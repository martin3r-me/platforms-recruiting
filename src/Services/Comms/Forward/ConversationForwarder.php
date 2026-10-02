<?php

namespace Platform\Recruiting\Services\Comms\Forward;

use Platform\Crm\Models\CommsWhatsAppThread;
use Platform\Recruiting\Models\RecConversationForward;

/**
 * Legt eine Weiterleitung Dispo → HR an (Spec 02.10.2026).
 *
 * Sicherheit: die IDs kommen aus einem Livewire-Aufruf und sind damit frei
 * waehlbar. Kopiert wird nur, was zu DIESEM Thread gehoert und eingehend ist.
 * Liefert ok/error statt zu werfen (Muster DispoReplySender).
 */
final class ConversationForwarder
{
    private const COMMENT_MAX = 1000;

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
    ): array {
        if (!ForwardTargets::isTarget($target) || !ForwardTargets::isSource($source)) {
            return ['ok' => false, 'error' => 'Unbekanntes Weiterleitungsziel.', 'forward' => null];
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

        $forward = RecConversationForward::create([
            'team_id' => $teamId,
            'source' => $source,
            'target' => $target,
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

        return ['ok' => true, 'error' => null, 'forward' => $forward];
    }
}
