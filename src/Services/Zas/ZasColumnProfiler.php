<?php

namespace Platform\Recruiting\Services\Zas;

/**
 * Spaltenuebersicht einer ZAS-Lieferung: Fuellgrad + Beispielwerte je Spalte,
 * gerechnet ueber die GANZE Datei (die Detailtabelle der Dispo-Sichtung capt
 * bei 200 Zeilen, die Uebersicht bewusst nicht — sie ist das Analyse-Werkzeug).
 *
 * Dient beiden Eingangsrichtungen: der Dispo-Sichtung im Backend und dem
 * Spalten-Bericht der Mitarbeiter-Lieferungen (ZasInboundColumnReport). Hiess
 * bis 2026-09-02 DispoColumnProfiler; die Logik war nie dispo-spezifisch.
 */
class ZasColumnProfiler
{
    /**
     * @param list<string> $columns
     * @param list<array<string, string>> $rows
     * @return list<array{column: string, filled: int, fill_ratio: float, examples: list<string>}>
     */
    public function profile(array $columns, array $rows, int $maxExamples = 3): array
    {
        $total = count($rows);
        $out = [];

        foreach ($columns as $column) {
            $filled = 0;
            $examples = [];

            foreach ($rows as $row) {
                $value = trim((string) ($row[$column] ?? ''));
                if ($value === '') {
                    continue;
                }
                $filled++;
                if (count($examples) < $maxExamples && !in_array($value, $examples, true)) {
                    $examples[] = $value;
                }
            }

            $out[] = [
                'column'     => $column,
                'filled'     => $filled,
                'fill_ratio' => $total === 0 ? 0.0 : round($filled / $total, 3),
                'examples'   => $examples,
            ];
        }

        return $out;
    }
}
