<?php

namespace Platform\Recruiting\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Platform\Recruiting\Models\RecInterview;
use Platform\Recruiting\Models\RecInterviewBooking;
use Platform\Recruiting\Models\RecInterviewWaitlist;

/**
 * Benachrichtigt wartende Bewerber, dass für einen ihrer Wunschorte ein
 * Schulungstermin frei geworden ist. Wird vom RecInterviewWaitlistObserver
 * dispatcht, sobald ein RecInterview in einen verfügbaren Zustand übergeht.
 *
 * "Nur 1x"-Regel: pro Warteliste-Zeile wird der Versand-Anspruch atomar
 * über notified_at gesetzt. Nur wer den Anspruch gewinnt (1 affected row),
 * bekommt die Nachricht — auch bei parallelen Jobs/Workern wasserdicht.
 */
class NotifyWaitlistForInterview implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;
    public $timeout = 120;

    public function __construct(private int $interviewId) {}

    public function handle(): void
    {
        $interview = RecInterview::with('position')->find($this->interviewId);
        if (!$interview || !$interview->is_active) {
            return;
        }
        if (!in_array($interview->status, ['planned', 'confirmed'], true)) {
            return;
        }
        if (!$interview->starts_at || $interview->starts_at->isPast()) {
            return;
        }

        // Kapazität: nur benachrichtigen, wenn wirklich noch Platz ist.
        if ($interview->max_participants) {
            $booked = RecInterviewBooking::where('rec_interview_id', $interview->id)
                ->whereNotIn('status', ['cancelled'])
                ->count();
            if ($booked >= $interview->max_participants) {
                return;
            }
        }

        $ort = $interview->position?->beschaftigungsort_lookup_value;
        if (empty($ort)) {
            return;
        }

        RecInterviewWaitlist::query()
            ->forTeam($interview->team_id)
            ->open()
            ->whereNull('notified_at')
            ->whereJsonContains('wunschorte', $ort)
            ->with('applicant')
            ->get()
            ->each(function (RecInterviewWaitlist $entry) {
                // Atomarer Versand-Anspruch: nur wenn diese Zeile noch
                // notified_at IS NULL hat, gewinnt genau dieser Lauf.
                $claimed = RecInterviewWaitlist::where('id', $entry->id)
                    ->whereNull('notified_at')
                    ->update(['notified_at' => DB::raw('NOW()')]);

                if ($claimed !== 1) {
                    return; // anderer Job war schneller
                }

                $applicant = $entry->applicant;
                if ($applicant && $applicant->is_active) {
                    $applicant->sendWaitlistAvailableNotification();
                }
            });
    }
}
