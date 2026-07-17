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
        $portalSent = false;
        $employee = RecEmployee::where('rec_applicant_id', $applicant->id)->first();
        if ($employee) {
            $employee->sendPortalNotification();
            $portalSent = true;
        }

        return ['status' => 'sent', 'portal_sent' => $portalSent, 'message' => null];
    }
}
