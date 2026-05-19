<?php

namespace Platform\Recruiting\Services;

use Illuminate\Support\Facades\Log;
use Platform\Recruiting\Models\RecApplicant;
use Platform\Recruiting\Models\RecAutoPilotLog;
use Platform\Recruiting\Models\RecInterviewBooking;

/**
 * Verarbeitet Antworten auf Schulungs-Reminder-Templates.
 *
 * Bewerber bekommen X Stunden vor der Schulung ein WhatsApp-Template mit
 * Quick-Reply-Buttons "Ja" / "Nein". Wenn er klickt oder tippt, kommt der
 * Inbound-Text bei uns an. Dieser Service identifiziert solche Antworten
 * im engen Reminder-Kontext und setzt den Booking-Status entsprechend.
 *
 * Identifikations-Logik (Text-Matching weil WhatsAppMetaService den
 * urspruenglichen 'button'-Message-Type zu 'text' normalisiert und
 * der Button-Text als Body gespeichert wird):
 *
 *  - Body matched case-insensitive auf 'ja'/'yes' (positive) bzw.
 *    'nein'/'no' (negative)
 *  - Bewerber hat eine aktive Buchung (status in ['booked', 'registered'])
 *  - Diese Buchung hat reminder_sent_at innerhalb der letzten 72 Stunden
 *
 * Wenn auch nur eine Bedingung nicht erfuellt ist: kein Status-Change,
 * Inbound wird normal weiterverarbeitet (HR-sichtbar als regulaere
 * WhatsApp-Antwort).
 */
class ReminderResponseHandler
{
    private const POSITIVE_WORDS = ['ja', 'yes', 'j', 'y'];
    private const NEGATIVE_WORDS = ['nein', 'no', 'n'];

    /**
     * Versucht den Inbound-Text als Reminder-Antwort zu interpretieren.
     * Returnt true wenn als Antwort interpretiert + Status geaendert wurde.
     * Returnt false wenn der Text keine Reminder-Antwort ist — dann soll
     * der Caller die Nachricht ueber den normalen Inbound-Pfad behandeln.
     */
    public function handle(RecApplicant $applicant, string $messageBody): bool
    {
        $normalized = mb_strtolower(trim($messageBody));
        if ($normalized === '') {
            return false;
        }

        $intent = $this->resolveIntent($normalized);
        if ($intent === null) {
            return false;
        }

        // Finde die juengste Buchung mit reminder_sent_at innerhalb 72h
        // und Status in [booked, registered]. Aelter oder anderer Status
        // → keine Reminder-Antwort.
        $booking = $applicant->interviewBookings()
            ->whereIn('status', ['booked', 'registered'])
            ->whereNotNull('reminder_sent_at')
            ->where('reminder_sent_at', '>=', now()->subHours(72))
            ->orderByDesc('reminder_sent_at')
            ->first();

        if (!$booking) {
            return false;
        }

        if ($intent === 'yes') {
            $this->confirmBooking($booking, $applicant);
            return true;
        }

        $this->cancelBooking($booking, $applicant);
        return true;
    }

    private function resolveIntent(string $normalized): ?string
    {
        if (in_array($normalized, self::POSITIVE_WORDS, true)) {
            return 'yes';
        }
        if (in_array($normalized, self::NEGATIVE_WORDS, true)) {
            return 'no';
        }
        return null;
    }

    private function confirmBooking(RecInterviewBooking $booking, RecApplicant $applicant): void
    {
        $booking->update(['status' => 'confirmed']);

        try {
            RecAutoPilotLog::create([
                'rec_applicant_id' => $applicant->id,
                'type'             => 'booking_confirmed_by_reply',
                'summary'           => "Bewerber hat Schulungs-Reminder mit \"Ja\" bestaetigt — Booking #{$booking->id} → confirmed.",
            ]);
        } catch (\Throwable $e) {
            Log::warning('[ReminderResponseHandler] Could not log booking_confirmed_by_reply', [
                'applicant_id' => $applicant->id,
                'booking_id'   => $booking->id,
                'error'        => $e->getMessage(),
            ]);
        }
    }

    private function cancelBooking(RecInterviewBooking $booking, RecApplicant $applicant): void
    {
        $booking->update([
            'status'        => 'cancelled',
            'cancelled_by'  => 'applicant',
            'cancelled_at'  => now(),
        ]);

        // Bewerber auf HR-Schreibtisch — analog zum Public-Form-Absagen-Pfad
        $applicant->is_on_hr_desk = true;
        $applicant->auto_pilot    = false;
        $applicant->save();

        try {
            RecAutoPilotLog::create([
                'rec_applicant_id' => $applicant->id,
                'type'             => 'cancelled_by_reply',
                'summary'           => "Bewerber hat Schulungs-Reminder mit \"Nein\" beantwortet — Booking #{$booking->id} storniert, Bewerber auf HR-Schreibtisch.",
            ]);
        } catch (\Throwable $e) {
            Log::warning('[ReminderResponseHandler] Could not log cancelled_by_reply', [
                'applicant_id' => $applicant->id,
                'booking_id'   => $booking->id,
                'error'        => $e->getMessage(),
            ]);
        }
    }
}
