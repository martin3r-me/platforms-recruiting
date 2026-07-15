<?php

namespace Platform\Recruiting\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;
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

    /**
     * Keine Benachrichtigung mehr, wenn der Termin in weniger als
     * MIN_LEAD_HOURS beginnt — eine Push um 22 Uhr für eine Schulung am
     * nächsten Morgen bringt niemanden mehr in den Termin.
     */
    public const MIN_LEAD_HOURS = 24;

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
        if (!$interview->starts_at || $interview->starts_at->lt(now()->addHours(self::MIN_LEAD_HOURS))) {
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

        // 1) Termin-Wartende: warten auf genau diesen Termin.
        $this->notifyEntries(
            RecInterviewWaitlist::query()
                ->forTeam($interview->team_id)
                ->open()
                ->whereNull('notified_at')
                ->forInterview($interview->id)
                ->with('applicant')
                ->get()
        );

        // 2) Ort-Wartende (Bestand): explizit ortBased(), damit Termin-
        //    Einträge nicht über ihren Wunschorte-Snapshot mitmatchen.
        $ort = $interview->position?->beschaftigungsort_lookup_value;
        if (empty($ort)) {
            return;
        }

        $this->notifyEntries(
            RecInterviewWaitlist::query()
                ->forTeam($interview->team_id)
                ->open()
                ->whereNull('notified_at')
                ->ortBased()
                ->whereJsonContains('wunschorte', $ort)
                ->with('applicant')
                ->get()
        );
    }

    private function notifyEntries(Collection $entries): void
    {
        $entries->each(function (RecInterviewWaitlist $entry) {
            // Atomarer Versand-Anspruch: nur wenn diese Zeile noch
            // notified_at IS NULL hat, gewinnt genau dieser Lauf.
            $claimed = RecInterviewWaitlist::where('id', $entry->id)
                ->whereNull('notified_at')
                ->update(['notified_at' => now()]);

            if ($claimed !== 1) {
                return; // anderer Job war schneller
            }

            // Versand. Schlägt er fehl (z.B. Template/Account nicht
            // konfiguriert, transienter WA-Fehler), geben wir den
            // notified_at-Anspruch WIEDER FREI — sonst wäre der Bewerber
            // dauerhaft als "benachrichtigt" markiert ohne je eine
            // Nachricht erhalten zu haben, und ein späterer Termin würde
            // ihn wegen der "nur 1x"-Regel nie mehr erreichen.
            $applicant = $entry->applicant;
            $sent = $applicant && $applicant->is_active
                && $applicant->sendWaitlistAvailableNotification();

            if (!$sent) {
                // Nur der atomare Gewinner erreicht diesen Pfad, daher
                // ist ein Reset per ID konfliktfrei. fulfilled_at-Guard,
                // damit eine zwischenzeitliche Buchung nicht angefasst wird.
                RecInterviewWaitlist::where('id', $entry->id)
                    ->whereNull('fulfilled_at')
                    ->update(['notified_at' => null]);
            }
        });
    }
}
