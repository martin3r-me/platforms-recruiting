<?php

namespace Platform\Recruiting\Services;

use Platform\Recruiting\Models\RecInterview;
use Platform\Recruiting\Models\RecInterviewBooking;
use Platform\Recruiting\Models\RecInterviewWaitlist;

/**
 * Automatisches Re-Arm der Termin-Dauerabos: Wird ein Termin (wieder)
 * VOLL, werden alle offenen Termin-Einträge scharf gestellt (armed=1) —
 * der nächste Voll→Frei-Übergang ist damit ein neues Ereignis und
 * benachrichtigt erneut (Produktentscheidung: Dauerabo, Variante B).
 *
 * Bewusst NICHT im Notify-Job: der kennt nur den Ist-Zustand "frei".
 * Das Voll-Werden ist die diskrete Ereignis-Grenze und wird an der
 * Quelle beobachtet (Buchungs-Aktivierung, Kapazitäts-Senkung).
 *
 * Über-Aufruf ist harmlos: der Voll-Check no-op't, und armed=1 auf
 * bereits scharfen Einträgen ändert nichts (idempotent).
 */
class WaitlistRearmService
{
    public static function rearmIfNowFull(int $interviewId): void
    {
        $interview = RecInterview::find($interviewId);
        if (!$interview || !$interview->max_participants) {
            return;
        }

        $booked = RecInterviewBooking::where('rec_interview_id', $interviewId)
            ->whereNotIn('status', ['cancelled'])
            ->count();

        if ($booked < $interview->max_participants) {
            return;
        }

        // Query-Builder: keine Model-Events, kein Observer-Ping-Pong.
        RecInterviewWaitlist::query()
            ->forInterview($interviewId)
            ->open()
            ->where('armed', false)
            ->update(['armed' => true]);
    }
}
