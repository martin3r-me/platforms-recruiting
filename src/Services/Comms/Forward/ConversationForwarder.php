<?php

namespace Platform\Recruiting\Services\Comms\Forward;

use Platform\Crm\Models\CommsChannel;
use Platform\Crm\Models\CommsWhatsAppThread;
use Platform\Recruiting\Models\RecConversationForward;

/**
 * Legt eine Weiterleitung an: Dispo → HR (Spec 02.10.2026) oder HR → Dispo
 * (Spec 16.10.2026, Ziel ist ein bestimmter Dispo-Kanal = Filiale).
 *
 * Sicherheit: die IDs kommen aus einem Livewire-Aufruf und sind damit frei
 * waehlbar. Kopiert wird nur, was zu DIESEM Thread gehoert und eingehend ist;
 * ein Dispo-Ziel-Kanal muss zur Auswahl von DispoForwardChannels gehoeren.
 * Liefert ok/error statt zu werfen (Muster DispoReplySender).
 */
final class ConversationForwarder
{
    private const COMMENT_MAX = 1000;

    public function __construct(private readonly ?DispoForwardChannels $dispoChannels = null) {}

    private const MEDIA_LABELS = [
        'image' => 'Bild',
        'video' => 'Video',
        'audio' => 'Audio',
        'voice' => 'Sprachnachricht',
        'document' => 'Dokument',
        'sticker' => 'Sticker',
    ];

    /** Deutsche Bezeichnung eines WhatsApp-Medientyps (Anzeige an Karte/Liste). */
    public static function mediaLabel(?string $type): string
    {
        $type = (string) $type;

        return self::MEDIA_LABELS[$type] ?? ucfirst($type);
    }

    /**
     * @param array<int, int|string> $messageIds
     * @return array{ok: bool, error: ?string, forward: ?RecConversationForward}
     */
    public function forward(
        int $teamId,
        CommsWhatsAppThread $thread,
        array $messageIds,
        ?string $comment,
        ?int $employeeId,
        string $displayName,
        ?object $user,
        string $target = ForwardTargets::HR,
        string $source = ForwardTargets::SOURCE_DISPO,
        ?int $targetChannelId = null,
    ): array {
        if (!ForwardTargets::isRoute($source, $target)) {
            return ['ok' => false, 'error' => 'Unbekanntes Weiterleitungsziel.', 'forward' => null];
        }

        $targetChannel = null;
        if ($target === ForwardTargets::DISPO) {
            $erlaubt = array_column(($this->dispoChannels ?? new DispoForwardChannels())->options($teamId), 'channel_id');
            if ($targetChannelId === null || !in_array($targetChannelId, $erlaubt, true)) {
                return ['ok' => false, 'error' => 'Bitte eine Dispo auswählen.', 'forward' => null];
            }
            $targetChannel = CommsChannel::query()->whereKey($targetChannelId)->where('team_id', $teamId)->first();
            if ($targetChannel === null) {
                return ['ok' => false, 'error' => 'Bitte eine Dispo auswählen.', 'forward' => null];
            }
        }

        $ids = array_values(array_unique(array_map('intval', $messageIds)));
        $rows = $ids === [] ? collect() : $thread->messages()
            ->whereIn('id', $ids)
            ->where('direction', 'inbound')
            ->orderBy('created_at')->orderBy('id')
            ->get();

        if ($rows->isEmpty()) {
            return ['ok' => false, 'error' => 'Keine eingehende Nachricht ausgewählt.', 'forward' => null];
        }

        $copy = $rows->map(function ($m) {
            $at = $m->sent_at ?? $m->created_at;
            $hasMedia = method_exists($m, 'hasMedia') && $m->hasMedia();

            return [
                'message_id' => (int) $m->id,
                'body' => (string) ($m->body ?? ''),
                'media_type' => $hasMedia
                    ? (string) $m->media_display_type
                    : ((string) ($m->message_type ?? 'text') !== 'text' ? (string) $m->message_type : null),
                'received_at' => optional($at)->toIso8601String() ?? '',
            ];
        })->values()->all();

        $comment = trim((string) $comment);

        // HR -> Dispo: Ziel-Thread sofort festlegen und auf ungelesen setzen —
        // das Dispo-Postfach zeigt jeden Thread seines Kanals, auch ohne Eingang;
        // ist das 24h-Fenster zu, startet die Dispo mit „Gespräch starten“.
        $targetThread = $targetChannel !== null
            ? $this->dispoThread($targetChannel, (string) $thread->remote_phone_number)
            : null;

        $forward = RecConversationForward::create([
            'team_id' => $teamId,
            'source' => $source,
            'target' => $target,
            'target_channel_id' => $targetChannel?->id,
            'target_thread_id' => $targetThread?->id,
            'source_thread_id' => (int) $thread->id,
            'rec_employee_id' => $employeeId,
            'phone' => (string) $thread->remote_phone_number,
            'display_name' => mb_substr($displayName !== '' ? $displayName : (string) $thread->remote_phone_number, 0, 190),
            'messages' => $copy,
            'comment' => $comment === '' ? null : mb_substr($comment, 0, self::COMMENT_MAX),
            'forwarded_by_user_id' => isset($user->id) ? (int) $user->id : null,
            'forwarded_by_name' => isset($user->name) ? mb_substr((string) $user->name, 0, 190) : null,
            'forwarded_at' => now(),
        ]);

        $targetThread?->markAsUnread();

        return ['ok' => true, 'error' => null, 'forward' => $forward];
    }

    /**
     * Vorhandener Thread dieser Nummer auf dem Dispo-Kanal, sonst ein neuer.
     * Meta liefert Nummern mal als "+49…", mal als nackte wa_id "49…" — beide
     * Formen pruefen, sonst entsteht ein zweiter Thread derselben Person
     * (gleiche Regel wie ForwardHrThreadLookup).
     */
    private function dispoThread(CommsChannel $channel, string $phone): CommsWhatsAppThread
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';

        $vorhanden = $digits === '' ? null : CommsWhatsAppThread::query()
            ->where('comms_channel_id', (int) $channel->id)
            ->whereIn('remote_phone_number', ['+' . $digits, $digits])
            ->orderByDesc('last_inbound_at')
            ->orderByDesc('id')
            ->first();

        return $vorhanden ?? CommsWhatsAppThread::findOrCreateForPhone($channel, $digits !== '' ? '+' . $digits : $phone);
    }
}
