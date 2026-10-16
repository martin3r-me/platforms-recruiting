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
 */
final class QuickReplyStamper
{
    public function stampIfQuickReply(CommsWhatsAppThread $thread, CommsWhatsAppMessage $message): bool
    {
        if ($message->direction !== 'inbound' || !QuickReply::isQuickReply($message->meta_payload)) {
            return false;
        }

        $now = now();
        RecConversationHandled::query()->upsert(
            [[
                'team_id' => (int) $thread->team_id,
                'comms_whatsapp_thread_id' => (int) $thread->id,
                'handled_at' => $now,
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
