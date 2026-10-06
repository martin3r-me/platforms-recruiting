<?php

namespace Platform\Recruiting\Services;

use Illuminate\Support\Carbon;
use Platform\Recruiting\Models\RecApplicant;
use Platform\Recruiting\Models\RecAutoPilotLog;
use Platform\Recruiting\Models\RecContractSendReservation;
use Platform\Recruiting\Models\RecContractTemplate;
use Platform\Recruiting\Models\RecHrDeskCase;

/**
 * Der automatische Versand einer Vormerkung (Spec Versand vormerken §4).
 * Prueft ALLES frisch, in fester Reihenfolge, und schreibt jedes Ergebnis an
 * die Vormerkung + in den Verlauf. Idempotent: Zeilensperre auf der
 * Vormerkung + Beleg (claimed_at), „schon versendet" schliesst ab statt zu senden.
 */
class ReservedContractSender
{
    public function __construct(
        private ContractDispatchService $dispatch,
        private ContractSendReservationService $reservations,
        private HrDeskRoutingService $hrDesk,
    ) {}

    /** Ein Beleg juenger als das gilt als laufender Versand (kein zweiter Lauf). */
    public const CLAIM_MINUTEN = 15;

    /**
     * Ablauf als Beleg-Muster (Controller-Entscheid 9):
     *  a) kurze Transaktion: Zeilensperre, Stufen 1–6, bei Erfolg claimed_at setzen;
     *  b) sendForApplicant AUSSERHALB jeder Transaktion — die Portal-WA geht sofort
     *     an Meta, ein Rollback darf die Vertraege nicht zuruecknehmen (sonst
     *     sieht ein Neuversuch „nichts versendet" und sendet doppelt);
     *  c) Ergebnis kurz nachtragen, Beleg loesen;
     *  d) Exception nach dem Beleg: Beleg loesen, Grund vermerken, weiterwerfen.
     */
    public function versuchen(int $applicantId): string
    {
        $connection = (new RecContractSendReservation())->getConnection();

        $pruefung = $connection->transaction(function () use ($applicantId) {
            $reservation = RecContractSendReservation::query()
                ->where('rec_applicant_id', $applicantId)->offen()
                ->lockForUpdate()->first();
            if (!$reservation) {
                return 'keine_vormerkung';
            }

            // Laeuft schon ein Versand? Still ueberspringen (kein Verlaufs-Spam).
            if ($reservation->claimed_at !== null && $reservation->claimed_at->gt(Carbon::now()->subMinutes(self::CLAIM_MINUTEN))) {
                return 'laeuft_bereits';
            }

            $applicant = RecApplicant::with(['phase.position', 'position', 'legalStatus', 'employee'])->find($applicantId);

            // 1) Bewerber noch im Rennen?
            if (!$applicant || !$applicant->is_active || $applicant->is_parked || $applicant->rejected_at !== null) {
                $this->reservations->zuruecknehmen($reservation, 'Bewerber nicht mehr aktiv (geparkt/abgelehnt/deaktiviert).');
                return 'abgebrochen';
            }

            // 2) Schon ein Vertrag raus (anderer Weg)?
            if ($applicant->hasAnyContractSent()) {
                $this->reservations->abschliessen($reservation, 'Verträge waren bereits auf anderem Weg versendet.');
                return 'bereits_versendet';
            }

            // 3) Rechtsstatus / offener HR-Fall (Controller-Entscheid 6):
            //    - ungeprueft → Nicht-EU-Fall anlegen (idempotent) und warten;
            //    - geprueft → ein offener Nicht-EU-Fall blockt NICHT mehr (er wird
            //      nach dem Versand freigegeben, wie HrDesk\Index::sendContractsFromDesk);
            //    - jeder andere vertragsblockierende offene Fall (minderjaehrig,
            //      Schulungsklaerung) blockt weiter.
            $offeneGruende = $applicant->hrDeskCases()->open()
                ->whereIn('reason', RecHrDeskCase::CONTRACT_BLOCKING_REASONS)
                ->pluck('reason')->all();
            if ($applicant->isLegalStatusUnchecked()) {
                $this->hrDesk->routeIfNotAlreadyOpen($applicant, RecHrDeskCase::REASON_NON_EU_CITIZEN, null, sprintf(
                    'Versand vorgemerkt am %s (Vertragsbeginn %s) — nach Rechtsstatus-Prüfung und Freigabe geht er automatisch raus.',
                    $reservation->reserved_at?->format('d.m.Y H:i'),
                    $reservation->vertragsbeginn?->format('d.m.Y') ?? '—',
                ));
                $this->reservations->vermerkeVersuch($reservation, 'Rechtsstatus prüfen (HR-Schreibtisch).');
                return 'wartet_hr';
            }
            $andereGruende = array_values(array_diff($offeneGruende, [RecHrDeskCase::REASON_NON_EU_CITIZEN]));
            if ($andereGruende !== []) {
                $this->reservations->vermerkeVersuch($reservation, 'Offener HR-Schreibtisch-Fall (' . implode(', ', $andereGruende) . ').');
                return 'wartet_hr';
            }

            // 4) Bereit?
            $bereitschaft = $applicant->versandBereitschaft();
            if ($bereitschaft->status === 'gesperrt') {
                $this->reservations->vermerkeVersuch($reservation, $bereitschaft->grund);
                return 'wartet_gesperrt';
            }
            if ($bereitschaft->status === 'unvollstaendig') {
                $this->reservations->vermerkeVersuch($reservation, $bereitschaft->grund);
                return 'wartet_unvollstaendig';
            }

            // 5) Vertragsbeginn nicht in der Vergangenheit (User-Entscheidung: stoppen)
            if ($reservation->vertragsbeginn !== null && $reservation->vertragsbeginn->lt(Carbon::today())) {
                $this->reservations->vermerkeVersuch($reservation, 'Vertragsbeginn ' . $reservation->vertragsbeginn->format('d.m.Y') . ' liegt in der Vergangenheit — bitte in der Nachbereitung anpassen.');
                return 'wartet_vertragsbeginn';
            }

            // 6) Zuschlag
            if ($applicant->zuschlag === null) {
                $this->reservations->vermerkeVersuch($reservation, 'Zuschlag fehlt.');
                return 'wartet_zuschlag';
            }

            // Alle Stufen bestanden → belegen; gesendet wird nach dem Commit.
            $reservation->forceFill(['claimed_at' => Carbon::now()])->save();

            return [$reservation, $applicant, in_array(RecHrDeskCase::REASON_NON_EU_CITIZEN, $offeneGruende, true)];
        });

        if (is_string($pruefung)) {
            return $pruefung;
        }

        [$reservation, $applicant, $nichtEuFallOffen] = $pruefung;

        try {
            // 7) Senden — gleiche Sequenz wie der Klick (Vertraege → Mitarbeiter → Portal),
            //    bewusst ohne umschliessende Transaktion.
            $defaultTemplate = RecContractTemplate::where('team_id', $applicant->team_id)->where('code', 'AV-default')->where('is_active', true)->first();
            $result = $this->dispatch->sendForApplicant($applicant, $reservation->reserved_by_user_id ? (int) $reservation->reserved_by_user_id : null, $reservation->contractFields(), $defaultTemplate);

            $reservation->claimed_at = null;

            if ($result['status'] === 'sent') {
                $portal = ContractDispatchService::isPortalFailure($result) ? ' Portal-WA fehlgeschlagen: ' . ($result['message'] ?? '') : '';
                $this->reservations->abschliessen($reservation, 'Automatisch versendet.' . $portal);
                $this->log($applicant, 'contract_send_auto_sent', sprintf(
                    'Verträge + Portal-Link automatisch versendet (vorgemerkt am %s von %s, Vertragsbeginn %s).%s',
                    $reservation->reserved_at?->format('d.m.Y H:i'),
                    $reservation->reserved_by_name ?? ('Benutzer #' . ($reservation->reserved_by_user_id ?? '—')),
                    $reservation->vertragsbeginn?->format('d.m.Y') ?? '—',
                    $portal,
                ), ['reservation_id' => $reservation->id]);

                if ($nichtEuFallOffen) {
                    $this->nichtEuFallFreigeben($applicant, $reservation->reserved_by_user_id !== null ? (int) $reservation->reserved_by_user_id : null);
                }
                return 'versendet';
            }

            if ($result['status'] === 'skipped_already_sent') {
                $this->reservations->abschliessen($reservation, 'Verträge waren bereits versendet.');
                return 'bereits_versendet';
            }

            $this->reservations->vermerkeVersuch($reservation, 'Versand fehlgeschlagen: ' . ($result['message'] ?? 'unbekannt'));
            return 'fehler';
        } catch (\Throwable $e) {
            try {
                $reservation->claimed_at = null;
                $this->reservations->vermerkeVersuch($reservation, 'Versand abgebrochen: ' . $e->getMessage());
            } catch (\Throwable) {}
            throw $e;
        }
    }

    /**
     * Gibt den offenen Nicht-EU-Fall nach dem automatischen Versand frei
     * (Spiegel von HrDesk\Index::sendContractsFromDesk). Laeuft nach dem Versand,
     * ausserhalb jeder Transaktion. Ein Fehler hier macht aus „versendet" keinen
     * Fehler — er landet nur im Verlauf. Ohne Benutzer an der Vormerkung keine
     * Freigabe (resolved_by_user_id = 0 verletzt den Fremdschluessel).
     */
    private function nichtEuFallFreigeben(RecApplicant $applicant, ?int $userId): void
    {
        if ($userId === null) {
            $this->log($applicant, 'contract_send_approve_failed', 'HR-Fall nach automatischem Versand NICHT freigegeben: kein Benutzer an der Vormerkung.');
            return;
        }
        try {
            $case = RecHrDeskCase::query()
                ->where('rec_applicant_id', $applicant->id)
                ->where('reason', RecHrDeskCase::REASON_NON_EU_CITIZEN)
                ->open()->first();
            if ($case) {
                $this->hrDesk->approveCase($case, $userId, 'Verträge + Portallink automatisch versendet (Vormerkung).');
            }
        } catch (\Throwable $e) {
            $this->log($applicant, 'contract_send_approve_failed', 'HR-Fall nach automatischem Versand NICHT freigegeben: ' . $e->getMessage());
        }
    }

    private function log(RecApplicant $applicant, string $type, string $summary, array $details = []): void
    {
        try {
            RecAutoPilotLog::create(['rec_applicant_id' => $applicant->id, 'type' => $type, 'summary' => $summary, 'details' => $details]);
        } catch (\Throwable) {}
    }
}
