<?php

namespace Platform\Recruiting\Services\Comms;

use Platform\Crm\Models\CommsChannel;
use Platform\Crm\Models\CommsWhatsAppThread;
use Platform\Crm\Services\Comms\WhatsAppMetaService;
use Platform\Integrations\Models\IntegrationsWhatsAppTemplate;
use Platform\Recruiting\Models\RecApplicant;

/**
 * Template-Versand aus der Kommunikation.
 *
 * Unterschied zu Applicant\Show::sendManualTemplate: dort wird der
 * Bewerber-Formular-Token an JEDES Template mit URL-Knopf gehaengt — auch an
 * eines, dessen URL gar keinen Platzhalter hat. Genau so landete der Token im
 * MA-Portal-Link (Fall Theo Wirtz). Hier entscheidet der Platzhalter.
 *
 * Liefert ok/error statt zu werfen (Muster: DispoReplySender).
 */
final class ApplicantTemplateSender
{
    /**
     * Braucht dieses Template den Formular-Token? Pure Entscheidung ohne
     * Laravel — damit unit-testbar.
     *
     * @param array<int, array<string, mixed>> $components Template-Komponenten von Meta
     */
    public static function needsFormToken(array $components): bool
    {
        foreach ($components as $component) {
            if (($component['type'] ?? '') !== 'BUTTONS') {
                continue;
            }
            foreach ($component['buttons'] ?? [] as $button) {
                if (($button['type'] ?? '') !== 'URL') {
                    continue;
                }
                if (str_contains((string) ($button['url'] ?? ''), '{{')) {
                    return true;
                }
            }
        }

        return false;
    }

    /** @return array{ok: bool, error: ?string} */
    public function send(
        CommsWhatsAppThread $thread,
        int $templateId,
        ?int $applicantId,
        mixed $sender,
    ): array {
        $template = IntegrationsWhatsAppTemplate::find($templateId);
        if (!$template || $template->status !== 'APPROVED') {
            return ['ok' => false, 'error' => 'Vorlage nicht gefunden oder nicht genehmigt.'];
        }

        $channel = CommsChannel::find($thread->comms_channel_id);
        if ($channel === null) {
            return ['ok' => false, 'error' => 'Kanal des Chats nicht gefunden.'];
        }

        $components = [];
        if (self::needsFormToken((array) ($template->components ?? []))) {
            $applicant = $applicantId ? RecApplicant::find($applicantId) : null;
            if ($applicant === null) {
                return ['ok' => false, 'error' => 'Diese Vorlage enthält einen Bewerber-Link — der Chat ist aber keinem Bewerber zugeordnet.'];
            }
            $publicUrl = $applicant->getPublicUrl();
            $formToken = basename(parse_url($publicUrl, PHP_URL_PATH));

            $components[] = [
                'type' => 'button',
                'sub_type' => 'url',
                'index' => 0,
                'parameters' => [['type' => 'text', 'text' => $formToken]],
            ];
        }

        try {
            $message = app(WhatsAppMetaService::class)->sendTemplate(
                channel: $channel,
                to: (string) $thread->remote_phone_number,
                templateName: $template->name,
                components: $components,
                languageCode: $template->language,
                sender: $sender,
            );

            if (($message->status ?? null) === 'failed') {
                return ['ok' => false, 'error' => 'Meta hat den Versand abgelehnt: '
                    . (string) ($message->meta_payload['error']['message'] ?? 'unbekannter Grund')];
            }

            return ['ok' => true, 'error' => null];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => 'Senden fehlgeschlagen: ' . $e->getMessage()];
        }
    }
}
