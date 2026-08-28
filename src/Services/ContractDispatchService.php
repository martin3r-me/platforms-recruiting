<?php

namespace Platform\Recruiting\Services;

use Platform\Recruiting\Models\RecApplicant;
use Platform\Recruiting\Models\RecContractTemplate;
use Platform\Recruiting\Models\RecEmployee;

/**
 * Pro-Bewerber-Versand-Sequenz "Portallink & Verträge" — extrahiert aus
 * dem Bulk-Button der Nachbereitung, genutzt von Bulk UND HR-Desk-Karte.
 *
 * Gepinnte Fakten (Spec F1/F2/F4):
 *  - SendContractsService::send() ruft selbst checkAutoPilotCompletion();
 *    dessen Carve-out führt die Completion-Hooks (inkl. Mitarbeiter-
 *    Erzeugung, idempotentes createOrUpdate) auch bei auto_pilot=false
 *    aus — die Employee-Anlage passiert also IM Send-Aufruf, der Desk
 *    braucht dafür kein approveCase vorab.
 *  - skipNotification=true unterdrückt NUR die bewerberseitige Vertrags-
 *    Portal-WA; die WA-Menge dieses Services ist damit identisch zum
 *    historischen Bulk: genau EINE Nachricht (Employee-Portal-WA).
 *  - hasAnyContractSent() ist der Idempotenz-Anker: bereits belieferte
 *    Bewerber werden komplett übersprungen (Selbstheilung, z.B. wenn
 *    nach erfolgreichem Versand der Fall-Abschluss abbrach).
 */
class ContractDispatchService
{
    /**
     * Grund fuer eine ausgebliebene Portal-WA, wenn die Mitarbeiter-Anlage
     * nicht durchlief (F1-Edge). Frueher blieb message hier null — und der
     * Bulk zaehlte deshalb keinen Fehler.
     */
    public const NO_EMPLOYEE_MESSAGE = 'Kein Mitarbeiter-Datensatz vorhanden — Portal-WA nicht gesendet (Grund im RecAutoPilotLog, Typ employee_create_failed).';

    /**
     * "Vertraege sind raus, die Portal-WA aber nicht" — der Zustand, in dem
     * der Bewerber trotz Versand keine Nachricht bekommen hat.
     *
     * Eine Quelle fuer beide Aufrufer: der Bulk las das Ergebnis frueher
     * ueber message !== null und uebersah damit genau den Fall ohne
     * Fehlertext (fehlender Mitarbeiter) — gruener Flash, stummer Bewerber.
     *
     * status=error und status=skipped_already_sent sind NICHT gemeint; die
     * haben bei beiden Aufrufern eigene Zweige.
     *
     * @param array{status: string, portal_sent: bool, message: ?string} $result
     */
    public static function isPortalFailure(array $result): bool
    {
        return ($result['status'] ?? null) === 'sent'
            && !($result['portal_sent'] ?? false);
    }

    public function sendForApplicant(
        RecApplicant $applicant,
        ?int $userId,
        ?array $contractFields,
        ?RecContractTemplate $defaultTemplate
    ): array {
        if ($applicant->hasAnyContractSent()) {
            return ['status' => 'skipped_already_sent', 'portal_sent' => false, 'message' => null];
        }

        // AV-default zuweisen falls leer — identisch zu
        // assignDefaultTemplateIfMissing() der Nachbereitung.
        if (!$applicant->contract_template_id && $defaultTemplate) {
            $applicant->contract_template_id = $defaultTemplate->id;
            $applicant->save();
        }

        try {
            // skipNotification=true: Vertrags-WA wird unterdrueckt — der
            // MA bekommt stattdessen nur die Portal-WA (das Portal listet
            // die Vertraege ohnehin auf). Gleiche Entscheidung wie im Bulk.
            app(SendContractsService::class)->send($applicant, $userId, $contractFields, true);
        } catch (\Throwable $e) {
            return ['status' => 'error', 'portal_sent' => false, 'message' => $e->getMessage()];
        }

        // Phase-Hook hat den MA angelegt (F1) — jetzt Portal-Link nachschieben.
        // Eigener try/catch: Verträge sind zu diesem Zeitpunkt RAUS — ein
        // Portal-Fehler darf weder den Status kippen noch (im Bulk) die
        // restlichen Bewerber blockieren (alte Bulk-Semantik: Fehler pro
        // Bewerber gezählt, Schleife läuft weiter).
        //
        // sendPortalNotification() wirft NICHT — es hat ein eigenes
        // Catch-All und liefert immer array{ok: bool, message: ?string}
        // zurueck (RecEmployee.php, ~Z. 406). Der try/catch hier ist daher
        // nur noch ein Guertel fuer einen etwaigen kuenftigen throw; die
        // eigentliche Erfolgs-/Fehler-Auswertung MUSS ueber den Rueckgabewert
        // laufen, sonst waere portal_sent auch bei fehlgeschlagenem WA-Versand
        // true. Der historische Bulk zaehlte einen solchen Versand faelschlich
        // als Erfolg; seit 08/2026 lesen beide Aufrufer das Ergebnis ueber
        // isPortalFailure() und damit gleich — die WA-Menge selbst bleibt
        // unveraendert (weiterhin genau ein Versandversuch).
        $portalSent = false;
        $portalError = null;
        try {
            $employee = RecEmployee::where('rec_applicant_id', $applicant->id)->first();
            if ($employee) {
                $portalResult = $employee->sendPortalNotification();
                $portalSent = (bool) ($portalResult['ok'] ?? false);
                if (!$portalSent) {
                    $portalError = $portalResult['message'] ?? 'Portal-WA fehlgeschlagen (Details im RecAutoPilotLog).';
                }
            } else {
                // Kein Mitarbeiter = kein Empfaenger fuer die Portal-WA, also
                // hat der Bewerber gar keine Nachricht bekommen. Das MUSS
                // einen Grund tragen, sonst liest es sich fuer den Aufrufer
                // wie "nichts zu melden".
                $portalError = self::NO_EMPLOYEE_MESSAGE;
            }
        } catch (\Throwable $e) {
            $portalError = $e->getMessage();
        }

        return ['status' => 'sent', 'portal_sent' => $portalSent, 'message' => $portalError];
    }
}
