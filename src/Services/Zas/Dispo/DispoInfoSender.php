<?php

namespace Platform\Recruiting\Services\Zas\Dispo;

use Platform\Recruiting\Models\RecDispoEvent;

/**
 * Versand der Crew-Info (Kunde 03.09.): "es gibt neue Infos zu deinem Einsatz"
 * mit URL-Button auf die Einsatz-Seite — nach Anhang-/Hinweis-Aenderungen,
 * gefiltert nach Qualifikation. Gleiche Mechanik wie der Bestaetigungs-
 * Versand: Kanal der Filiale, Template aus den Dispo-Einstellungen
 * (dispo_info_template_id), {{name}} als Named-Parameter nur wenn der Body
 * ihn traegt, Token als Button-Parameter. Liefert sent/failed, wirft nie.
 */
class DispoInfoSender
{
    /**
     * @param list<array{employee_id:int, phone:string, first_name:string, portal_token:string}> $recipients
     * @return array{ok:bool, message:?string, sent:int, failed:list<array{employee_id:int, error:string}>}
     */
    public function send(RecDispoEvent $event, array $recipients, int $templateId): array
    {
        $template = \Platform\Integrations\Models\IntegrationsWhatsAppTemplate::find($templateId);
        if (!$template || $template->status !== 'APPROVED') {
            return ['ok' => false, 'message' => 'Info-Template nicht gefunden oder nicht freigegeben (Disposition → Einstellungen).', 'sent' => 0, 'failed' => []];
        }

        $channel = app(DispoChannelResolver::class)->resolveForEvent($event);
        if (!$channel) {
            return ['ok' => false, 'message' => 'Kein WhatsApp-Kanal aufloesbar (Filiale/Standard pruefen).', 'sent' => 0, 'failed' => []];
        }

        $usesName = self::bodyUsesName($template);
        $service = app(\Platform\Crm\Services\Comms\WhatsAppMetaService::class);
        // Absender tolerant aufloesen — Capsule-Tests haben kein Auth-System.
        try {
            $sender = auth()->user();
        } catch (\Throwable) {
            $sender = null;
        }
        $sent = 0;
        $failed = [];

        foreach ($recipients as $recipient) {
            $components = [];
            if ($usesName) {
                $components[] = [
                    'type'       => 'body',
                    'parameters' => [[
                        'type'           => 'text',
                        'parameter_name' => 'name',
                        'text'           => trim($recipient['first_name']) !== '' ? trim($recipient['first_name']) : 'zusammen',
                    ]],
                ];
            }
            $components[] = [
                'type'       => 'button',
                'sub_type'   => 'url',
                'index'      => 0,
                'parameters' => [['type' => 'text', 'text' => $recipient['portal_token']]],
            ];

            try {
                $message = $service->sendTemplate(
                    channel:      $channel,
                    to:           (string) $recipient['phone'],
                    templateName: $template->name,
                    components:   $components,
                    languageCode: $template->language ?? 'de',
                    sender:       $sender,
                );
                if (($message->status ?? null) === 'failed') {
                    $failed[] = ['employee_id' => $recipient['employee_id'], 'error' => (string) ($message->meta_payload['error']['message'] ?? 'Meta: failed')];
                    continue;
                }
                $sent++;
            } catch (\Throwable $e) {
                $failed[] = ['employee_id' => $recipient['employee_id'], 'error' => $e->getMessage()];
            }
        }

        return ['ok' => true, 'message' => null, 'sent' => $sent, 'failed' => $failed];
    }

    /** Traegt der BODY die Named-Variable {{name}}? (Muster DispoChatTemplateSender). */
    private static function bodyUsesName(\Platform\Integrations\Models\IntegrationsWhatsAppTemplate $template): bool
    {
        foreach ((array) ($template->components ?? []) as $component) {
            if (strtoupper((string) ($component['type'] ?? '')) === 'BODY'
                && str_contains((string) ($component['text'] ?? ''), '{{name}}')) {
                return true;
            }
        }

        return false;
    }
}
