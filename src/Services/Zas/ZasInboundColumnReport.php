<?php

namespace Platform\Recruiting\Services\Zas;

/**
 * Fasst den Fuellgrad je ZAS-Spalte ueber beliebig viele Lieferungen zusammen.
 *
 * Beantwortet die Frage, die sich beim Bestands-Import immer wieder stellt:
 * "Liefert ZAS dieses Feld ueberhaupt — und lesen wir es?" (Anlass: die leeren
 * Dokument- und Qualifikationsfelder der 706 uebernommenen Mitarbeiter.)
 *
 * Zwei Entscheidungen, ohne die der Bericht in die Irre fuehren wuerde:
 *
 *  - Der Nenner ist SPALTENWEISE: gezaehlt werden nur die Zeilen der
 *    Lieferungen, die diese Spalte im Kopf hatten. Spalten, die ZAS erst
 *    spaeter dazugenommen hat (StatusMASeit, Ersthelfer), stuenden sonst als
 *    "12 von 820" da und saehen wie ein Lieferproblem aus.
 *  - "Leer" wird getrennt: ALWAYS_EMPTY heisst "Spalte kam, nie mit Wert" (bei
 *    ZAS nachfragen lohnt), MISSING heisst "Spalte kam nie" (braucht eine
 *    Formatabsprache). Verschiedene Konsequenzen, also verschiedene Zustaende.
 *
 * Reine Logik ohne DB/Storage — der Command liest die Dateien, dieser Dienst
 * rechnet nur.
 */
class ZasInboundColumnReport
{
    /** Spalte kam vor und trug mindestens einmal einen Wert. */
    public const STATUS_FILLED = 'filled';

    /** Spalte kam vor, war aber in jeder Zeile leer. */
    public const STATUS_ALWAYS_EMPTY = 'always_empty';

    /** Spalte kam in keiner Lieferung vor. */
    public const STATUS_MISSING = 'missing';

    public function __construct(
        private ZasColumnProfiler $profiler,
        private ZasInboundCsvParser $parser,
    ) {}

    /**
     * Wie build(), nur ab Rohdatei-Inhalt.
     *
     * Geparst wird mit demselben Dienst wie beim Import — sonst koennte der
     * Bericht Spalten anders schneiden als der Importer sie liest (BOM,
     * Trennzeichen-Erkennung, das `;|;`-Zeilenende) und damit eine Luecke
     * behaupten oder verstecken, die es nicht gibt.
     *
     * @param  list<string> $contents
     * @param  list<string> $knownColumns
     * @param  list<string> $expectedColumns
     * @return list<array{column: string, filled: int, rows: int, ratio: float, read: bool, status: string, examples: list<string>}>
     */
    public function fromContents(array $contents, array $knownColumns, array $expectedColumns, int $maxExamples = 0): array
    {
        $deliveries = [];

        foreach ($contents as $content) {
            $parsed       = $this->parser->parse($content);
            $deliveries[] = ['columns' => $parsed['columns'], 'rows' => $parsed['rows']];
        }

        return $this->build($deliveries, $knownColumns, $expectedColumns, $maxExamples);
    }

    /**
     * @param  list<array{columns: list<string>, rows: list<array<string, string>>}> $deliveries
     * @param  list<string> $knownColumns   Spalten, die der Import liest (ZasInboundRowMapper::knownColumns())
     * @param  list<string> $expectedColumns Spalten, die wir erwarten — daraus entsteht die MISSING-Liste
     * @param  int          $maxExamples     0 = keine Beispielwerte (Standard; sie enthalten Personendaten)
     * @return list<array{column: string, filled: int, rows: int, ratio: float, read: bool, status: string, examples: list<string>}>
     */
    public function build(array $deliveries, array $knownColumns, array $expectedColumns, int $maxExamples = 0): array
    {
        /** @var array<string, array{filled: int, rows: int, examples: list<string>}> $totals */
        $totals = [];
        $order  = [];

        foreach ($deliveries as $delivery) {
            $columns  = $delivery['columns'] ?? [];
            $rows     = $delivery['rows'] ?? [];
            $rowCount = count($rows);

            foreach ($this->profiler->profile($columns, $rows, $maxExamples) as $entry) {
                $column = $entry['column'];

                if (!array_key_exists($column, $totals)) {
                    // delivered=true: die Spalte stand im Kopf einer Lieferung.
                    // Das unterscheidet spaeter ALWAYS_EMPTY von MISSING.
                    $totals[$column] = ['filled' => 0, 'rows' => 0, 'examples' => [], 'delivered' => true];
                    $order[]         = $column;
                }

                $totals[$column]['filled'] += $entry['filled'];
                // Nenner nur um die Zeilen DIESER Lieferung erhoehen — eine
                // Lieferung ohne die Spalte darf sie nicht verwaessern.
                $totals[$column]['rows'] += $rowCount;

                foreach ($entry['examples'] as $example) {
                    if (count($totals[$column]['examples']) < $maxExamples
                        && !in_array($example, $totals[$column]['examples'], true)) {
                        $totals[$column]['examples'][] = $example;
                    }
                }
            }
        }

        // Erwartete, aber nie gelieferte Spalten hinten anhaengen.
        foreach ($expectedColumns as $column) {
            if (!array_key_exists($column, $totals)) {
                $totals[$column] = ['filled' => 0, 'rows' => 0, 'examples' => [], 'delivered' => false];
                $order[]         = $column;
            }
        }

        $known  = array_flip($knownColumns);
        $report = [];

        foreach ($order as $column) {
            $filled = $totals[$column]['filled'];
            $rows   = $totals[$column]['rows'];

            $report[] = [
                'column'   => $column,
                'filled'   => $filled,
                'rows'     => $rows,
                'ratio'    => $rows === 0 ? 0.0 : round($filled / $rows, 3),
                'read'     => array_key_exists($column, $known),
                'status'   => $this->status($filled, $totals[$column]['delivered']),
                'examples' => $totals[$column]['examples'],
            ];
        }

        return $report;
    }

    /**
     * Nur die Luecken: Spalten ohne einen einzigen Wert, egal ob sie kamen
     * oder fehlten. Das ist die Liste, mit der man zu ZAS geht.
     *
     * @param  list<array{status: string}> $report
     * @return list<array{status: string}>
     */
    public static function onlyEmpty(array $report): array
    {
        return array_values(array_filter(
            $report,
            fn (array $entry) => $entry['status'] !== self::STATUS_FILLED
        ));
    }

    /**
     * Ein Wert genuegt fuer FILLED. Ohne Wert entscheidet allein, ob die
     * Spalte je im Kopf einer Lieferung stand — daran haengt, ob man bei ZAS
     * nach der Pflege des Feldes fragt oder ueber das Format sprechen muss.
     */
    private function status(int $filled, bool $delivered): string
    {
        if ($filled > 0) {
            return self::STATUS_FILLED;
        }

        return $delivered ? self::STATUS_ALWAYS_EMPTY : self::STATUS_MISSING;
    }
}
