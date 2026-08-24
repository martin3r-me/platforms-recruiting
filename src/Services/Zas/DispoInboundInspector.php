<?php

namespace Platform\Recruiting\Services\Zas;

/**
 * Best-Effort-Strukturerkennung fuer Dispo-Inbound-Payloads (Phase 1 Sichtung).
 *
 * Bewusst von ZasInboundController::inspect() dupliziert statt dort extrahiert:
 * der Mitarbeiter-Inbound ist live und bleibt unangetastet. Zusammenfuehrung
 * beim Umzug ins Staffing-Modul (siehe Spec, Zielbild).
 *
 * Erwartet UTF-8-Input — Aufrufer normalisiert vorher via
 * CsvEncodingNormalizer::toUtf8(). Darf nie werfen (Sichtung ist Best-Effort).
 */
class DispoInboundInspector
{
    private const DELIMITERS = [';', ',', "\t", '|'];

    /** Block-Marker einer ZAS-Webexport-Zeile, z. B. {Personal}, {Dispo2} — keine Quotes/Doppelpunkte, sonst waere es JSON. */
    private const BLOCK_MARKER_PATTERN = '/^\{[A-Za-z0-9ÄÖÜäöüß_ \-]+\}$/u';

    /**
     * Grobformat anhand des Inhalts: 'blocks' | 'csv' | 'json' | 'unknown'.
     * Heuristik: ein Block-Marker (z. B. {Personal}) als erste Zeile gewinnt
     * VOR der JSON-Erkennung (der ZAS-Webexport beginnt so, waere sonst ein
     * Fehlversuch als JSON und faelschlich 'unknown'); sonst gewinnt valides
     * JSON; sonst gilt eine Header-Zeile mit bekanntem Trennzeichen als CSV;
     * alles andere ist unknown (Roh-Ansicht).
     */
    public function detectFormat(string $content): string
    {
        $trimmed = trim($content);
        if ($trimmed === '') {
            return 'unknown';
        }

        $firstLine = (string) (preg_split('/\r\n|\r|\n/', $trimmed)[0] ?? '');
        if (preg_match(self::BLOCK_MARKER_PATTERN, trim($firstLine)) === 1) {
            return 'blocks';
        }

        if (str_starts_with($trimmed, '{') || str_starts_with($trimmed, '[')) {
            json_decode($trimmed);
            return json_last_error() === JSON_ERROR_NONE ? 'json' : 'unknown';
        }

        $firstLine = (string) (preg_split('/\r\n|\r|\n/', $trimmed)[0] ?? '');
        foreach (self::DELIMITERS as $delimiter) {
            if (substr_count($firstLine, $delimiter) > 0) {
                return 'csv';
            }
        }

        return 'unknown';
    }

    /**
     * Parst CSV-Inhalt tolerant: Trennzeichen-Erkennung, Header→Wert-Maps,
     * Laengenunterschiede werden aufgefuellt (keine strikte Validierung).
     *
     * @return array{delimiter: ?string, columns: list<string>, extra_columns: list<string>, row_count: int, rows: list<array<string, string>>}
     */
    public function inspectCsv(string $utf8Content): array
    {
        $lines = preg_split('/\r\n|\r|\n/', $utf8Content) ?: [];
        $lines = array_values(array_filter($lines, fn ($l) => trim($l) !== ''));

        if ($lines === []) {
            return ['delimiter' => null, 'columns' => [], 'extra_columns' => [], 'row_count' => 0, 'rows' => []];
        }

        $delimiter = $this->detectDelimiter($lines[0]);
        $columns   = array_map('trim', str_getcsv($lines[0], $delimiter, '"', ''));

        $rows = [];
        foreach (array_slice($lines, 1) as $line) {
            $values = array_map('trim', str_getcsv($line, $delimiter, '"', ''));
            $rows[] = $this->zip($columns, $values);
        }

        $extras = [];
        foreach ($rows as $row) {
            foreach (array_keys($row) as $key) {
                if (!in_array($key, $columns, true) && !in_array($key, $extras, true)) {
                    $extras[] = $key;
                }
            }
        }

        return [
            'delimiter' => $delimiter,
            'columns'   => $columns,
            'extra_columns' => $extras,
            'row_count' => count($rows),
            'rows'      => $rows,
        ];
    }

    /** Wahrscheinlichstes Trennzeichen der Header-Zeile (Default Semikolon). */
    protected function detectDelimiter(string $line): string
    {
        $counts = [];
        foreach (self::DELIMITERS as $delimiter) {
            $counts[$delimiter] = substr_count($line, $delimiter);
        }
        arsort($counts);
        $best = array_key_first($counts);

        return $counts[$best] > 0 ? $best : ';';
    }

    /**
     * Header-Spalten + Werte zu einer Map; Ueberhang bekommt col_N-Keys.
     *
     * @param list<string> $columns
     * @param list<string> $values
     * @return array<string, string>
     */
    protected function zip(array $columns, array $values): array
    {
        $out = [];
        $count = max(count($columns), count($values));
        for ($i = 0; $i < $count; $i++) {
            $key = ($columns[$i] ?? '') !== '' ? $columns[$i] : ('col_' . $i);
            $out[$key] = $values[$i] ?? '';
        }

        return $out;
    }
}
