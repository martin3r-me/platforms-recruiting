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

    /**
     * Notbremse gegen Sekunden-Flattern (voll↔frei im Minutentakt):
     * Mindestabstand zwischen zwei Nachrichten an dieselbe Person für
     * denselben Termin. NICHT der Haupt-Mechanismus (das ist der
     * armed-Claim = ein Ereignis pro Voll→Frei-Fenster) — nur ein Deckel
     * für den pathologischen Fall. Greift die Bremse, bleibt der Eintrag
     * scharf; zugestellt wird beim nächsten Trigger nach Ablauf.
     */
    public const RENOTIFY_COOLDOWN_MINUTES = 60;

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
                ->seatTaking()
                ->count();
            if ($booked >= $interview->max_participants) {
                return;
            }
        }

        // 1) Termin-Abos (Dauerabo): scharfe Einträge dieses Termins.
        //    armed wird beim Voll-Werden gesetzt (WaitlistRearmService)
        //    und hier atomar verbraucht — ein Ereignis pro
        //    Voll→Frei-Fenster, Storno-Wellen im selben Fenster finden
        //    armed=0 vor.
        $this->notifyTerminEntries(
            RecInterviewWaitlist::query()
                ->forTeam($interview->team_id)
                ->open()
                ->where('armed', true)
                ->forInterview($interview->id)
                ->with('applicant')
                ->get(),
            $interview
        );

        // 2) Ort-Wartende (Bestand): explizit ortBased(), damit Termin-
        //    Einträge nicht über ihren Wunschorte-Snapshot mitmatchen.
        $ort = $interview->position?->beschaftigungsort_lookup_value;
        if (empty($ort)) {
            return;
        }

        // Skip-Logik: Wer ein OFFENES Termin-Abo für genau diesen Termin
        // hat, wird vom Ort-Zweig für diesen Termin übersprungen — das
        // speziellere Abo gewinnt, keine Doppel-WhatsApp.
        $terminAboApplicantIds = RecInterviewWaitlist::query()
            ->forInterview($interview->id)
            ->open()
            ->pluck('rec_applicant_id');

        $this->notifyEntries(
            RecInterviewWaitlist::query()
                ->forTeam($interview->team_id)
                ->open()
                ->whereNull('notified_at')
                ->ortBased()
                ->whereJsonContains('wunschorte', $ort)
                ->when($terminAboApplicantIds->isNotEmpty(), fn ($query) => $query->whereNotIn('rec_applicant_id', $terminAboApplicantIds))
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

    /**
     * Dauerabo-Zustellung (nur Termin-Einträge). Atomarer Claim auf
     * armed=1: nur wer das Flag umlegt (1 affected row), verschickt —
     * parallel laufende Jobs desselben Frei-Fensters gehen leer aus.
     * Die Cooldown-Bedingung steckt IM Claim-UPDATE: greift die
     * Notbremse, bleibt armed=1 stehen (Zustellung beim nächsten
     * Trigger nach Ablauf), nur notified_at/armed werden NICHT angefasst.
     */
    private function notifyTerminEntries(Collection $entries, RecInterview $interview): void
    {
        $entries->each(function (RecInterviewWaitlist $entry) use ($interview) {
            $previousNotifiedAt = $entry->notified_at;

            $claimed = RecInterviewWaitlist::where('id', $entry->id)
                ->where('armed', true)
                // Härtung gegen das ms-Fenster zwischen get() und Claim:
                // wer sich gerade abgemeldet (cancelled_at) oder gebucht
                // (fulfilled_at) hat, bekommt keine WhatsApp mehr — gleiche
                // Logik wie der hasActive-Guard im Public-Booking.
                ->whereNull('cancelled_at')
                ->whereNull('fulfilled_at')
                ->where(function ($query) {
                    $query->whereNull('notified_at')
                        ->orWhere('notified_at', '<=', now()->subMinutes(self::RENOTIFY_COOLDOWN_MINUTES));
                })
                ->update(['armed' => false, 'notified_at' => now()]);

            if ($claimed !== 1) {
                return; // anderer Job war schneller ODER Notbremse aktiv
            }

            // Versand: termin-spezifisches Template ({{termin}}), mit
            // Fallback aufs generische Template (siehe RecApplicant).
            $applicant = $entry->applicant;
            $sent = $applicant && $applicant->is_active
                && $applicant->sendTerminWaitlistNotification($interview);

            if (!$sent) {
                // Claim zurückgeben: wieder scharf UND den alten
                // notified_at-Stand wiederherstellen — sonst würde der
                // fehlgeschlagene Versand die Notbremse für eine Stunde
                // scharf schalten, obwohl nichts ankam. fulfilled_at-Guard
                // wie im Ort-Loop: zwischenzeitliche Buchung nicht anfassen.
                RecInterviewWaitlist::where('id', $entry->id)
                    ->whereNull('fulfilled_at')
                    ->update(['armed' => true, 'notified_at' => $previousNotifiedAt]);
            }
        });
    }
}
