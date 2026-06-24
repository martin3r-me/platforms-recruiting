<?php

namespace Platform\Recruiting\Services\Comms;

use Platform\Crm\Models\CommsChannel;
use Platform\Crm\Services\Comms\WhatsAppMetaService;
use Platform\Integrations\Models\IntegrationsWhatsAppAccount;
use Platform\Integrations\Models\IntegrationsWhatsAppTemplate;
use Platform\Recruiting\Models\RecApplicantSettings;

/**
 * Versendet das konfigurierte Eingangsbestätigungs-Template
 * (`comms_holding_template_id`, z.B. „wir kümmern uns / melden uns") an eine
 * oder mehrere Telefonnummern — subjekt-unabhängig (Bewerber, Mitarbeiter,
 * auch verwaiste Threads), weil nur Nummer + Vorname benötigt werden.
 *
 * Template-Versand ist bei Meta unabhängig vom 24h-Fenster erlaubt; daher kann
 * die Bestätigung an beliebige markierte Konversationen rausgehen.
 */
final class HoldingTemplateSender
{
    public function __construct(private readonly WhatsAppMetaService $whatsApp) {}

    /**
     * @param iterable<array{phone: ?string, first_name: ?string}> $recipients
     * @return array{sent: int, failed: int, skipped: int, error: ?string, template: ?string}
     */
    public function sendToMany(int $teamId, iterable $recipients, string $settingsKey = 'comms_holding_template_id'): array
    {
        $config = $this->resolveConfig($teamId, $settingsKey);
        if ($config['error'] !== null) {
            return ['sent' => 0, 'failed' => 0, 'skipped' => 0, 'error' => $config['error'], 'template' => null];
        }

        /** @var IntegrationsWhatsAppTemplate $template */
        $template = $config['template'];
        /** @var CommsChannel $channel */
        $channel = $config['channel'];

        $sent = 0;
        $failed = 0;
        $skipped = 0;

        foreach ($recipients as $recipient) {
            $phone = trim((string) ($recipient['phone'] ?? ''));
            if ($phone === '') {
                $skipped++;
                continue;
            }

            $firstName = (string) ($recipient['first_name'] ?? '');
            $components = HoldingTemplateComponents::build($template->components ?? [], $firstName);

            try {
                $this->whatsApp->sendTemplate(
                    channel: $channel,
                    to: $phone,
                    templateName: $template->name,
                    components: $components,
                    languageCode: $template->language ?? 'de',
                    sender: auth()->user(),
                );
                $sent++;
            } catch (\Throwable $e) {
                $failed++;
            }
        }

        return ['sent' => $sent, 'failed' => $failed, 'skipped' => $skipped, 'error' => null, 'template' => $template->name];
    }

    /** Einzelversand des konfigurierten Templates an eine Nummer (z.B. Auto-Reply). */
    public function sendOne(int $teamId, string $phone, string $firstName, string $settingsKey = 'comms_holding_template_id'): array
    {
        return $this->sendToMany($teamId, [['phone' => $phone, 'first_name' => $firstName]], $settingsKey);
    }

    /** Name des konfigurierten Templates oder null (für UI-Anzeige/Guard/Throttle). */
    public function configuredTemplateName(int $teamId, string $settingsKey = 'comms_holding_template_id'): ?string
    {
        $config = $this->resolveConfig($teamId, $settingsKey);
        return $config['error'] === null ? $config['template']->name : null;
    }

    /**
     * @return array{error: ?string, template: ?IntegrationsWhatsAppTemplate, channel: ?CommsChannel}
     */
    private function resolveConfig(int $teamId, string $settingsKey = 'comms_holding_template_id'): array
    {
        $fail = fn (string $msg) => ['error' => $msg, 'template' => null, 'channel' => null];

        $settings = RecApplicantSettings::getOrCreateForTeam($teamId);
        $templateId = $settings->getSetting($settingsKey);
        if (!$templateId) {
            return $fail('Kein Eingangsbestätigungs-Template konfiguriert (Einstellungen → Kommunikation).');
        }

        if (!class_exists(IntegrationsWhatsAppTemplate::class)) {
            return $fail('WhatsApp-Integration nicht verfügbar.');
        }

        $template = IntegrationsWhatsAppTemplate::find($templateId);
        if (!$template || $template->status !== 'APPROVED') {
            return $fail('Template nicht gefunden oder bei Meta nicht genehmigt.');
        }

        $accountId = $settings->getSetting('auto_pilot_wa_account_id') ?: $template->whatsapp_account_id;
        if (!$accountId || !class_exists(IntegrationsWhatsAppAccount::class)) {
            return $fail('Kein WhatsApp-Account konfiguriert.');
        }

        $account = IntegrationsWhatsAppAccount::find($accountId);
        if (!$account || !$account->active) {
            return $fail('WhatsApp-Account nicht aktiv.');
        }

        $channel = CommsChannel::where('type', 'whatsapp')
            ->where('is_active', true)
            ->where('sender_identifier', $account->phone_number)
            ->first();

        if (!$channel) {
            return $fail('Kein aktiver WhatsApp-Kanal für den Account.');
        }

        return ['error' => null, 'template' => $template, 'channel' => $channel];
    }
}
