<?php

namespace Platform\Recruiting\Services\Zas\Dispo;

/**
 * Reine Aufloesung + Validierung der Eskalations-Konfiguration pro VA (Runde 3, #5).
 * Kein DB-/Config-Zugriff. Der Stufen-Planner (DispoEscalationPlanner) bleibt
 * unveraendert und bekommt nur die hier aufgeloesten Zeiten.
 *
 * Drei Modi fuer $day: vortag (Standard), einsatztag, und (Runde 4, #4) datum —
 * ein frei gewaehlter Tag, an dem ALLE kommenden Einsatztage der VA eskalieren.
 */
final class DispoEscalationConfig
{
    public const DAY_VORTAG = 'vortag';
    public const DAY_EINSATZTAG = 'einsatztag';
    /** Runde 4 (#4): frei gewaehltes Datum — an diesem Tag eskalieren ALLE kommenden Einsatztage der VA. */
    public const DAY_DATUM = 'datum';

    /**
     * @param array{1:string,2:string,3:string} $defaults Team-Default HH:MM
     * @param ?string $date Eskalationsdatum Y-m-d (nur im Modus datum relevant)
     * @return array{day:string, times:array{1:string,2:string,3:string}, date:?string, overridden:bool}
     */
    public static function effective(?string $day, ?string $t1, ?string $t2, ?string $t3, array $defaults, ?string $date = null): array
    {
        $date = self::isDate($date) ? $date : null;
        $day = match (true) {
            $day === self::DAY_EINSATZTAG                 => self::DAY_EINSATZTAG,
            $day === self::DAY_DATUM && $date !== null    => self::DAY_DATUM,
            default                                       => self::DAY_VORTAG,
        };
        if ($day !== self::DAY_DATUM) {
            $date = null;
        }
        $hasTimes = self::isTime($t1) && self::isTime($t2) && self::isTime($t3);
        $times = $hasTimes ? [1 => (string) $t1, 2 => (string) $t2, 3 => (string) $t3] : $defaults;

        return ['day' => $day, 'times' => $times, 'date' => $date, 'overridden' => $hasTimes || $day !== self::DAY_VORTAG];
    }

    /**
     * Wird eine Einbuchung mit Einsatzdatum $datum an $runDay eskaliert? (alle Y-m-d)
     * datum-Modus: nur am gewaehlten Datum, fuer jeden Einsatztag >= Lauftag.
     */
    public static function appliesOn(string $day, string $datum, string $runDay, ?string $escalationDate = null): bool
    {
        if ($day === self::DAY_DATUM) {
            return $escalationDate !== null && $escalationDate === $runDay && $datum >= $runDay;
        }
        if ($day === self::DAY_EINSATZTAG) {
            return $datum === $runDay;
        }

        return $datum === (new \DateTimeImmutable($runDay))->modify('+1 day')->format('Y-m-d');
    }

    public static function isTime(?string $value): bool
    {
        return $value !== null && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $value) === 1;
    }

    public static function isDate(?string $value): bool
    {
        if ($value === null || preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m) !== 1) {
            return false;
        }

        return checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
    }

    /**
     * Eingabe-Validierung (Sende-Modal / Anpassen-Dialog). Leere Zeiten = Standard.
     *
     * @param ?string $earliestVon fruehester Schichtbeginn HH:MM der betroffenen Tage, null = unbekannt
     * @param array{1:string,2:string,3:string} $defaults
     * @param string  $date        Eskalationsdatum Y-m-d (Modus datum), '' = nicht gesetzt
     * @param ?string $today       Lauf-/Heute-Datum Y-m-d fuer die Vergangenheitspruefung, null = nicht pruefen
     * @param ?string $firstDatum  erster kommender Einsatztag Y-m-d, null = unbekannt
     * @return list<string> Fehlermeldungen (leer = gueltig)
     */
    public static function validate(string $day, string $t1, string $t2, string $t3, ?string $earliestVon, array $defaults, string $date = '', ?string $today = null, ?string $firstDatum = null): array
    {
        $errors = [];
        if (!in_array($day, [self::DAY_VORTAG, self::DAY_EINSATZTAG, self::DAY_DATUM], true)) {
            $errors[] = 'Ungültiger Eskalationstag.';
        }
        if ($day === self::DAY_DATUM) {
            if (!self::isDate($date)) {
                $errors[] = 'Bitte ein Datum für die Eskalation wählen.';
            } elseif ($today !== null && $date < $today) {
                $errors[] = 'Das Eskalationsdatum darf nicht in der Vergangenheit liegen.';
            } elseif ($firstDatum !== null && $date > $firstDatum) {
                $errors[] = 'Das Eskalationsdatum muss vor oder auf dem ersten Einsatztag (' . (new \DateTimeImmutable($firstDatum))->format('d.m.Y') . ') liegen.';
            }
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

        // Einsatztag-Regel gilt auch, wenn das gewaehlte Datum AUF dem ersten Einsatztag liegt.
        $onShiftDay = $day === self::DAY_EINSATZTAG
            || ($day === self::DAY_DATUM && $firstDatum !== null && $date === $firstDatum);
        if ($onShiftDay && self::isTime($earliestVon)) {
            $eff = self::effective($day, $t1 ?: null, $t2 ?: null, $t3 ?: null, $defaults, $date !== '' ? $date : null);
            if (!($eff['times'][3] < $earliestVon)) {
                $errors[] = "Am Einsatztag müssen alle Stufen vor dem frühesten Schichtbeginn ({$earliestVon}) liegen.";
            }
        }

        return $errors;
    }
}
