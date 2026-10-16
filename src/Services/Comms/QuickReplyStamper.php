<?php

namespace Platform\Recruiting\Services\Comms;

use Platform\Crm\Models\CommsWhatsAppMessage;
use Platform\Crm\Models\CommsWhatsAppThread;
use Platform\Recruiting\Models\RecConversationHandled;

/**
 * Legt einen Chat als gelesen ab, wenn die Person nur einen vorgefertigten
 * Button geklickt hat (Spec 16.10.2026: „Ja“/„Nein“ auf t05 verarbeitet der
 * ReminderResponseHandler selbst, HR muss nichts tun).
 *
 * Bewusst ueber den eigenen Stempel statt is_unread im Core: der Core setzt
 * is_unread beim Eingang auf true, und „ungelesen schliessen“ muss den Chat
 * wieder oeffnen koennen — das geht, indem es den Stempel loescht. Schreibt
 * die Person danach Freitext, hebt ConversationHandledState den Stempel
 * von selbst auf.
 *
 * Im Zweifel bleibt der Chat offen (Review 16.10.2026, Befund 1). Gestempelt
 * wird nur, wenn
 *  - der Klick der JUENGSTE Eingang ist — Meta wiederholt Webhooks, und der
 *    Core feuert das Inbound-Event auch fuer eine Dublette; ein wiederholter
 *    alter Klick darf einen neueren Text nicht ablegen;
 *  - seit der letzten menschlichen Antwort bzw. dem letzten gueltigen Stempel
 *    nichts GETIPPT wurde — „Ich komme 30 min spaeter“ und danach „Ja“ ist
 *    Arbeit fuer HR.
 * Der Stempel traegt den Zeitpunkt des Klicks (sent_at), nicht den der
 * Verarbeitung: alles, was danach eingeht, hebt ihn auf.
 */
final class QuickReplyStamper
{
    public function qualifies(CommsWhatsAppThread $thread, CommsWhatsAppMessage $message): bool
    {
        if ($message->direction !== 'inbound' || !QuickReply::isQuickReply($message->meta_payload)) {
            return false;
        }

        // S-Themen §2.6: Klicks auf unsere Themen-Knoepfe stempelt nur der
        // ThemenKlickHandler (Dispo → routed_dispo). Lohn/HR muessen offen
        // bleiben — nicht darauf verlassen, dass die Ursprungsnachricht davor liegt.
        if (QuickReply::istThemenKlick($message->meta_payload)) {
            return false;
        }

        $threadId = (int) $thread->id;

        $neuererEingang = CommsWhatsAppMessage::query()
            ->where('comms_whatsapp_thread_id', $threadId)
            ->where('direction', 'inbound')
            ->where('id', '>', (int) $message->id)
            ->exists();
        if ($neuererEingang) {
            return false;
        }

        // Gleiche Regel wie InboxQuery::humanOutboundTimestamps(): eine
        // Auto-Quittung (OOO, Voice) ist keine Antwort.
        $letzteAntwortId = (int) CommsWhatsAppMessage::query()
            ->where('comms_whatsapp_thread_id', $threadId)
            ->where('direction', 'outbound')
            ->where('is_auto_reply', false)
            ->max('id');

        $stempelAt = RecConversationHandled::query()
            ->where('comms_whatsapp_thread_id', $threadId)
            ->value('handled_at');

        $dazwischen = CommsWhatsAppMessage::query()
            ->where('comms_whatsapp_thread_id', $threadId)
            ->where('direction', 'inbound')
            ->where('id', '>', $letzteAntwortId)
            ->where('id', '<', (int) $message->id)
            ->when($stempelAt !== null, fn ($q) => $q->where('sent_at', '>', $stempelAt))
            ->get(['id', 'meta_payload']);

        foreach ($dazwischen as $frueher) {
            if (!QuickReply::isQuickReply($frueher->meta_payload)) {
                return false;
            }
        }

        return true;
    }

    public function stampIfQuickReply(CommsWhatsAppThread $thread, CommsWhatsAppMessage $message): bool
    {
        if (!$this->qualifies($thread, $message)) {
            return false;
        }

        $handledAt = $message->sent_at ?? $message->created_at ?? now();
        $now = now();
        RecConversationHandled::query()->upsert(
            [[
                'team_id' => (int) $thread->team_id,
                'comms_whatsapp_thread_id' => (int) $thread->id,
                'handled_at' => $handledAt,
                'handled_by_user_id' => null,
                'handled_reason' => RecConversationHandled::REASON_QUICK_REPLY,
                'created_at' => $now,
                'updated_at' => $now,
            ]],
            ['comms_whatsapp_thread_id'],
            ['team_id', 'handled_at', 'handled_by_user_id', 'handled_reason', 'updated_at'],
        );

        return true;
    }
}
