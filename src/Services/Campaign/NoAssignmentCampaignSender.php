<?php

namespace Platform\Recruiting\Services\Campaign;

use Illuminate\Support\Facades\Log;
use Platform\Crm\Services\Comms\WhatsAppMetaService;
use Platform\Recruiting\Models\RecApplicant;
use Platform\Recruiting\Models\RecAutoPilotLog;
use Platform\Recruiting\Services\Comms\HoldingTemplateComponents;
use Platform\Recruiting\Services\Comms\HoldingTemplateSender;
use Platform\Recruiting\Support\WhatsAppTemplateBodyVariables;
use Platform\Recruiting\Support\WhatsAppTemplateUrlButtons;

/**
 * Sammelversand „ohne Einsatz" (Clara, 14.09.2026): EIN Template an EINE
 * Person, hoechstens mit dem Vornamen als Variable — die Nachfrage an
 * Schulungsteilnehmer, die nie in einen Einsatz gekommen sind („moechtest du
 * starten oder nicht?"). Die Antwort kommt als normale WhatsApp zurueck und
 * landet ueber den Thread-Kontext an der Bewerbung.
 *
 * SCHLANKE SCHWESTER von NewDatesCampaignSender, keine Erweiterung: dort ist
 * der dynamische URL-Button die Sendebedingung (ohne Link waere die Kampagne
 * Spam), hier ist er der AUSSCHLUSS — dieses Template traegt keinen Link, und
 * ein dynamischer Button ohne Parameter geht bei Meta entweder in den Fehler
 * oder, schlimmer, ins Leere. Gemeinsam bleiben Aufloesung
 * (HoldingTemplateSender::resolveTemplate), Body-Bau
 * (HoldingTemplateComponents) und der Fremd-Variablen-Guard.
 *
 * Was dieser Sender NICHT tut: den Auto-Piloten anfassen, Wartelisten
 * schliessen, Phasen bewegen. Es ist eine einzelne Nachricht an Menschen, die
 * laengst Mitarbeiter sind.
 */
class NoAssignmentCampaignSender
{
    public const STATUS_SENT = 'sent';
    public const STATUS_NO_PHONE = 'no_phone';
    public const STATUS_NOT_CONFIGURED = 'not_configured';
    public const STATUS_TEMPLATE_WITH_FOREIGN_VARS = 'template_with_foreign_vars';
    public const STATUS_TEMPLATE_WITH_DYNAMIC_BUTTON = 'template_with_dynamic_button';
    public const STATUS_FAILED = 'failed';

    /** Passt in rec_auto_pilot_logs.type (string(30)). */
    public const LOG_TYPE = 'campaign_no_assignment';

    /** @return array{status:string, error:?string} */
    public function send(RecApplicant $applicant, int $templateId, int $interviewId, string $campaignUuid, ?int $sentByUserId): array
    {
        $phone = $applicant->primaryContactPhone();
        if ($phone === null) {
            return ['status' => self::STATUS_NO_PHONE, 'error' => 'Keine Telefonnummer am Kontakt.'];
        }

        $target = app(HoldingTemplateSender::class)->resolveTemplate((int) $applicant->team_id, $templateId);
        if ($target['error'] !== null) {
            return ['status' => self::STATUS_NOT_CONFIGURED, 'error' => $target['error']];
        }
        $template = $target['template'];
        $components = $template->components ?? [];

        // Fremd-Variablen-Guard (Muster NewDatesCampaignSender): jede
        // Body-Variable ausser dem Vornamen wuerde
        // HoldingTemplateComponents::build() mit dem MUSTER-Text aus dem
        // Meta-Beispiel fuellen — erfolgreich, ohne Fehler, ohne Logzeile. '1'
        // ist der Fallback-Variablenname mancher Meta-Editoren fuer denselben
        // Vornamen-Slot.
        $foreignVars = array_values(array_filter(
            WhatsAppTemplateBodyVariables::names($components),
            fn (string $name): bool => !in_array(strtolower($name), ['name', 'vorname', '1'], true),
        ));
        if ($foreignVars !== []) {
            return [
                'status' => self::STATUS_TEMPLATE_WITH_FOREIGN_VARS,
                'error' => 'Template „' . $template->name . '“ hat Body-Variablen außer dem Vornamen (' . implode(', ', $foreignVars) . ') — die würden mit Meta-Beispieltext gefüllt.',
            ];
        }

        // Statische Buttons sind in Ordnung (sie tragen keinen Parameter), ein
        // dynamischer nicht: dieser Sendepfad hat keinen Wert dafuer.
        if (WhatsAppTemplateUrlButtons::dynamicIndexes($components) !== []) {
            return [
                'status' => self::STATUS_TEMPLATE_WITH_DYNAMIC_BUTTON,
                'error' => 'Template „' . $template->name . '“ hat einen URL-Button mit Variable — dieser Versand kennt keinen Link dafür.',
            ];
        }

        $sendComponents = HoldingTemplateComponents::build($components, $this->firstName($applicant));
        if (HoldingTemplateComponents::hasEmptyRequiredParam($sendComponents)) {
            return ['status' => self::STATUS_FAILED, 'error' => 'Leerer Pflicht-Parameter im Body (meist der Vorname).'];
        }

        try {
            $message = app(WhatsAppMetaService::class)->sendTemplate(
                channel: $target['channel'],
                to: $phone,
                templateName: (string) $template->name,
                components: $sendComponents,
                languageCode: (string) ($template->language ?? 'de'),
            );
        } catch (\Throwable $e) {
            $this->log($applicant, 'error', 'Sammelversand „ohne Einsatz“: Versand fehlgeschlagen — ' . $e->getMessage(), [
                'campaign' => $campaignUuid, 'template' => (string) $template->name, 'interview_id' => $interviewId,
            ]);

            return ['status' => self::STATUS_FAILED, 'error' => $e->getMessage()];
        }

        // Ab hier ist die WhatsApp RAUS — Buchhaltung darf den Erfolg nicht
        // mehr kippen (Muster RecApplicant::sendBookingLinkWhatsApp).
        try {
            if ($thread = $message->thread ?? null) {
                $thread->addContext($applicant->getMorphClass(), $applicant->id, 'campaign');
            }
        } catch (\Throwable $e) {
            Log::warning('[NoAssignmentCampaign] Thread-Kontext fehlgeschlagen (WhatsApp ist raus): ' . $e->getMessage(), ['applicant_id' => $applicant->id]);
        }

        $this->log($applicant, self::LOG_TYPE, 'Sammelversand „ohne Einsatz“ gesendet (Template: ' . $template->name . ').', [
            'campaign' => $campaignUuid,
            'template' => (string) $template->name,
            'interview_id' => $interviewId,
            'sent_by' => $sentByUserId,
        ]);

        return ['status' => self::STATUS_SENT, 'error' => null];
    }

    private function firstName(RecApplicant $applicant): string
    {
        $applicant->loadMissing('crmContactLinks.contact');
        $contact = $applicant->crmContactLinks->sortBy('contact_id')->first()?->contact;
        $name = trim((string) ($contact?->first_name ?? ''));

        return $name !== '' ? $name : 'Bewerber/in';
    }

    private function log(RecApplicant $applicant, string $type, string $summary, array $details): void
    {
        try {
            $log = new RecAutoPilotLog([
                'rec_applicant_id' => $applicant->id,
                'type' => $type,
                'summary' => $summary,
                'details' => $details,
            ]);
            $log->created_at = now();
            $log->save();
        } catch (\Throwable $e) {
            Log::warning('[NoAssignmentCampaign] Log fehlgeschlagen: ' . $e->getMessage(), ['applicant_id' => $applicant->id, 'type' => $type]);
        }
    }
}
