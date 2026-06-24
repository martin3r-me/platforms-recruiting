<?php

namespace Platform\Recruiting\Services\Comms;

use Platform\Crm\Models\CommsChannel;
use Platform\Crm\Models\CommsLog;
use Platform\Crm\Models\CommsWhatsAppMessage;
use Platform\Crm\Models\CommsWhatsAppThread;

/**
 * Schickt automatisch ein Hinweis-Template zurück, wenn eine eingehende
 * WhatsApp-Nachricht eine Sprach-/Audionachricht ist (die der Kunde nicht
 * bearbeiten kann). Gedrosselt auf 1× pro 24h je Konversation.
 *
 * Feature ist aus, solange kein Template konfiguriert ist
 * (`comms_voice_not_supported_template_id`).
 */
final class VoiceNoteAutoReplyHandler
{
    private const SETTINGS_KEY = 'comms_voice_not_supported_template_id';

    public function __construct(private readonly HoldingTemplateSender $sender) {}

    public function handle(CommsChannel $channel, CommsWhatsAppThread $thread, CommsWhatsAppMessage $message): void
    {
        // Nur Sprach-/Audionachrichten (Voice-Notes werden als 'audio' gespeichert).
        if ($message->message_type !== 'audio') {
            return;
        }

        $teamId = (int) $channel->team_id;

        // Feature aktiv? (Template konfiguriert UND bei Meta genehmigt)
        $templateName = $this->sender->configuredTemplateName($teamId, self::SETTINGS_KEY);
        if ($templateName === null) {
            return;
        }

        $phone = (string) ($thread->remote_phone_number ?? '');
        if ($phone === '') {
            return;
        }

        // Drosselung: letzte ausgehende Auto-Antwort (dieses Template) auf dem Thread.
        $last = CommsWhatsAppMessage::query()
            ->where('comms_whatsapp_thread_id', $thread->id)
            ->where('direction', 'outbound')
            ->where('template_name', $templateName)
            ->latest('created_at')
            ->first();

        if (VoiceNoteAutoReplyThrottle::shouldSkip($last?->created_at?->getTimestamp(), time())) {
            return; // innerhalb der letzten 24h bereits gesendet
        }

        $result = $this->sender->sendOne($teamId, $phone, $this->firstNameFromThread($thread), self::SETTINGS_KEY);

        CommsLog::log(
            event: 'voice_autoreply_sent',
            status: ($result['error'] === null && ($result['sent'] ?? 0) > 0) ? 'success' : 'error',
            summary: $result['error'] === null
                ? "Sprachnachricht-Auto-Hinweis an {$phone} gesendet"
                : "Sprachnachricht-Auto-Hinweis fehlgeschlagen: {$result['error']}",
            details: ['thread_id' => $thread->id, 'template' => $templateName, 'result' => $result],
            extra: [
                'team_id' => $teamId,
                'channel_type' => 'whatsapp',
                'channel_id' => $channel->id,
                'source' => 'recruiting_voice_autoreply',
                'recipient' => $phone,
            ],
        );
    }

    private function firstNameFromThread(CommsWhatsAppThread $thread): string
    {
        $full = $thread->contact?->full_name;
        if (!$full) {
            return '';
        }

        return trim(explode(' ', trim($full))[0] ?? '');
    }
}
