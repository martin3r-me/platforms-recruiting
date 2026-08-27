<?php

namespace Platform\Recruiting\Services\Zas\Dispo;

/**
 * Reine Aufloesung + Validierung der Eskalations-Konfiguration pro VA (Runde 3, #5).
 * Kein DB-/Config-Zugriff. Der Stufen-Planner (DispoEscalationPlanner) bleibt
 * unveraendert und bekommt nur die hier aufgeloesten Zeiten.
 */
final class DispoEscalationConfig
{
    public const DAY_VORTAG = 'vortag';
    public const DAY_EINSATZTAG = 'einsatztag';

    /**
     * @param array{1:string,2:string,3:string} $defaults Team-Default HH:MM
     * @return array{day:string, times:array{1:string,2:string,3:string}, overridden:bool}
     */
    public static function effective(?string $day, ?string $t1, ?string $t2, ?string $t3, array $defaults): array
    {
        $day = $day === self::DAY_EINSATZTAG ? self::DAY_EINSATZTAG : self::DAY_VORTAG;
        $hasTimes = self::isTime($t1) && self::isTime($t2) && self::isTime($t3);
        $times = $hasTimes ? [1 => (string) $t1, 2 => (string) $t2, 3 => (string) $t3] : $defaults;

        return ['day' => $day, 'times' => $times, 'overridden' => $hasTimes || $day === self::DAY_EINSATZTAG];
    }

    /** Wird eine Einbuchung mit Einsatzdatum $datum an $runDay eskaliert? (beide Y-m-d) */
    public static function appliesOn(string $day, string $datum, string $runDay): bool
    {
        if ($day === self::DAY_EINSATZTAG) {
            return $datum === $runDay;
        }

        return $datum === (new \DateTimeImmutable($runDay))->modify('+1 day')->format('Y-m-d');
    }

    public static function isTime(?string $value): bool
    {
        return $value !== null && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $value) === 1;
    }

    /**
     * Eingabe-Validierung (Sende-Modal / Anpassen-Dialog). Leere Zeiten = Standard.
     *
     * @param ?string $earliestVon fruehester Schichtbeginn HH:MM der betroffenen Tage, null = unbekannt
     * @param array{1:string,2:string,3:string} $defaults
     * @return list<string> Fehlermeldungen (leer = gueltig)
     */
    public static function validate(string $day, string $t1, string $t2, string $t3, ?string $earliestVon, array $defaults): array
    {
        $errors = [];
        if (!in_array($day, [self::DAY_VORTAG, self::DAY_EINSATZTAG], true)) {
            $errors[] = 'Ungültiger Eskalationstag.';
        }

        $set = array_filter([$t1, $t2, $t3], fn ($v) => $v !== '');
        if ($set !== [] && count($set) !== 3) {
            $errors[] = 'Bitte alle drei Uhrzeiten setzen oder alle leer lassen (Standard).';
        }
        if (count($set) === 3) {
            if (!self::isTime($t1) || !self::isTime($t2) || !self::isTime($t3)) {
                $errors[] = 'Uhrzeiten bitte im Format HH:MM.';
            } elseif (!($t1 < $t2 && $t2 < $t3)) {
                $errors[] = 'Die Stufen müssen zeitlich aufsteigend sein (Stufe 1 < Stufe 2 < Stufe 3).';
            }
        }
        if ($errors !== []) {
            return $errors;
        }

        if ($day === self::DAY_EINSATZTAG && self::isTime($earliestVon)) {
            $eff = self::effective($day, $t1 ?: null, $t2 ?: null, $t3 ?: null, $defaults);
            if (!($eff['times'][3] < $earliestVon)) {
                $errors[] = "Am Einsatztag müssen alle Stufen vor dem frühesten Schichtbeginn ({$earliestVon}) liegen.";
            }
        }

        return $errors;
    }
}
