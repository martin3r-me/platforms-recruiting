<?php

namespace Platform\Recruiting\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Platform\Recruiting\Models\RecApplicant;
use Platform\Recruiting\Models\RecContract;
use Platform\Recruiting\Models\RecContractTemplate;

/**
 * Generates and "sends" the applicant's contract bundle:
 *  - the chosen Arbeitsvertrag-Variante (e.g. AV-060 = 0,60€ Zuschlag)
 *  - the IFSG (Infektionsschutzgesetz) — auto-attached to every AV-*
 *
 * "Versenden" here means: create the RecContract rows + set sent_at, which
 * (a) marks the contracts as dispatched in our model and (b) satisfies the
 * 'contract_sent' phase-completion check so the applicant advances to the
 * "Vertrag unterschreiben" phase. The actual delivery of the signing link
 * to the applicant runs through the existing AutoPilot phase-entry template
 * (= Phase 5 "Vertrag" sends WA "Hier sind deine Verträge zum
 * Unterschreiben").
 *
 * This service is intentionally callable from anywhere — UI, MCP tool,
 * console command — so we can test the flow before the SL-Nachbereitungs-UI
 * is built.
 */
class SendContractsService
{
    /**
     * @param  array{vertragsbeginn?: ?string, vertragsende?: ?string}|null  $contractFields
     *         Optional extra-field values, die nach Vertragsanlage auf AV + IFSG
     *         geschrieben werden. `vertragsbeginn` als YYYY-MM-DD; `vertragsende`
     *         leer → wird via RecContract::resolveContractDates auto-berechnet.
     *
     * @return array{
     *   av_contract: RecContract,
     *   ifsg_contract: ?RecContract,
     *   created: int,
     *   reused: int
     * }
     *
     * @throws \RuntimeException if applicant has no contract_template_id set
     *                           or the chosen template is invalid
     */
    public function send(RecApplicant $applicant, ?int $createdByUserId = null, ?array $contractFields = null): array
    {
        if (!$applicant->contract_template_id) {
            throw new \RuntimeException(
                "Bewerber #{$applicant->id} hat keine contract_template_id gesetzt — "
                . "bitte erst Vertragsvorlage in der Schulungsnachbereitung auswählen."
            );
        }

        $avTemplate = RecContractTemplate::where('team_id', $applicant->team_id)
            ->where('id', $applicant->contract_template_id)
            ->where('is_active', true)
            ->first();

        if (!$avTemplate) {
            throw new \RuntimeException(
                "Gewählte Vertragsvorlage #{$applicant->contract_template_id} existiert nicht "
                . "oder ist inaktiv im Team #{$applicant->team_id}."
            );
        }

        $ifsgTemplate = RecContractTemplate::where('team_id', $applicant->team_id)
            ->where('code', 'IFSG')
            ->where('is_active', true)
            ->first();

        $resolvedDates = RecContract::resolveContractDates(
            $contractFields['vertragsbeginn'] ?? null,
            $contractFields['vertragsende'] ?? null,
        );

        return DB::transaction(function () use ($applicant, $avTemplate, $ifsgTemplate, $createdByUserId, $resolvedDates) {
            $created = 0;
            $reused = 0;

            // 1) AV-Vertrag — falls schon einer da der nicht cancelled ist und denselben Template referenziert,
            //    nutzen wir den (idempotent). Sonst neu anlegen.
            $avContract = $applicant->contracts()
                ->whereNotIn('status', ['cancelled'])
                ->where('rec_contract_template_id', $avTemplate->id)
                ->first();

            if ($avContract) {
                $reused++;
            } else {
                $avContract = RecContract::create([
                    'rec_applicant_id' => $applicant->id,
                    'rec_contract_template_id' => $avTemplate->id,
                    'team_id' => $applicant->team_id,
                    'personalized_content' => $avTemplate->personalizeContent($applicant),
                    'status' => 'pending',
                    'created_by_user_id' => $createdByUserId,
                ]);
                $created++;
            }

            // 2) IFSG-Vertrag automatisch dazu falls Vorlage da und noch keiner aktiv
            $ifsgContract = null;
            if ($ifsgTemplate) {
                $ifsgContract = $applicant->contracts()
                    ->whereNotIn('status', ['cancelled'])
                    ->where('rec_contract_template_id', $ifsgTemplate->id)
                    ->first();

                if ($ifsgContract) {
                    $reused++;
                } else {
                    $ifsgContract = RecContract::create([
                        'rec_applicant_id' => $applicant->id,
                        'rec_contract_template_id' => $ifsgTemplate->id,
                        'team_id' => $applicant->team_id,
                        'personalized_content' => $ifsgTemplate->personalizeContent($applicant),
                        'status' => 'pending',
                        'created_by_user_id' => $createdByUserId,
                    ]);
                    $created++;
                }
            } else {
                Log::warning('[SendContractsService] No active IFSG template for team', [
                    'team_id' => $applicant->team_id,
                    'applicant_id' => $applicant->id,
                ]);
            }

            // 2b) Optionaler Zusatzvertrag (typisch AT-* fuer Aufenthaltstitel-
            //     bezogene Doks) — HR weist ihn auf dem HR-Schreibtisch zu fuer
            //     nicht-EU-Buerger. Idempotent: existierender Vertrag mit gleichem
            //     Template wird reused. Wenn HR den Zusatzvertrag entfernt
            //     (additional_contract_template_id=null) wird hier nichts
            //     versendet — bestehende Vertraege bleiben aber unangetastet.
            $additionalContract = null;
            $additionalTemplate = $applicant->legalStatus?->additionalContractTemplate;
            if ($additionalTemplate && $additionalTemplate->is_active) {
                $additionalContract = $applicant->contracts()
                    ->whereNotIn('status', ['cancelled'])
                    ->where('rec_contract_template_id', $additionalTemplate->id)
                    ->first();

                if ($additionalContract) {
                    $reused++;
                } else {
                    $additionalContract = RecContract::create([
                        'rec_applicant_id'         => $applicant->id,
                        'rec_contract_template_id' => $additionalTemplate->id,
                        'team_id'                  => $applicant->team_id,
                        'personalized_content'     => $additionalTemplate->personalizeContent($applicant),
                        'status'                   => 'pending',
                        'created_by_user_id'       => $createdByUserId,
                    ]);
                    $created++;
                }
            }

            // 3) Vertragslaufzeit als Extra-Fields nur auf den AV-Vertrag
            //    schreiben — IFSG ist eine eigenständige Erklärung und hat
            //    semantisch nichts mit der AV-Laufzeit zu tun.
            if ($resolvedDates['vertragsbeginn'] || $resolvedDates['vertragsende']) {
                if ($resolvedDates['vertragsbeginn']) {
                    $avContract->setExtraField('vertragsbeginn', $resolvedDates['vertragsbeginn']);
                }
                if ($resolvedDates['vertragsende']) {
                    $avContract->setExtraField('vertragsende', $resolvedDates['vertragsende']);
                }
                if ($avContract->contractTemplate) {
                    $avContract->personalized_content = $avContract->contractTemplate
                        ->personalizeContent($applicant, $avContract);
                    $avContract->save();
                }
            }

            // 4) Beide Verträge als "verschickt" markieren — das löst die
            //    'contract_sent' Phase-Completion-Check aus.
            //    $nowSentCount zaehlt nur Vertraege die in DIESEM Run ihr
            //    sent_at neu bekommen — fuer die Idempotenz-Logik beim
            //    Notification-Versand unten.
            $now = now();
            $nowSentCount = 0;
            foreach (array_filter([$avContract, $ifsgContract, $additionalContract]) as $contract) {
                if (!$contract->sent_at) {
                    $contract->sent_at = $now;
                    if ($contract->status === 'pending') {
                        $contract->status = 'sent';
                    }
                    $contract->save();
                    $nowSentCount++;
                }
            }

            // 5) WhatsApp-Portal-Notification an den Bewerber. Nutzt das
            //    team-weite contract_wa_template_id-Setting aus den
            //    Bewerber-Einstellungen — gleiches Template wie wenn HR
            //    im Bewerber-Show "Portal per WhatsApp senden" klickt.
            //    Ergebnis wird nicht hart ausgewertet; Vertragsversand
            //    soll auch dann durchlaufen wenn die WA-Konfig (z.B. ein
            //    abgelaufenes Template) gerade nicht greift — HR sieht's
            //    dann im RecAutoPilotLog.
            //    Idempotenz: nur senden wenn in diesem Run wirklich
            //    mindestens ein Vertrag JETZT erstmalig als gesendet
            //    markiert wurde — verhindert doppelte Notification beim
            //    Bulk-Re-Send fuer schon abgeschlossene Bewerber.
            if ($nowSentCount > 0) {
                $applicant->refresh();
                $applicant->sendContractPortalNotification();
            }

            // 6) AutoPilot-Phase-Check: Phase wandert nach "Vertrag unterschreiben"
            $applicant->checkAutoPilotCompletion();

            return [
                'av_contract'         => $avContract->fresh(),
                'ifsg_contract'       => $ifsgContract?->fresh(),
                'additional_contract' => $additionalContract?->fresh(),
                'created'             => $created,
                'reused'              => $reused,
            ];
        });
    }
}
