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
 * Kampagne „Neue Termine“: EIN Template an EINEN Bewerber, der Personen-Token
 * im dynamischen URL-Button an Position 0.
 *
 * Muster TrainingCertificateWhatsAppDelivery: Template + Kanal werden ueber
 * app(HoldingTemplateSender::class)->resolveTemplate() zum Sendezeitpunkt
 * aufgeloest (lesend) — nicht in den Konstruktor injiziert, weil
 * HoldingTemplateSender final ist und ein typisiertes Konstruktor-Property
 * sich im Test nicht mit einer Attrappe belegen liesse (Ruling task-7). Den
 * Body baut HoldingTemplateComponents (Aufruf, keine Erweiterung), gesendet
 * wird direkt ueber WhatsAppMetaService::sendTemplate(), ebenfalls per app().
 * Der Guard „dynamischer Button an Position 0“ ist die SENDEBEDINGUNG — ohne
 * Variable im Button gaebe es keinen Link, und eine Kampagne ohne Link ist
 * Spam.
 *
 * Der Token ist derselbe fuer /form/ und /recruiting/interviews/ — welche
 * Seite sich oeffnet, entscheidet allein die Basis-URL im bei Meta genehmigten
 * Template. Der Sender kennt den Unterschied A/B nur fuers Log.
 */
class NewDatesCampaignSender
{
    public const STATUS_SENT = 'sent';
    public const STATUS_NO_PHONE = 'no_phone';
    public const STATUS_NOT_CONFIGURED = 'not_configured';
    public const STATUS_TEMPLATE_WITHOUT_URL_BUTTON = 'template_without_url_button';
    public const STATUS_TEMPLATE_WITH_FOREIGN_VARS = 'template_with_foreign_vars';
    public const STATUS_FAILED = 'failed';

    public const URL_BUTTON_INDEX = 0;
    public const LOG_TYPE = 'campaign_sent';

    private \Closure $tokenResolver;

    /**
     * @param \Closure(RecApplicant):string|null $tokenResolver Default: kanonischer
     *        Public-Token des Bewerbers (CorePublicFormLink). Injizierbar, damit
     *        Tests ohne Core-Tabellen laufen.
     */
    public function __construct(?\Closure $tokenResolver = null)
    {
        $this->tokenResolver = $tokenResolver
            ?? fn (RecApplicant $a): string => (string) $a->getOrCreatePublicFormLink()->token;
    }

    /**
     * @param string $segment CampaignSegment::TEMPLATE_FORM|TEMPLATE_BOOKING — nur fuers Log
     * @return array{status:string, error:?string}
     */
    public function send(RecApplicant $applicant, int $templateId, string $segment, string $campaignUuid, ?int $sentByUserId): array
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

        if (!WhatsAppTemplateUrlButtons::hasDynamicAt($components, self::URL_BUTTON_INDEX)) {
            return [
                'status' => self::STATUS_TEMPLATE_WITHOUT_URL_BUTTON,
                'error' => 'Template „' . $template->name . '“ hat keinen dynamischen URL-Button an Position 0 — ohne ihn kein Link.',
            ];
        }

        // Fremd-Variablen-Guard (Final-Review): jede Body-Variable ausser dem
        // Vornamen wuerde HoldingTemplateComponents::build() mit dem
        // MUSTER-Text aus dem Meta-Beispiel fuellen (`:45` in
        // WhatsAppTemplateBodyVariables) — erfolgreich, ohne Fehler, ohne
        // Logzeile. '1' ist der Fallback-Variablenname mancher Meta-Editoren
        // fuer denselben Vornamen-Slot.
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

        $token = ($this->tokenResolver)($applicant);
        if (trim($token) === '') {
            return ['status' => self::STATUS_FAILED, 'error' => 'Kein Public-Token für den Bewerber.'];
        }

        $sendComponents = HoldingTemplateComponents::build($components, $this->firstName($applicant));
        if (HoldingTemplateComponents::hasEmptyRequiredParam($sendComponents)) {
            return ['status' => self::STATUS_FAILED, 'error' => 'Leerer Pflicht-Parameter im Body (meist der Vorname).'];
        }
        $sendComponents[] = [
            'type' => 'button',
            'sub_type' => 'url',
            'index' => self::URL_BUTTON_INDEX,
            'parameters' => [['type' => 'text', 'text' => $token]],
        ];

        try {
            $message = app(WhatsAppMetaService::class)->sendTemplate(
                channel: $target['channel'],
                to: $phone,
                templateName: (string) $template->name,
                components: $sendComponents,
                languageCode: (string) ($template->language ?? 'de'),
            );
        } catch (\Throwable $e) {
            $this->log($applicant, 'error', 'Kampagne „Neue Termine“: Versand fehlgeschlagen — ' . $e->getMessage(), [
                'campaign' => $campaignUuid, 'template' => (string) $template->name, 'segment' => $segment,
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
            Log::warning('[NewDatesCampaign] Thread-Kontext fehlgeschlagen (WhatsApp ist raus): ' . $e->getMessage(), ['applicant_id' => $applicant->id]);
        }

        $this->log($applicant, self::LOG_TYPE, 'Kampagne „Neue Termine“ gesendet (Template ' . $segment . ': ' . $template->name . ').', [
            'campaign' => $campaignUuid,
            'template' => (string) $template->name,
            'segment' => $segment,
            'phase_id' => $applicant->rec_phase_id,
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
            Log::warning('[NewDatesCampaign] Log fehlgeschlagen: ' . $e->getMessage(), ['applicant_id' => $applicant->id, 'type' => $type]);
        }
    }
}
