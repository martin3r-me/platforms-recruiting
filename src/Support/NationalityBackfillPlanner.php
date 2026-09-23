<?php

namespace Platform\Recruiting\Support;

/**
 * Entscheidet je Mitarbeiterzeile, wie die Staatsangehoerigkeit nachgetragen
 * wird — Stufe A des Nation-Fixes (23.09.2026).
 *
 * Zwei Herkuenfte, zwei Regeln:
 *  - Funnel-MA: Wert aus dem Bewerber-Feld `nationalitaet`. Das Geburtsland
 *    ist dort echt (vom Bewerber angegeben) und bleibt.
 *  - Import-MA (rec_zas_inbound_file_id gesetzt): ZAS liefert kein
 *    Geburtsland, in `birth_country` steht ZAS' `Nation` — also die
 *    Staatsangehoerigkeit. Sie wandert um, das Geburtsland wird geleert,
 *    weil es dort nie eines gab. Kennt der Bewerber den Wert, gewinnt er.
 *
 * Nie ueberschreiben: eine gesetzte Staatsangehoerigkeit bleibt.
 *
 * Reine Logik (kein Framework/DB) → pure-unit-testbar.
 */
final class NationalityBackfillPlanner
{
    /**
     * @param array{nationality:?string, birth_country:?string, rec_zas_inbound_file_id:mixed, applicant_nationality:?string} $row
     * @return array<string, ?string>|null  Spalten, die geschrieben werden — null = nichts tun
     */
    public static function plan(array $row): ?array
    {
        if (self::filled($row['nationality'] ?? null)) {
            return null;
        }

        $fromApplicant = self::filled($row['applicant_nationality'] ?? null) ? $row['applicant_nationality'] : null;
        $fromImport    = !empty($row['rec_zas_inbound_file_id']);
        $birthCountry  = self::filled($row['birth_country'] ?? null) ? $row['birth_country'] : null;

        if ($fromApplicant !== null) {
            $plan = ['nationality' => $fromApplicant];
            if ($fromImport && $birthCountry !== null) {
                $plan['birth_country'] = null;
            }
            return $plan;
        }

        if ($fromImport && $birthCountry !== null) {
            return ['nationality' => $birthCountry, 'birth_country' => null];
        }

        return null;
    }

    private static function filled(mixed $v): bool
    {
        return $v !== null && trim((string) $v) !== '';
    }
}
