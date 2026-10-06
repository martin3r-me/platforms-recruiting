<?php

namespace Platform\Recruiting\Services;

use Platform\Recruiting\Models\RecContractTemplate;
use Platform\Recruiting\Models\RecInterviewBooking;

/**
 * Der Klick „Vertraege versenden" fuer eine Gruppe (Nachbereitung) oder eine
 * Person (HR-Schreibtisch) — Spec Versand vormerken §3:
 *   bereit → sofort senden · unvollstaendig → vormerken + Erinnerung ·
 *   gesperrt → nur melden. Vorfilter (Teilgenommen, Vorlage, Rechtsstatus,
 *   Zuschlag) macht der Aufrufer; Vertragsbeginn wird HIER verlangt, damit
 *   nie ohne Datum vorgemerkt wird.
 */
final class ContractSendRun
{
    public function __construct(
        private ContractDispatchService $dispatch,
        private ContractSendReservationService $reservations,
    ) {}

    /**
     * @param iterable<RecInterviewBooking> $bookings
     * @param array<int, array{vertragsbeginn?: ?string, vertragsende?: ?string}> $datenJeBewerber
     */
    public function ausfuehren(iterable $bookings, array $datenJeBewerber, ?RecContractTemplate $defaultTemplate, int $userId, string $userName, string $source): ContractSendRunResult
    {
        $ergebnis = new ContractSendRunResult();

        foreach ($bookings as $booking) {
            $applicant = $booking->applicant;
            if (!$applicant) {
                continue;
            }
            // Ein Fehler bei einer Person darf den halb gelaufenen Sammelversand
            // nicht abbrechen — sie landet unter fehler, die anderen laufen weiter.
            try {
                $daten = $datenJeBewerber[$applicant->id] ?? [];
                $beginn = $daten['vertragsbeginn'] ?? null;
                $ende = $daten['vertragsende'] ?? null;

                // Gesperrt zuerst: wer ohnehin nicht versendet wird, braucht kein
                // Datum — sonst stuende ein Gesperrter ohne Beginn als Fehler da.
                $bereitschaft = $applicant->versandBereitschaft();

                if ($bereitschaft->status === 'gesperrt') {
                    $ergebnis->gesperrt[$applicant->id] = $bereitschaft->grund;
                    continue;
                }

                if (empty($beginn)) {
                    $ergebnis->fehler[$applicant->id] = 'Vertragsbeginn fehlt.';
                    continue;
                }

                if ($bereitschaft->status === 'unvollstaendig') {
                    $this->reservations->vormerken($applicant, $booking->id, $beginn, $ende, $source, $userId, $userName, $bereitschaft->fehlendeFelder);
                    $ergebnis->vorgemerkt[] = $applicant->id;
                    if ($this->reservations->letzteErinnerungGesendet) {
                        $ergebnis->erinnert[] = $applicant->id;
                    }
                    continue;
                }

                $result = $this->dispatch->sendForApplicant($applicant, $userId, ['vertragsbeginn' => $beginn, 'vertragsende' => $ende], $defaultTemplate);
                if ($result['status'] === 'sent') {
                    $ergebnis->versendet[] = $applicant->id;
                    // Eine offene Vormerkung ist mit dem Direktversand erledigt. Scheitert
                    // nur dieses Abschliessen, bleibt die Person „versendet" — die
                    // Vertraege SIND raus; der naechste Job-Lauf schliesst die
                    // Vormerkung als „bereits versendet". Kein Eintrag unter fehler,
                    // sonst zaehlte die Meldung dieselbe Person doppelt.
                    try {
                        $offen = $applicant->contractSendReservations()->offen()->first();
                        if ($offen) {
                            $this->reservations->abschliessen($offen, 'Direkt versendet.');
                        }
                    } catch (\Throwable $e) {
                        try {
                            \Illuminate\Support\Facades\Log::warning('[ContractSendRun] Vormerkung nach Direktversand nicht abgeschlossen: ' . $e->getMessage(), ['applicant_id' => $applicant->id]);
                        } catch (\Throwable) {
                            // Protokoll darf den erfolgreichen Versand nicht kippen.
                        }
                    }
                    if (ContractDispatchService::isPortalFailure($result)) {
                        $ergebnis->fehler[$applicant->id] = $result['message'] ?? 'Portal-WA fehlgeschlagen.';
                    }
                } elseif ($result['status'] === 'error') {
                    $ergebnis->fehler[$applicant->id] = $result['message'] ?? 'Versand fehlgeschlagen.';
                }
            } catch (\Throwable $e) {
                $ergebnis->fehler[$applicant->id] = 'Fehler: ' . $e->getMessage();
            }
        }

        return $ergebnis;
    }
}
