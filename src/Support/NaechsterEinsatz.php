<?php

namespace Platform\Recruiting\Support;

use Carbon\Carbon;

/**
 * Kachel „Nächster Einsatz“ auf der Portal-Startseite (29.10.2026): bewusst
 * kurz — wann, wo, Zeit, Status. Details und Bestaetigen auf der
 * Einsatz-Seite (Reiter „Einsätze“). Eingabe sind genau die Einsaetze, die
 * die Einsatz-Seite zeigt (EmployeeAssignments::sichtbareEinsaetze), damit
 * Kachel und Seite nie Verschiedenes sagen. Pure.
 */
final class NaechsterEinsatz
{
    private const WOCHENTAG = ['So', 'Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa'];

    /**
     * @param iterable<object> $einsaetze sortiert nach Datum/Beginn; je mit datum (Carbon), von, bis, confirmed_at, reconfirm_required_at, event
     * @return ?array{tag:string, zeit:string, name:string, status:string, weitere:int, unbestaetigt:int}
     *         status: bestaetigt | teilweise (erster bestaetigt, spaetere offen) | offen | geaendert (Zeit geaendert, neu bestaetigen)
     *         weitere: weitere EINSAETZE (Veranstaltungen), nicht Tage — wie die Einsatz-Seite gruppiert
     */
    public static function aus(iterable $einsaetze, Carbon $jetzt): ?array
    {
        $liste = is_array($einsaetze) ? array_values($einsaetze) : array_values(iterator_to_array($einsaetze, false));
        if ($liste === []) {
            return null;
        }

        $erster = $liste[0];
        $unbestaetigt = 0;
        foreach ($liste as $e) {
            if ($e->confirmed_at === null || $e->reconfirm_required_at !== null) {
                $unbestaetigt++;
            }
        }

        $datum = Carbon::parse($erster->datum)->startOfDay();
        $heute = $jetzt->copy()->startOfDay();
        $tag = match (true) {
            $datum->equalTo($heute)              => 'Heute',
            $datum->equalTo($heute->copy()->addDay()) => 'Morgen',
            default                              => self::WOCHENTAG[$datum->dayOfWeek] . ' ' . $datum->format('d.m.'),
        };

        $status = match (true) {
            $erster->reconfirm_required_at !== null => 'geaendert',
            $erster->confirmed_at !== null && $unbestaetigt > 0 => 'teilweise',
            $erster->confirmed_at !== null          => 'bestaetigt',
            default                                 => 'offen',
        };

        $events = [];
        foreach ($liste as $e) {
            $events[(string) ($e->rec_dispo_event_id ?? spl_object_id($e))] = true;
        }

        $bis = trim((string) ($erster->bis ?? ''));

        return [
            'tag'          => $tag,
            'zeit'         => trim((string) $erster->von) . ($bis !== '' ? '–' . $bis : ''),
            'name'         => (string) ($erster->event->name ?? $erster->event->einsatz_ref ?? ''),
            'status'       => $status,
            'weitere'      => count($events) - 1,
            'unbestaetigt' => $unbestaetigt,
        ];
    }
}
