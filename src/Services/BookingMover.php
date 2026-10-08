<?php

namespace Platform\Recruiting\Services;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Platform\Recruiting\Jobs\NotifyWaitlistForInterview;
use Platform\Recruiting\Models\RecAutoPilotLog;
use Platform\Recruiting\Models\RecInterview;
use Platform\Recruiting\Models\RecInterviewBooking;

/**
 * Teilnehmer zwischen Schulungsterminen DERSELBEN Stelle verschieben
 * (05.10.2026, Anlass: 70 Buchungen in einem MGL-Termin, die auf
 * Logistik-/Catering-Termine verteilt werden).
 *
 * Die Buchungszeile wandert mit — kein Storno plus Neubuchung. Status,
 * Bestaetigung, Notizen und Einsatz-Klaerung bleiben erhalten. Bewusst still:
 * keine Nachricht an den Teilnehmer. Der Erinnerungsstempel bleibt stehen
 * (User-Entscheidung 05.10.): verschoben wird praktisch nur innerhalb einer
 * Schulung (Aufteilung in Gruppen), wer schon erinnert wurde, bekommt nichts
 * mehr. Wer noch nicht erinnert wurde, bekommt die regulaere Erinnerung des
 * neuen Termins.
 *
 * Ohne Model-Events gespeichert (saveQuietly), vor allem ohne den
 * ZAS-Export-Marker (RecApplicantExportObserver reagiert auf
 * rec_interview_id). SchulungsStandort/-Datum liest der Export live aus der
 * Buchung — der neue Stand geht also mit, sobald der Bewerber aus anderem
 * Grund exportiert wird (User-Entscheidung 05.10.). Die uebrigen Observer
 * reagieren nur auf Statuswechsel, und der Status bleibt hier unveraendert.
 *
 * Regeln (alle im Lock geprueft, die UI-Auswahl ist nur Komfort):
 *  - Quelle und Ziel im eigenen Team, beide mit derselben, gesetzten Stelle.
 *  - Ziel: aktiv, nicht abgesagt, noch nicht zu Ende, nicht die Quelle.
 *  - Buchungen vor der Schulung (MOVABLE_STATUSES) immer; Endstatus aus der
 *    Schulung (SAME_DAY_STATUSES) nur in einen Termin am selben Tag. Nur aus
 *    der Quelle.
 *  - Platzbelegende Buchungen brauchen einen freien Platz im Ziel; Standby
 *    belegt keinen und wandert immer mit.
 *  - Der Unique-Index (rec_interview_id, rec_applicant_id) gilt auch fuer
 *    soft-geloeschte Zeilen: hat der Bewerber im Ziel schon IRGENDEINE
 *    alte Buchung, wird er uebersprungen statt die alte Historie zu loeschen.
 *
 * Warteliste: die Observer reagieren nur auf Statuswechsel, nicht auf einen
 * Terminwechsel. Deshalb stoesst der Mover selbst an: Quelle hat Platz
 * bekommen (Notify), Ziel kann voll geworden sein (Re-Arm).
 */
class BookingMover
{
    public const MOVABLE_STATUSES = ['booked', 'registered', 'confirmed'];

    /**
     * Endstatus aus der Schulung selbst (08.10.2026, Feedback MGL-Runde 07.10.):
     * wer schon auf Teilgenommen steht, soll waehrend der Schulung noch in eine
     * Gruppe desselben Tages wandern koennen — mit seinem Status. In einen
     * Termin an einem anderen Tag nie: dort hat er nicht teilgenommen.
     */
    public const SAME_DAY_STATUSES = ['attended', 'no_show', 'rejected_on_site'];

    /** Darf diese Buchung von $source nach $target? (Status-Teil der Regeln) */
    public static function statusErlaubt(string $status, ?RecInterview $source, ?RecInterview $target): bool
    {
        if (in_array($status, self::MOVABLE_STATUSES, true)) {
            return true;
        }
        if (!in_array($status, self::SAME_DAY_STATUSES, true) || !$source || !$target) {
            return false;
        }

        return $source->starts_at && $target->starts_at
            && $source->starts_at->isSameDay($target->starts_at);
    }

    /** Status, die ueberhaupt in Frage kommen — fuer die Haken in der UI. */
    public static function statusMoeglich(string $status): bool
    {
        return in_array($status, self::MOVABLE_STATUSES, true)
            || in_array($status, self::SAME_DAY_STATUSES, true);
    }

    /** Zieltermine, die fuer die Quelle zulaessig sind (Komfort fuer die UI). */
    public function targetsFor(int $sourceInterviewId, int $teamId): Collection
    {
        $source = RecInterview::query()
            ->where('team_id', $teamId)
            ->find($sourceInterviewId);

        if (!$source || !$source->rec_position_id) {
            return new Collection();
        }

        return $this->eligibleTargets($source)
            ->withCount(['bookings as taken_seats_count' => fn ($q) => $q->seatTaking()])
            ->orderBy('starts_at')
            ->get();
    }

    /**
     * @param list<int|string> $bookingIds
     */
    public function move(
        int $sourceInterviewId,
        array $bookingIds,
        int $targetInterviewId,
        int $teamId,
        int $userId,
        string $userName,
        ?string $comment = null,
    ): BookingMoveResult {
        $bookingIds = array_values(array_unique(array_map('intval', $bookingIds)));
        $comment = trim((string) $comment) ?: null;

        if ($bookingIds === []) {
            return BookingMoveResult::failed('Keine Teilnehmer ausgewählt.');
        }

        $connection = (new RecInterviewBooking())->getConnection();

        $result = $connection->transaction(function () use ($sourceInterviewId, $bookingIds, $targetInterviewId, $teamId, $userId, $userName, $comment) {
            // Beide Termine sperren, in fester ID-Reihenfolge (kein Deadlock,
            // wenn zwei Leute gleichzeitig A→B und B→A verschieben). Dieselbe
            // Termin-Sperre nutzt die manuelle Buchung — Kapazitaet ist damit
            // gegen parallele Buchungen serialisiert.
            $locked = RecInterview::query()
                ->whereIn('id', [$sourceInterviewId, $targetInterviewId])
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            $source = $locked->get($sourceInterviewId);
            $target = $locked->get($targetInterviewId);

            if ($error = $this->targetError($source, $target, $teamId)) {
                return BookingMoveResult::failed($error);
            }

            $bookings = RecInterviewBooking::query()
                ->where('rec_interview_id', $source->id)
                ->where('team_id', $teamId)
                ->whereIn('id', $bookingIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            $applicantsWithRowInTarget = RecInterviewBooking::withTrashed()
                ->where('rec_interview_id', $target->id)
                ->whereIn('rec_applicant_id', $bookings->pluck('rec_applicant_id'))
                ->pluck('rec_applicant_id')
                ->flip();

            $taken = $target->bookings()->seatTaking()->count();
            $max = $target->max_participants ?: null;
            $now = Carbon::now();

            $moved = [];
            $skipped = [];

            foreach ($bookingIds as $id) {
                $booking = $bookings->get($id);

                if (!$booking) {
                    $skipped[$id] = 'Buchung gehört nicht (mehr) zu diesem Termin.';
                    continue;
                }

                if (!self::statusErlaubt($booking->status, $source, $target)) {
                    $skipped[$id] = in_array($booking->status, self::SAME_DAY_STATUSES, true)
                        ? 'Status „' . $booking->status_label . '" wandert nur in einen Termin am selben Tag.'
                        : 'Status „' . $booking->status_label . '" wird nicht verschoben.';
                    continue;
                }

                if ($applicantsWithRowInTarget->has($booking->rec_applicant_id)) {
                    $skipped[$id] = 'Hat im Zieltermin schon eine (alte) Buchung.';
                    continue;
                }

                $takesSeat = $booking->takes_seat;
                if ($takesSeat && $max !== null && $taken >= $max) {
                    $skipped[$id] = 'Zieltermin ist voll.';
                    continue;
                }

                $booking->forceFill([
                    'rec_interview_id'        => $target->id,
                    'moved_from_interview_id' => $source->id,
                    'moved_at'                => $now,
                    'moved_by_user_id'        => $userId,
                ])->saveQuietly();

                if ($takesSeat) {
                    $taken++;
                }

                $this->log($booking, $source, $target, $userId, $userName, $comment);
                $moved[] = $id;
            }

            return new BookingMoveResult($moved, $skipped);
        });

        if ($result->error === null && $result->moved !== []) {
            $this->notifyWaitlistSeatFreed($sourceInterviewId);
            $this->rearmWaitlistIfFull($targetInterviewId);
        }

        return $result;
    }

    private function eligibleTargets(RecInterview $source)
    {
        return RecInterview::query()
            ->where('team_id', $source->team_id)
            ->where('rec_position_id', $source->rec_position_id)
            ->where('id', '!=', $source->id)
            ->where('is_active', true)
            ->where('status', '!=', 'cancelled')
            // Bis zum ENDE des Ziels, nicht bis zum Beginn (08.10.2026): am 07.10.
            // starteten alle Gruppen um 18 Uhr — ab da liess sich waehrend der
            // Schulung niemand mehr aufteilen. Ohne Ende zaehlt der Beginn
            // (wie der Nachpflege-Hinweis: ends_at ?? starts_at).
            ->where(function ($q) {
                $now = Carbon::now();
                $q->where('ends_at', '>', $now)
                    ->orWhere(fn ($q) => $q->whereNull('ends_at')->where('starts_at', '>', $now));
            });
    }

    private function targetError(?RecInterview $source, ?RecInterview $target, int $teamId): ?string
    {
        if (!$source || (int) $source->team_id !== $teamId) {
            return 'Quelltermin nicht gefunden.';
        }
        if (!$source->rec_position_id) {
            return 'Der Termin hat keine Stelle — Verschieben geht nur innerhalb einer Stelle.';
        }
        if (!$target || !$this->eligibleTargets($source)->whereKey($target->id)->exists()) {
            return 'Zieltermin ist nicht zulässig (andere Stelle, schon zu Ende, abgesagt oder inaktiv).';
        }

        return null;
    }

    private function log(RecInterviewBooking $booking, RecInterview $source, RecInterview $target, int $userId, string $userName, ?string $comment): void
    {
        $label = fn (RecInterview $i) => trim(($i->title ?: 'Termin #' . $i->id) . ' (' . ($i->starts_at?->format('d.m.Y H:i') ?? '?') . ')');

        $summary = "Buchung von {$label($source)} nach {$label($target)} verschoben durch {$userName}.";
        if ($comment !== null) {
            $summary .= " Kommentar: {$comment}";
        }

        RecAutoPilotLog::create([
            'rec_applicant_id' => $booking->rec_applicant_id,
            'type'             => 'booking_moved',
            'summary'          => $summary,
            'details'          => [
                'booking_id'        => $booking->id,
                'from_interview_id' => $source->id,
                'to_interview_id'   => $target->id,
                'user_id'           => $userId,
                'user_name'         => $userName,
                'comment'           => $comment,
                'status'            => $booking->status,
            ],
        ]);
    }

    /** Quelle hat Platz bekommen. Der Job re-validiert selbst, Ueber-Dispatch ist safe. */
    protected function notifyWaitlistSeatFreed(int $interviewId): void
    {
        try {
            NotifyWaitlistForInterview::dispatch($interviewId);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /** Ziel kann voll geworden sein → Termin-Dauerabos wieder scharf stellen. */
    protected function rearmWaitlistIfFull(int $interviewId): void
    {
        try {
            WaitlistRearmService::rearmIfNowFull($interviewId);
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
