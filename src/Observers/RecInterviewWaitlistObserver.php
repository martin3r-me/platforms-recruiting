<?php

namespace Platform\Recruiting\Observers;

use Illuminate\Support\Facades\Log;
use Platform\Recruiting\Jobs\NotifyWaitlistForInterview;
use Platform\Recruiting\Models\RecApplicant;
use Platform\Recruiting\Models\RecInterview;
use Platform\Recruiting\Models\RecInterviewBooking;
use Platform\Recruiting\Models\RecInterviewWaitlist;
use Platform\Recruiting\Services\WaitlistRearmService;

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

                // Kapazitäts-SENKUNG kann den Termin voll machen →
                // Dauerabos scharf stellen (Erhöhung: Voll-Check no-op't).
                if ($interview->wasChanged('max_participants')) {
                    WaitlistRearmService::rearmIfNowFull($interview->id);
                }
            }, 'rec_interview.saved.waitlist', $interview->id);
        });

        RecInterviewBooking::saved(static function (RecInterviewBooking $booking): void {
            self::safelyRun(function () use ($booking): void {
                if (!$booking->rec_interview_id) {
                    return;
                }

                // Storno gibt ggf. einen Platz frei → Warteliste anstoßen.
                // Der Job re-validiert Kapazität/Status/Cutoff selbst;
                // Über-Dispatch ist dank armed-/notified_at-Claim safe.
                if ($booking->wasChanged('status') && $booking->status === 'cancelled') {
                    NotifyWaitlistForInterview::dispatch($booking->rec_interview_id);
                    return;
                }

                // Aktivierende Änderung (Neuanlage aktiv oder Status weg
                // von cancelled) kann den Termin VOLL machen → Dauerabos
                // wieder scharf stellen. rearmIfNowFull no-op't, wenn
                // noch Platz ist.
                $activated = $booking->status !== 'cancelled'
                    && ($booking->wasRecentlyCreated || $booking->wasChanged('status'));

                if ($activated) {
                    WaitlistRearmService::rearmIfNowFull($booking->rec_interview_id);
                }
            }, 'rec_interview_booking.saved.waitlist', $booking->id);
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

                // Aktive Buchungen an ZUKUENFTIGEN Terminen mitstornieren —
                // der Booking-saved-Observer oben bietet den Platz dann der
                // Warteliste an. Vergangene Termine (attended/no_show-Historie)
                // bleiben unangetastet.
                RecInterviewBooking::where('rec_applicant_id', $applicant->id)
                    ->whereIn('status', ['booked', 'registered', 'confirmed'])
                    ->whereHas('interview', fn ($q) => $q->where('starts_at', '>', now()))
                    ->get()
                    ->each(function (RecInterviewBooking $booking): void {
                        $booking->status = 'cancelled';
                        $booking->cancelled_by = 'system';
                        $booking->cancelled_at = now();
                        $booking->save();
                    });
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
