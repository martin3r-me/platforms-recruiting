<?php

namespace Platform\Recruiting\Services\Zas\Dispo\Jobs;

/**
 * Einsaetze im Kalendermonat je Personengruppe und die Gruppen-Baender des
 * Kunden (PDF „Mitarbeitergruppen“): 0 / weniger als 5 / weniger als 10 /
 * weniger als 20 / alle. Die Baender sind DISJUNKT, damit „naechste Gruppe“ in
 * Welle 2 nicht dieselben Leute trifft wie Welle 1.
 */
final class DispoEinsatzZaehler
{
    /** Schluessel => Beschriftung (Kundenwort zuerst, Band in Klammern). */
    public const BAENDER = [
        '0'     => '0 Einsätze',
        '1-4'   => 'weniger als 5 (1–4)',
        '5-9'   => 'weniger als 10 (5–9)',
        '10-19' => 'weniger als 20 (10–19)',
        '20+'   => '20 und mehr',
        'alle'  => 'alle verfügbaren Mitarbeiter',
    ];

    /**
     * @param list<array<string,mixed>> $assignments wie DispoTagesPruefung
     * @param array<int,int> $anchorByEmployee
     * @return array<int,int> Anker => Anzahl (nur Anker mit mindestens einem Einsatz)
     */
    public static function proMonat(array $assignments, array $anchorByEmployee, string $monat): array
    {
        $out = [];
        foreach ($assignments as $a) {
            if (!DispoTagesPruefung::istEinsatz($a)) {
                continue;
            }
            if (substr((string) $a['datum'], 0, 7) !== $monat) {
                continue;
            }
            $emp = (int) $a['employee_id'];
            $anchor = $anchorByEmployee[$emp] ?? $emp;
            $out[$anchor] = ($out[$anchor] ?? 0) + 1;
        }
        ksort($out);

        return $out;
    }

    /** Reihenfolge der Welle (Spec 2026-10-30 §2): so viele Tage zurueck zaehlen. */
    public const TAGE_ZURUECK = 30;

    /**
     * Zaehlfenster fuer die Reihenfolge: heute minus TAGE_ZURUECK bis zum letzten
     * angebotenen Tag, mindestens heute.
     *
     * @param list<string> $tage Y-m-d
     * @return array{von: string, bis: string}
     */
    public static function zaehlfenster(string $heute, array $tage): array
    {
        $heute = substr($heute, 0, 10);
        $tage = array_values(array_filter(array_map(fn ($t) => substr((string) $t, 0, 10), $tage), fn ($t) => $t !== ''));

        return [
            'von' => \Carbon\CarbonImmutable::parse($heute)->subDays(self::TAGE_ZURUECK)->toDateString(),
            'bis' => $tage === [] ? $heute : max($heute, max($tage)),
        ];
    }

    /**
     * Einsaetze je Person im Zeitraum (beide Grenzen einschliesslich), gleiche Zeilenregel wie proMonat.
     *
     * @param list<array<string,mixed>> $assignments
     * @param array<int,int> $anchorByEmployee
     * @return array<int,int> Anker => Anzahl (nur Anker mit mindestens einem Einsatz)
     */
    public static function imZeitraum(array $assignments, array $anchorByEmployee, string $von, string $bis): array
    {
        $out = [];
        foreach ($assignments as $a) {
            if (!DispoTagesPruefung::istEinsatz($a)) {
                continue;
            }
            $tag = substr((string) $a['datum'], 0, 10);
            if ($tag < $von || $tag > $bis) {
                continue;
            }
            $emp = (int) $a['employee_id'];
            $anchor = $anchorByEmployee[$emp] ?? $emp;
            $out[$anchor] = ($out[$anchor] ?? 0) + 1;
        }
        ksort($out);

        return $out;
    }

    public static function band(int $anzahl): string
    {
        return match (true) {
            $anzahl <= 0 => '0',
            $anzahl <= 4 => '1-4',
            $anzahl <= 9 => '5-9',
            $anzahl <= 19 => '10-19',
            default => '20+',
        };
    }

    /** @param list<string> $gruppen leer = niemand; 'alle' = jeder */
    public static function trifft(int $anzahl, array $gruppen): bool
    {
        if ($gruppen === []) {
            return false;
        }
        if (in_array('alle', $gruppen, true)) {
            return true;
        }

        return in_array(self::band($anzahl), $gruppen, true);
    }
}
