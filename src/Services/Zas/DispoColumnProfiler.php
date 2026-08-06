<?php

namespace Platform\Recruiting\Services\Zas;

/**
 * Spaltenuebersicht fuer die Dispo-Sichtung: Fuellgrad + Beispielwerte je
 * Spalte, gerechnet ueber die GANZE Datei (die Detailtabelle capt bei 200
 * Zeilen, die Uebersicht bewusst nicht — sie ist das Analyse-Werkzeug).
 */
class DispoColumnProfiler
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
