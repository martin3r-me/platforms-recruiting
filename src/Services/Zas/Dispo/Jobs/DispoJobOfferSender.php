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

        $variablen = self::bodyVariablen($template);
        $service = app(\Platform\Crm\Services\Comms\WhatsAppMetaService::class);
        try {
            $sender = auth()->user();
        } catch (\Throwable) {
            $sender = null;
        }
        $sent = 0;
        $failed = [];

        foreach ($recipients as $r) {
            $offer = null;
            try {
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
                if ($variablen !== []) {
                    $werte = [
                        'name'  => trim((string) $r['first_name']) !== '' ? trim((string) $r['first_name']) : 'zusammen',
                        // Dringend-Vorlage (Folie 1): fruehester angebotener Tag, „Sa, 31.10.“
                        'datum' => self::datumText((string) ($r['datum'] ?? '')),
                    ];
                    $components[] = [
                        'type'       => 'body',
                        'parameters' => array_map(fn ($v) => ['type' => 'text', 'parameter_name' => $v, 'text' => $werte[$v]], $variablen),
                    ];
                }
                $components[] = [
                    'type'       => 'button',
                    'sub_type'   => 'url',
                    'index'      => 0,
                    'parameters' => [['type' => 'text', 'text' => $r['portal_token']]],
                ];

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
                if ($offer !== null) {
                    try {
                        $offer->update(['send_error' => mb_substr($e->getMessage(), 0, 500)]);
                    } catch (\Throwable) {
                        // zweiter Fehler beim Stempeln: bewusst geschluckt
                    }
                }
                $failed[] = ['anchor' => (int) ($r['anchor'] ?? 0), 'error' => $e->getMessage()];
            }
        }

        return ['ok' => true, 'message' => null, 'sent' => $sent, 'failed' => $failed];
    }

    /**
     * Benannte Variablen im BODY, in Reihenfolge ihres Auftretens (Muster
     * DispoInfoSender). Bekannt sind {{name}} und {{datum}}; gefuellt wird nur,
     * was die Vorlage traegt — alte Vorlagen ohne Datum laufen unveraendert.
     *
     * @return list<string>
     */
    public static function bodyVariablen(IntegrationsWhatsAppTemplate $template): array
    {
        foreach ((array) ($template->components ?? []) as $component) {
            if (strtoupper((string) ($component['type'] ?? '')) !== 'BODY') {
                continue;
            }
            preg_match_all('/\{\{(name|datum)\}\}/', (string) ($component['text'] ?? ''), $m);

            return array_values(array_unique($m[1]));
        }

        return [];
    }

    /** „Sa, 31.10.“ aus Y-m-d; leer bleibt leer (Meta lehnt leere Parameter ab — dann steht „bald“). */
    public static function datumText(string $ymd): string
    {
        if ($ymd === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $ymd)) {
            return 'bald';
        }
        $c = \Carbon\Carbon::parse($ymd);
        $wd = ['So', 'Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa'];

        return $wd[$c->dayOfWeek] . ', ' . $c->format('d.m.');
    }
}
