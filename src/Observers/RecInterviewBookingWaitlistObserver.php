<?php

namespace Platform\Recruiting\Observers;

use Illuminate\Support\Facades\Log;
use Platform\Recruiting\Models\RecAutoPilotLog;
use Platform\Recruiting\Models\RecInterviewBooking;
use Platform\Recruiting\Models\RecInterviewWaitlist;

/**
 * Wer einen Termin hat, braucht keinen zweiten: eine neue oder wieder
 * aktivierte Buchung schliesst alle offenen Warteliste-Eintraege des
 * Bewerbers — Ort-Eintrag UND Termin-Abos, unabhaengig vom Termin.
 *
 * Das ist genau die Semantik, die der oeffentliche Pfad schon hat
 * (Public/InterviewBooking.php:337-340, ->open() ohne Termin-Filter). Hier
 * haengt sie am Model, damit HR-Dialog, MCP-Tool und CSV-Sammelbuchung nicht
 * jeder fuer sich daran denken muessen. Ohne das bekommt ein manuell gebuchter
 * Bewerber spaeter noch eine "Termin frei geworden"-WhatsApp: der
 * Versand-Guard prueft fulfilled_at, nicht ob eine Buchung existiert
 * (NotifyWaitlistForInterview.php:177-180).
 *
 * fulfilled_at und nicht cancelled_at: der Bewerber HAT einen Termin bekommen.
 * cancelled_at heisst im Datenmodell "hat sich selbst abgemeldet".
 *
 * Bewusst pauschal: auch ein Termin-Abo fuer einen ANDEREN (vollen) Termin
 * fliegt raus. Wer wirklich umsteigen will, kann sich ueber seinen Portal-Link
 * neu eintragen. Bei manueller Buchung ist das eine bewusste HR-Entscheidung —
 * deshalb der Log-Eintrag, damit es in drei Wochen nicht wie ein Bug aussieht.
 *
 * Body in safelyRun(): ein Bug hier darf nie einen regulaeren Save kaputt
 * machen (gleiches Prinzip wie RecInterviewBookingComplianceObserver).
 */
class RecInterviewBookingWaitlistObserver
{
    public static function register(): void
    {
        RecInterviewBooking::saved(static function (RecInterviewBooking $booking): void {
            self::safelyRun(function () use ($booking): void {
                // Nur beim Entstehen oder bei einem Statuswechsel — sonst
                // laeuft die Query bei jedem Feld-Update mit (Notizen,
                // Reminder-Zeitstempel, Standby-Marker).
                if (!$booking->wasRecentlyCreated && !$booking->wasChanged('status')) {
                    return;
                }

                if ($booking->status === 'cancelled') {
                    return;
                }

                $offene = RecInterviewWaitlist::where('rec_applicant_id', $booking->rec_applicant_id)
                    ->open()
                    ->get();

                if ($offene->isEmpty()) {
                    return;
                }

                RecInterviewWaitlist::whereIn('id', $offene->pluck('id'))
                    ->update(['fulfilled_at' => now()]);

                // created_by_user_id trennt die Pfade: der oeffentliche
                // Buchungspfad setzt es nicht (Public/InterviewBooking.php:308-321),
                // HR-Dialog, MCP-Tool und Sammelbuchung setzen es.
                $durchHr = $booking->created_by_user_id !== null;
                $anzahl = $offene->count();
                $abos = $offene->whereNotNull('rec_interview_id')->count();

                RecAutoPilotLog::create([
                    'rec_applicant_id' => $booking->rec_applicant_id,
                    'type'             => 'waitlist_closed',
                    'summary'          => $durchHr
                        ? "Warteliste geschlossen ({$anzahl} Eintrag/Einträge, davon {$abos} Termin-Abo) — manuelle Buchung durch HR (Buchung #{$booking->id})."
                        : "Warteliste geschlossen ({$anzahl} Eintrag/Einträge, davon {$abos} Termin-Abo) — Bewerber hat selbst gebucht (Buchung #{$booking->id}).",
                    'details'          => [
                        'booking_id'   => $booking->id,
                        'interview_id' => $booking->rec_interview_id,
                        'entry_ids'    => $offene->pluck('id')->all(),
                        'by_hr'        => $durchHr,
                    ],
                ]);
            }, 'rec_interview_booking.saved.waitlist', $booking->id);
        });
    }

    private static function safelyRun(callable $fn, string $context, ?int $id = null): void
    {
        try {
            $fn();
        } catch (\Throwable $e) {
            try {
                Log::warning("[{$context}] fehlgeschlagen", ['id' => $id, 'error' => $e->getMessage()]);
            } catch (\Throwable) {
            }
        }
    }
}
