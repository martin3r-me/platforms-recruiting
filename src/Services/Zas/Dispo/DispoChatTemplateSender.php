<?php

namespace Platform\Recruiting\Services\Zas\Dispo;

use Platform\Crm\Models\CommsChannel;
use Platform\Crm\Models\CommsWhatsAppThread;

/**
 * Versand der drei festen Dispo-Chat-Vorlagen (Kunde 01.09.) aus der
 * Kommunikation und dem VA-Chat-Panel: oeffnet das 24h-Fenster, wenn der
 * Mitarbeiter antwortet. AUSSCHLIESSLICH die in config recruiting.zas.
 * dispo_chat_templates hinterlegten Templates sind versendbar — der
 * key kommt vom Client und wird hier gegen die Liste aufgeloest.
 *
 * Variable {{name}} (Named-Parameter bei Meta) = Vorname des Mitarbeiters;
 * gesetzt nur, wenn der Template-Body sie enthaelt. Versand ueber den Kanal
 * DES Threads (Filial-Nummer). Liefert ok/error statt zu werfen (Muster
 * DispoReplySender).
 */
class DispoChatTemplateSender
{
    /** @return list<array{key:string, label:string, template:string}> */
    public static function options(): array
    {
        try {
            $raw = (array) config('recruiting.zas.dispo_chat_templates', []);
        } catch (\Throwable) {
            // Pure Unit-Kontexte (DispoTemplateLabelsTest) haben keinen Container —
            // dann gibt es schlicht keine Chat-Vorlagen.
            return [];
        }

        return array_values(array_filter(
            $raw,
            fn ($o) => isset($o['key'], $o['label'], $o['template'])
        ));
    }

    /** @return array{ok:bool, error:?string} */
    public function send(CommsWhatsAppThread $thread, string $key, string $firstName, mixed $sender): array
    {
        $option = collect(self::options())->firstWhere('key', $key);
        if ($option === null) {
            return ['ok' => false, 'error' => 'Unbekannte Vorlage.'];
        }

        $channel = CommsChannel::find($thread->comms_channel_id);
        if ($channel === null) {
            return ['ok' => false, 'error' => 'Kanal des Threads nicht gefunden.'];
        }

        $template = \Platform\Integrations\Models\IntegrationsWhatsAppTemplate::query()
            ->where('name', $option['template'])
            ->where('status', 'APPROVED')
            ->orderByDesc('id')
            ->first();
        if ($template === null) {
            return ['ok' => false, 'error' => "Vorlage '{$option['label']}' ist (noch) nicht freigegeben — bitte Meta-Status pruefen."];
        }

        // {{name}} nur befuellen, wenn der Body die Variable traegt — ein Template
        // ohne Variable bekommt keine components (Meta lehnt Ueberzaehliges ab).
        // Der Body steckt im components-JSON des Meta-Syncs (kein body-Feld am Modell).
        $components = [];
        if (self::bodyUsesName($template)) {
            if (trim($firstName) === '') {
                return ['ok' => false, 'error' => 'Kein Vorname am Mitarbeiter hinterlegt — Vorlage braucht {{name}}.'];
            }
            $components = [[
                'type'       => 'body',
                'parameters' => [[
                    'type'           => 'text',
                    'parameter_name' => 'name',
                    'text'           => trim($firstName),
                ]],
            ]];
        }

        try {
            $message = app(\Platform\Crm\Services\Comms\WhatsAppMetaService::class)->sendTemplate(
                channel:      $channel,
                to:           (string) $thread->remote_phone_number,
                templateName: $template->name,
                components:   $components,
                languageCode: $template->language ?? 'de',
                sender:       $sender,
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

    /** Traegt der BODY des Templates die Named-Variable {{name}}? (Muster DispoEscalateCommand). */
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
