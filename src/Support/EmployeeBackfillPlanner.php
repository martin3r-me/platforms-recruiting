<?php

namespace Platform\Recruiting\Support;

/**
 * Entscheidet beim Backfill, welche Kandidaten-Werte tatsaechlich auf den
 * RecEmployee geschrieben werden duerfen: NUR Spalten, die aktuell leer
 * sind (null, '' oder []). Vorhandene Werte — insbesondere manuelle
 * Nachpflege aus MA-Portal/HR — werden nie ueberschrieben; false zaehlt
 * als echter Wert.
 *
 * Reine Logik (kein Framework/DB) → pure-unit-testbar.
 */
class EmployeeBackfillPlanner
{
    /**
     * @param array<string, mixed> $candidates  Spalte => neuer Wert (aus ApplicantEmployeeFieldMapping)
     * @param array<string, mixed> $current     Spalte => aktueller (gecasteter) MA-Wert
     * @return array<string, mixed>  Spalten, die geschrieben werden sollen
     */
    public static function plan(array $candidates, array $current): array
    {
        $out = [];
        foreach ($candidates as $column => $value) {
            if ($value === null) {
                continue;
            }
            $existing = $current[$column] ?? null;
            if ($existing === null || $existing === '' || $existing === []) {
                $out[$column] = $value;
            }
        }
        return $out;
    }
}
