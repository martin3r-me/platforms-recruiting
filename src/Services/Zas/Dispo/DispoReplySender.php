<?php

namespace Platform\Recruiting\Services\Zas\Dispo;

use Platform\Crm\Models\CommsChannel;
use Platform\Crm\Models\CommsWhatsAppThread;

/**
 * Freitext-Antwort ueber den Kanal DES Threads (die Filial-Nummer, auf der er
 * liegt). Prueft das 24h-Fenster. Liefert ok/error statt zu werfen — der
 * Aufrufer entscheidet, ob der Eingabetext stehen bleibt. Genutzt von der
 * Kommunikation und dem VA-Chat-Panel (Runde 4, #1).
 */
class DispoReplySender
{
    /** @return array{ok:bool, error:?string} */
    public function send(CommsWhatsAppThread $thread, string $text, mixed $sender): array
    {
        $text = trim($text);
        if ($text === '') {
            return ['ok' => false, 'error' => 'Bitte eine Nachricht eingeben.'];
        }
        $channel = CommsChannel::find($thread->comms_channel_id);
        if ($channel === null) {
            return ['ok' => false, 'error' => 'Kanal des Threads nicht gefunden.'];
        }
        if (!DispoTimeCalculator::isReplyWindowOpen($thread->last_inbound_at, now())) {
            return ['ok' => false, 'error' => '24h-Fenster abgelaufen — Erinnerungen laufen als Vorlage über die Veranstaltung.'];
        }

        try {
            $message = app(\Platform\Crm\Services\Comms\WhatsAppMetaService::class)->sendText(
                channel: $channel,
                to:      (string) $thread->remote_phone_number,
                message: $text,
                sender:  $sender,
            );
            if (($message->status ?? null) === 'failed') {
                return ['ok' => false, 'error' => 'Meta hat den Versand abgelehnt: ' . (string) ($message->meta_payload['error']['message'] ?? 'unbekannter Grund')];
            }

            return ['ok' => true, 'error' => null];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => 'Senden fehlgeschlagen: ' . $e->getMessage()];
        }
    }
}
