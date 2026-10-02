<?php

namespace Platform\Recruiting\Services\Comms\Forward;

use Platform\Crm\Models\CommsWhatsAppMessage;

/**
 * Anhaenge weitergeleiteter Nachrichten — immer FRISCH zur message_id, nie
 * gespeichert: die Datei-URLs sind signiert und laufen nach 60 Minuten ab
 * (Core ContextFile::getUrlAttribute). Zugriff wie im Dispo-Chat.
 */
final class ForwardAttachments
{
    /**
     * @param array<int, mixed> $attachments Ausgabe von CommsWhatsAppMessage::attachments
     * @return list<array{url:string, thumbnail:?string, title:string, media_type:?string}>
     */
    public static function map(array $attachments, ?string $mediaType): array
    {
        $out = [];
        foreach ($attachments as $att) {
            $url = is_array($att) ? (string) ($att['url'] ?? '') : '';
            if ($url === '') {
                continue;
            }
            $thumb = (string) ($att['thumbnail'] ?? '');
            $title = trim((string) ($att['title'] ?? ''));
            $out[] = [
                'url' => $url,
                'thumbnail' => $thumb !== '' ? $thumb : null,
                'title' => $title !== '' ? $title : 'Datei',
                'media_type' => $mediaType,
            ];
        }

        return $out;
    }

    /**
     * @param array<int, int|string> $messageIds
     * @return array<int, list<array{url:string, thumbnail:?string, title:string, media_type:?string}>>
     */
    public function forMessageIds(array $messageIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $messageIds), static fn (int $id) => $id > 0)));
        if ($ids === []) {
            return [];
        }

        try {
            $result = [];
            foreach (CommsWhatsAppMessage::query()->whereIn('id', $ids)->get() as $m) {
                if (!method_exists($m, 'hasMedia') || !$m->hasMedia()) {
                    continue;
                }
                $mapped = self::map((array) ($m->attachments ?? []), (string) $m->media_display_type);
                if ($mapped !== []) {
                    $result[(int) $m->id] = $mapped;
                }
            }

            return $result;
        } catch (\Throwable) {
            return [];
        }
    }
}
