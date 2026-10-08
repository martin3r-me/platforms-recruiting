<?php

namespace Platform\Recruiting\Services\Zas\Dispo;

use Illuminate\Support\Carbon;
use Platform\Recruiting\Models\RecDispoAssignment;
use Platform\Recruiting\Models\RecDispoDeclineCheck;

/**
 * Offene Absage-Meldungen in der VA (Spec 2026-10-08, Entscheidungen 10/11).
 *
 * Eine Meldung ist nur so lange offen, wie mindestens einer ihrer Tage noch
 * im Rennen ist: kommend, nicht abgesagt, nicht verschwunden, nicht zur
 * Loeschung gemeldet und NICHT nach der Meldung bestaetigt. Ausgewertet beim
 * Lesen — kein Haken in den Bestaetigungswegen.
 *
 * Annehmen/Verwerfen schreibt nur die Meldung. Abgesagt wird ausschliesslich
 * ueber DispoDecline aus dem Absage-Fenster.
 */
class DispoDeclineReview
{
    /**
     * @param list<array{datum:string, confirmed_at:mixed, declined_at:mixed, missing_since:mixed, deletion_marked_at:mixed}> $days
     */
    public static function isStillOpen(\DateTimeInterface $checkedAt, array $days, string $today): bool
    {
        foreach ($days as $d) {
            if ($d['datum'] < $today || $d['declined_at'] !== null || $d['missing_since'] !== null || $d['deletion_marked_at'] !== null) {
                continue;
            }
            if ($d['confirmed_at'] !== null && Carbon::parse($d['confirmed_at'])->greaterThan($checkedAt)) {
                continue; // nach der Meldung bestaetigt — die Person kommt doch
            }

            return true;
        }

        return false;
    }

    /**
     * Offene Meldungen einer VA je Person (kanonisch), juengste zuerst; die
     * Tage sind auf die noch offenen reduziert.
     *
     * @return array<int, list<array{id:int, excerpt:string, reason:string, confidence:?string, assignment_ids:list<int>, at:string}>>
     */
    public function openByEvent(int $eventId): array
    {
        $out = [];
        foreach ($this->openChecks([$eventId]) as [$check, $ids]) {
            $out[(int) $check->rec_employee_id][] = [
                'id'             => (int) $check->id,
                'excerpt'        => (string) $check->excerpt,
                'reason'         => (string) $check->reason,
                'confidence'     => $check->confidence,
                'assignment_ids' => $ids,
                'at'             => $check->created_at->format('d.m. H:i'),
            ];
        }

        return $out;
    }

    /**
     * @param list<int> $eventIds
     * @return array<int,int> event_id => Anzahl Personen mit offener Meldung
     */
    public function openCountsByEvent(array $eventIds): array
    {
        $persons = [];
        foreach ($this->openChecks($eventIds) as [$check]) {
            $persons[(int) $check->rec_dispo_event_id][(int) $check->rec_employee_id] = true;
        }

        return array_map('count', $persons);
    }

    /** "Keine Absage": alle offenen Meldungen der Person in dieser VA verwerfen. */
    public function dismissForPerson(int $eventId, int $employeeId, ?int $userId): int
    {
        return $this->resolve($eventId, $employeeId, RecDispoDeclineCheck::REVIEW_DISMISSED, $userId);
    }

    /** Nach gespeicherter Absage: offene Meldungen der Person gelten als uebernommen. */
    public function acceptForPerson(int $eventId, int $employeeId, ?int $userId): int
    {
        return $this->resolve($eventId, $employeeId, RecDispoDeclineCheck::REVIEW_ACCEPTED, $userId);
    }

    private function resolve(int $eventId, int $employeeId, string $status, ?int $userId): int
    {
        return RecDispoDeclineCheck::query()
            ->where('rec_dispo_event_id', $eventId)
            ->where('rec_employee_id', $employeeId)
            ->where('review_status', RecDispoDeclineCheck::REVIEW_OPEN)
            ->update(['review_status' => $status, 'reviewed_by_user_id' => $userId, 'reviewed_at' => now()]);
    }

    /**
     * @param list<int> $eventIds
     * @return list<array{0: RecDispoDeclineCheck, 1: list<int>}> Meldung + ihre noch offenen Tage
     */
    private function openChecks(array $eventIds): array
    {
        if ($eventIds === []) {
            return [];
        }
        $checks = RecDispoDeclineCheck::query()
            ->whereIn('rec_dispo_event_id', $eventIds)
            ->where('review_status', RecDispoDeclineCheck::REVIEW_OPEN)
            ->orderByDesc('id')
            ->get();
        if ($checks->isEmpty()) {
            return [];
        }

        $rows = RecDispoAssignment::query()
            ->whereIn('id', $checks->flatMap(fn ($c) => (array) $c->assignment_ids)->unique()->values()->all())
            ->get(['id', 'datum', 'confirmed_at', 'declined_at', 'missing_since', 'deletion_marked_at'])
            ->keyBy('id');
        $today = now()->toDateString();

        $out = [];
        foreach ($checks as $check) {
            $open = [];
            foreach ((array) $check->assignment_ids as $id) {
                $row = $rows[(int) $id] ?? null;
                if ($row !== null && self::isStillOpen($check->created_at, [self::state($row)], $today)) {
                    $open[] = (int) $id;
                }
            }
            if ($open !== []) {
                $out[] = [$check, $open];
            }
        }

        return $out;
    }

    private static function state(RecDispoAssignment $row): array
    {
        return [
            'datum'              => $row->datum->format('Y-m-d'),
            'confirmed_at'       => $row->confirmed_at,
            'declined_at'        => $row->declined_at,
            'missing_since'      => $row->missing_since,
            'deletion_marked_at' => $row->deletion_marked_at,
        ];
    }
}
