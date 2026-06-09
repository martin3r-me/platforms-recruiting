<?php

namespace Platform\Recruiting\Observers;

use Illuminate\Support\Facades\Log;
use Platform\Recruiting\Jobs\NotifyWaitlistForInterview;
use Platform\Recruiting\Models\RecApplicant;
use Platform\Recruiting\Models\RecInterview;
use Platform\Recruiting\Models\RecInterviewWaitlist;

/**
 * Verdrahtet die Schulung-Warteliste mit dem Lebenszyklus:
 *  - RecInterview wird verfügbar  → Job benachrichtigt passende Warter
 *  - Bewerber rejected/parked/inaktiv → offene Warteliste-Zeile canceln
 *
 * Alle Bodies in safelyRun() — ein Bug hier darf nie einen regulären
 * Save kaputt machen (gleiches Prinzip wie RecApplicantExportObserver).
 */
class RecInterviewWaitlistObserver
{
    public static function register(): void
    {
        RecInterview::saved(static function (RecInterview $interview): void {
            self::safelyRun(function () use ($interview): void {
                // Nur dispatchen, wenn der Slot gerade verfügbar IST und
                // entweder neu angelegt wurde oder ein verfügbarkeits-
                // relevantes Feld sich geändert hat. Der Job re-validiert
                // alles selbst — Über-Dispatch ist dank notified_at safe.
                $isAvailable = $interview->is_active
                    && in_array($interview->status, ['planned', 'confirmed'], true)
                    && $interview->starts_at
                    && $interview->starts_at->isFuture();

                if (!$isAvailable) {
                    return;
                }

                $relevantChange = $interview->wasRecentlyCreated
                    || $interview->wasChanged(['is_active', 'status', 'starts_at', 'max_participants']);

                if (!$relevantChange) {
                    return;
                }

                NotifyWaitlistForInterview::dispatch($interview->id);
            }, 'rec_interview.saved.waitlist', $interview->id);
        });

        RecApplicant::saved(static function (RecApplicant $applicant): void {
            self::safelyRun(function () use ($applicant): void {
                // Bewerber fällt aus dem Flow → offene Warteliste-Zeile
                // canceln, damit Zähler/Liste sauber bleiben.
                $droppedOut = ($applicant->wasChanged('rejected_at') && $applicant->rejected_at)
                    || ($applicant->wasChanged('is_parked') && $applicant->is_parked)
                    || ($applicant->wasChanged('is_active') && !$applicant->is_active);

                if (!$droppedOut) {
                    return;
                }

                RecInterviewWaitlist::where('rec_applicant_id', $applicant->id)
                    ->open()
                    ->update(['cancelled_at' => now()]);
            }, 'rec_applicant.saved.waitlist', $applicant->id);
        });
    }

    private static function safelyRun(callable $fn, string $context, $id): void
    {
        try {
            $fn();
        } catch (\Throwable $e) {
            Log::warning("Waitlist-Observer Fehler [{$context}#{$id}]: " . $e->getMessage());
        }
    }
}
