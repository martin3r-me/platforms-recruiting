<?php
// src/Services/ContractSendReservationService.php
namespace Platform\Recruiting\Services;

use Illuminate\Support\Carbon;
use Platform\Recruiting\Models\RecApplicant;
use Platform\Recruiting\Models\RecAutoPilotLog;
use Platform\Recruiting\Models\RecContractSendReservation;

/**
 * Vormerkungen fuer den automatischen Vertragsversand (Spec Versand vormerken
 * §2/§3/§6). Hoechstens EINE offene je Bewerber; erneutes Vormerken
 * aktualisiert sie. Erinnerung = Onboarding-Vorlage der Phase, gedrosselt auf
 * eine pro 24 h. Jeder Schritt steht im Verlauf (rec_auto_pilot_logs).
 */
class ContractSendReservationService
{
    public const REMINDER_THROTTLE_HOURS = 24;

    /** Ging beim letzten vormerken() die Erinnerung wirklich raus? (ehrliche Meldung) */
    public bool $letzteErinnerungGesendet = false;

    /** @param list<string> $fehlendeFelder */
    public function vormerken(
        RecApplicant $applicant,
        ?int $bookingId,
        ?string $vertragsbeginn,
        ?string $vertragsende,
        string $source,
        ?int $userId,
        string $userName,
        array $fehlendeFelder,
    ): RecContractSendReservation {
        $offen = $applicant->contractSendReservations()->offen()->first();
        $neu = $offen === null;

        $daten = [
            'rec_interview_booking_id' => $bookingId,
            'vertragsbeginn' => $vertragsbeginn ?: null,
            'vertragsende' => $vertragsende ?: null,
            'source' => $source,
            'reserved_by_user_id' => $userId,
            'reserved_by_name' => mb_substr($userName, 0, 190),
            'reserved_at' => Carbon::now(),
        ];

        if ($neu) {
            $offen = $applicant->contractSendReservations()->create($daten + ['team_id' => $applicant->team_id]);
        } else {
            $offen->fill($daten)->save();
        }

        $felder = $fehlendeFelder === [] ? 'keine Pflichtfelder offen, wartet auf Phasenwechsel bzw. Freigabe' : implode(', ', $fehlendeFelder);
        $this->log($applicant, 'contract_send_reserved', sprintf(
            'Versand %s von %s — Onboarding unvollständig: %s. Vertragsbeginn %s. Geht automatisch raus, sobald die Daten vollständig sind.',
            $neu ? 'vorgemerkt' : 'erneut vorgemerkt (Daten aktualisiert)',
            $userName,
            $felder,
            $vertragsbeginn ?: '—',
        ), ['reservation_id' => $offen->id, 'user_id' => $userId, 'fehlende_felder' => $fehlendeFelder, 'source' => $source]);

        $this->letzteErinnerungGesendet = $this->erinnern($offen);

        return $offen;
    }

    /** true = Erinnerung ging raus. Drossel: eine pro 24 h, ausser force. */
    public function erinnern(RecContractSendReservation $reservation, bool $force = false): bool
    {
        if (!$force && $reservation->last_reminder_at !== null
            && $reservation->last_reminder_at->gt(Carbon::now()->subHours(self::REMINDER_THROTTLE_HOURS))) {
            return false;
        }

        $applicant = $reservation->applicant;
        if (!$applicant) {
            return false;
        }

        // Nichts offen (typisch AutoPilot aus, Fall wartet auf Freigabe): keine
        // Erinnerung — der Bewerber kann nichts mehr ausfuellen.
        if ($applicant->fehlendePflichtfelder() === []) {
            return false;
        }

        try {
            $ergebnis = $this->erinnerungSenden($applicant);
        } catch (\Throwable $e) {
            $ergebnis = ['ok' => false, 'error' => $e->getMessage()];
        }
        if ($ergebnis['ok'] ?? false) {
            $reservation->forceFill(['last_reminder_at' => Carbon::now()])->save();

            return true;
        }

        // Erfolg loggt das Modell selbst (contract_send_reminder); hier nur der Fehler.
        $this->log($applicant, 'contract_send_reminder', 'Erinnerung zur Vervollständigung NICHT gesendet: ' . ($ergebnis['error'] ?? 'unbekannt'), ['reservation_id' => $reservation->id]);

        return false;
    }

    public function zuruecknehmen(RecContractSendReservation $reservation, string $grund, ?int $userId = null): void
    {
        if (!$reservation->istOffen()) {
            return;
        }
        $reservation->forceFill(['cancelled_at' => Carbon::now(), 'cancel_reason' => mb_substr($grund, 0, 255)])->save();
        if ($reservation->applicant) {
            $this->log($reservation->applicant, 'contract_send_cancelled', 'Versand-Vormerkung zurückgenommen: ' . $grund, ['reservation_id' => $reservation->id, 'user_id' => $userId]);
        }
    }

    public function abschliessen(RecContractSendReservation $reservation, string $ergebnis): void
    {
        $reservation->forceFill([
            'completed_at' => Carbon::now(),
            'last_attempt_at' => Carbon::now(),
            'last_attempt_result' => mb_substr($ergebnis, 0, 500),
        ])->save();
    }

    public function vermerkeVersuch(RecContractSendReservation $reservation, string $ergebnis): void
    {
        $reservation->forceFill(['last_attempt_at' => Carbon::now(), 'last_attempt_result' => mb_substr($ergebnis, 0, 500)])->save();
        if ($reservation->applicant) {
            $this->log($reservation->applicant, 'contract_send_waiting', 'Automatischer Versand wartet: ' . $ergebnis, ['reservation_id' => $reservation->id]);
        }
    }

    /** @return array{ok: bool, error: ?string} */
    protected function erinnerungSenden(RecApplicant $applicant): array
    {
        return $applicant->sendPhaseOnboardingReminder();
    }

    private function log(RecApplicant $applicant, string $type, string $summary, array $details = []): void
    {
        try {
            RecAutoPilotLog::create([
                'rec_applicant_id' => $applicant->id,
                'type' => $type,
                'summary' => $summary,
                'details' => $details,
            ]);
        } catch (\Throwable) {
            // Verlauf darf die Aktion nie kippen.
        }
    }
}
