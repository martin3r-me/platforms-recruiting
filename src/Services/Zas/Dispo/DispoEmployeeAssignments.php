<?php

namespace Platform\Recruiting\Services\Zas\Dispo;

use Platform\Recruiting\Models\RecDispoAssignment;

/**
 * Einsaetze einer Person fuer die Mitarbeiter-Akte (Kunde 26.09.: „Kann ich die
 * disponierten Einsaetze im Mitarbeiter sehen?").
 *
 * Rein aufbereitend, kein DB-Zugriff: die Einbuchungen reicht der Aufrufer
 * herein (er kennt auch die Personen-Gruppe RG/MA). Nur Anzeige — bestaetigt,
 * abgesagt und angeschrieben wird weiterhin in der Veranstaltung.
 */
final class DispoEmployeeAssignments
{
    /**
     * Kommende und vergangene Einsaetze getrennt; vergangene gedeckelt, weil
     * bei jemandem seit 2022 sonst hunderte Zeilen in der Akte stehen.
     *
     * @param iterable<RecDispoAssignment> $assignments
     * @return array{upcoming: list<array<string,mixed>>, past: list<array<string,mixed>>, past_total: int, total: int}
     */
    public static function split(iterable $assignments, string $today, int $pastLimit = 10): array
    {
        $upcoming = [];
        $past = [];
        $total = 0;

        foreach ($assignments as $a) {
            $total++;
            if ($a->datum === null) {
                continue;
            }
            if ($a->datum->format('Y-m-d') >= $today) {
                $upcoming[] = self::row($a);
            } else {
                $past[] = self::row($a);
            }
        }

        $pastTotal = count($past);

        return [
            'upcoming'   => $upcoming,
            // Juengste zuerst — die Akte interessiert „zuletzt im Einsatz".
            'past'       => array_slice(array_reverse($past), 0, max(0, $pastLimit)),
            'past_total' => $pastTotal,
            'total'      => $total,
        ];
    }

    /** Eine Zeile — gleiche Begriffe wie auf der VA-Seite, kein neues Vokabular. */
    public static function row(RecDispoAssignment $a): array
    {
        [$status, $statusLabel] = match (true) {
            $a->missing_since !== null      => ['gone', 'verschwunden'],
            $a->deletion_marked_at !== null => ['gone', 'zur Löschung gemeldet'],
            $a->declined_at !== null        => ['declined', 'abgesagt'],
            $a->confirmed_at !== null       => ['confirmed', 'bestätigt'],
            $a->reminder_sent_at !== null   => ['sent', 'angeschrieben'],
            default                         => ['open', 'offen'],
        };

        $event = $a->relationLoaded('event') ? $a->getRelation('event') : null;
        $wd = ['So', 'Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa'][$a->datum->dayOfWeek];

        return [
            'id'           => (int) $a->id,
            'event_id'     => $a->rec_dispo_event_id !== null ? (int) $a->rec_dispo_event_id : null,
            'event_name'   => (string) ($event->name ?? $event->einsatz_ref ?? '—'),
            'einsatz_ref'  => (string) ($event->einsatz_ref ?? ''),
            'filiale'      => (string) ($event->filiale ?? ''),
            'datum'        => $wd . ' ' . $a->datum->format('d.m.Y'),
            'zeit'         => $a->von ? ($a->von . ($a->bis ? '–' . $a->bis : '')) : '—',
            'taetigkeit'   => (string) ($a->taetigkeit ?? ''),
            'status'       => $status,
            'status_label' => $statusLabel,
        ];
    }
}
