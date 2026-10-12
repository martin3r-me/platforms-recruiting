<?php

namespace Platform\Recruiting\Services\Zas\Dispo\Jobs;

use Platform\Integrations\Models\IntegrationsWhatsAppTemplate;
use Platform\Recruiting\Models\RecDispoEvent;
use Platform\Recruiting\Models\RecDispoJobOffer;
use Platform\Recruiting\Models\RecDispoJobOfferShift;
use Platform\Recruiting\Models\RecDispoJobWave;
use Platform\Recruiting\Services\Zas\Dispo\DispoChannelResolver;

/**
 * Versand der Jobangebote einer Welle (Muster DispoInfoSender): je Empfaenger
 * ein Angebot samt Schichten, {{name}} nur wenn der Body ihn traegt, Token als
 * URL-Button-Parameter. Ein Angebot mit send_error bleibt bestehen — die Person
 * gilt als angeschrieben (Unique request+anchor). Wirft nie.
 */
class DispoJobOfferSender
{
    /**
     * @param list<array{anchor:int, employee_id:int, phone:string, first_name:string, portal_token:string, shift_ids:list<int>}> $recipients
     * @return array{ok:bool, message:?string, sent:int, failed:list<array{anchor:int, error:string}>}
     */
    public function send(RecDispoJobWave $wave, array $recipients, int $templateId): array
    {
        $template = IntegrationsWhatsAppTemplate::find($templateId);
        if (!$template || $template->status !== 'APPROVED') {
            return ['ok' => false, 'message' => 'Vorlage nicht gefunden oder nicht freigegeben (Disposition → Einstellungen).', 'sent' => 0, 'failed' => []];
        }

        $request = $wave->request;
        $channel = app(DispoChannelResolver::class)->resolveForEvent(new RecDispoEvent(['filial_nr' => $request->filial_nr]));
        if (!$channel) {
            return ['ok' => false, 'message' => 'Kein WhatsApp-Kanal auflösbar (Filiale/Standard prüfen).', 'sent' => 0, 'failed' => []];
        }

        $usesName = self::bodyUsesName($template);
        $service = app(\Platform\Crm\Services\Comms\WhatsAppMetaService::class);
        try {
            $sender = auth()->user();
        } catch (\Throwable) {
            $sender = null;
        }
        $sent = 0;
        $failed = [];

        foreach ($recipients as $r) {
            $exists = RecDispoJobOffer::query()
                ->where('request_id', $request->id)
                ->where('anchor_employee_id', $r['anchor'])
                ->exists();
            if ($exists) {
                $failed[] = ['anchor' => (int) $r['anchor'], 'error' => 'in dieser Anfrage schon angeschrieben'];
                continue;
            }

            $offer = RecDispoJobOffer::create([
                'wave_id' => $wave->id, 'request_id' => $request->id,
                'rec_employee_id' => $r['employee_id'], 'anchor_employee_id' => $r['anchor'],
            ]);
            foreach ($r['shift_ids'] as $shiftId) {
                RecDispoJobOfferShift::create(['offer_id' => $offer->id, 'shift_id' => $shiftId]);
            }

            $components = [];
            if ($usesName) {
                $components[] = [
                    'type'       => 'body',
                    'parameters' => [[
                        'type'           => 'text',
                        'parameter_name' => 'name',
                        'text'           => trim((string) $r['first_name']) !== '' ? trim((string) $r['first_name']) : 'zusammen',
                    ]],
                ];
            }
            $components[] = [
                'type'       => 'button',
                'sub_type'   => 'url',
                'index'      => 0,
                'parameters' => [['type' => 'text', 'text' => $r['portal_token']]],
            ];

            try {
                $message = $service->sendTemplate(
                    channel:      $channel,
                    to:           (string) $r['phone'],
                    templateName: $template->name,
                    components:   $components,
                    languageCode: $template->language ?? 'de',
                    sender:       $sender,
                );
                if (($message->status ?? null) === 'failed') {
                    $error = (string) ($message->meta_payload['error']['message'] ?? 'Meta: failed');
                    $offer->update(['message_id' => $message->id ?? null, 'send_error' => mb_substr($error, 0, 500)]);
                    $failed[] = ['anchor' => (int) $r['anchor'], 'error' => $error];
                    continue;
                }
                $offer->update(['message_id' => $message->id ?? null, 'sent_at' => now()]);
                $sent++;
            } catch (\Throwable $e) {
                $offer->update(['send_error' => mb_substr($e->getMessage(), 0, 500)]);
                $failed[] = ['anchor' => (int) $r['anchor'], 'error' => $e->getMessage()];
            }
        }

        return ['ok' => true, 'message' => null, 'sent' => $sent, 'failed' => $failed];
    }

    /** Traegt der BODY die Named-Variable {{name}}? (Muster DispoInfoSender). */
    private static function bodyUsesName(IntegrationsWhatsAppTemplate $template): bool
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
