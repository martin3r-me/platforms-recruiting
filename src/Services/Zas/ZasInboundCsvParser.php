<?php

namespace Platform\Recruiting\Services\Zas;

/**
 * Best-Effort-Parser fuer die von ZAS gelieferte Mitarbeiter-CSV.
 *
 * Lag bis 2026-08-25 als geschuetzte Methoden im ZasInboundController und war
 * damit nur ueber HTTP erreichbar. Der Reprocess-Command braucht genau dieselbe
 * Interpretation einer gespeicherten Rohdatei — sonst wuerde ein Nachlauf die
 * Zeilen anders schneiden als der Erstlauf. Deshalb ein gemeinsamer Dienst.
 *
 * Darf NIE werfen: der Empfang einer Lieferung soll nicht an einer Eigenheit
 * der Datei scheitern. Zwei Ausgaben sind VERTRAG, weil die Zeilen-Guards des
 * Importers darauf aufbauen:
 *
 *  - Mehr Werte als Kopfspalten => die Ueberzaehligen bekommen col_N-Keys.
 *    Daran erkennt der Importer einen Spaltenversatz (Semikolon im Feldwert).
 *  - Das ZAS-Zeilenende `;|;` erzeugt eine Spalte '|', deren Wert in jeder
 *    intakten Zeile '|' ist. Alles andere heisst Versatz oder Zeile zu kurz.
 */
class ZasInboundCsvParser
{
    /**
     * @return array{
     *     delimiter: ?string,
     *     columns: array<int, string>,
     *     row_count: int,
     *     first_data_row: ?array<string, string>,
     *     rows: list<array<string, string>>
     * }
     */
    public function parse(string $content): array
    {
        // UTF-8-BOM strippen (ZAS-Exporte tragen sie; Eingang vermutlich auch).
        $clean = preg_replace('/^\xEF\xBB\xBF/', '', $content) ?? $content;

        $lines = preg_split('/\r\n|\r|\n/', $clean) ?: [];
        $lines = array_values(array_filter($lines, fn ($l) => trim($l) !== ''));

        if ($lines === []) {
            return ['delimiter' => null, 'columns' => [], 'row_count' => 0, 'first_data_row' => null, 'rows' => []];
        }

        $headerLine = $lines[0];
        $delimiter  = $this->detectDelimiter($headerLine);
        $columns    = array_map('trim', str_getcsv($headerLine, $delimiter, '"', ''));

        $rows = [];
        foreach (array_slice($lines, 1) as $line) {
            $values = array_map('trim', str_getcsv($line, $delimiter, '"', ''));
            $rows[] = $this->zip($columns, $values);
        }

        return [
            'delimiter'      => $delimiter,
            'columns'        => $columns,
            'row_count'      => count($rows),
            'first_data_row' => $rows[0] ?? null,
            'rows'           => $rows,
        ];
    }

    /**
     * Ermittelt das wahrscheinlichste Trennzeichen anhand der Kopfzeile.
     * ZAS-Exporte nutzen Semikolon; der Eingang war anfangs ungewiss.
     */
    protected function detectDelimiter(string $line): string
    {
        $candidates = [';' => 0, ',' => 0, "\t" => 0, '|' => 0];
        foreach (array_keys($candidates) as $char) {
            $candidates[$char] = substr_count($line, $char);
        }
        arsort($candidates);
        $best = array_key_first($candidates);

        return $candidates[$best] > 0 ? $best : ';';
    }

    /**
     * Verbindet Kopfspalten mit Werten. Laengenunterschiede werden tolerant
     * aufgefuellt — die Bewertung uebernimmt der Importer, nicht der Parser.
     *
     * @param  array<int, string> $columns
     * @param  array<int, string> $values
     * @return array<string, string>
     */
    protected function zip(array $columns, array $values): array
    {
        $out   = [];
        $count = max(count($columns), count($values));
        for ($i = 0; $i < $count; $i++) {
            $key       = $columns[$i] ?? ('col_' . $i);
            $out[$key] = $values[$i] ?? '';
        }

        return $out;
    }
}
