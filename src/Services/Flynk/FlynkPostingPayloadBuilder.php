<?php

namespace Platform\Recruiting\Services\Flynk;

final class FlynkPostingPayloadBuilder
{
    public static function contentHash(?string $title, ?string $description, ?string $activity): string
    {
        return hash(
            'sha256',
            trim((string) $title) . "\n" . trim((string) $description) . "\n" . trim((string) $activity)
        );
    }

    public static function build(array $posting, string $event, ?string $careersUrl): FlynkTask
    {
        $title = (string) ($posting['title'] ?? '');
        $description = (string) ($posting['description'] ?? '');
        $activity = trim((string) ($posting['activity'] ?? ''));
        $activityLine = $activity !== '' ? "\nTätigkeit: {$activity}" : '';

        [$taskTitle, $taskType, $taskDescription] = match ($event) {
            FlynkEvent::PUBLISH => [
                "Stellenanzeige: {$title}",
                'new_section',
                $description . $activityLine . "\n\nBitte als Stellenanzeige auf der Karriereseite veröffentlichen.",
            ],
            FlynkEvent::UPDATE => [
                "Stellenanzeige aktualisieren: {$title}",
                'text_change',
                $description . $activityLine . "\n\nBestehende Anzeige mit diesem Stand aktualisieren.",
            ],
            FlynkEvent::CLOSE => [
                "Stellenanzeige entfernen: {$title}",
                'text_change',
                'Diese Stellenanzeige ist beendet — bitte von der Karriereseite entfernen.',
            ],
        };

        $payload = [
            'title' => mb_substr($taskTitle, 0, 255),
            'type' => $taskType,
            'description' => $taskDescription,
            'priority' => 'normal',
            'meta' => [
                'posting_uuid' => $posting['uuid'] ?? null,
                'position_title' => $posting['position_title'] ?? null,
                'activity' => $posting['activity'] ?? null,
                'team_id' => $posting['team_id'] ?? null,
                'generation' => $posting['generation'] ?? null,
                'closes_at' => $posting['closes_at'] ?? null,
                'event' => $event,
            ],
        ];

        if ($careersUrl !== null && $careersUrl !== '') {
            $payload['target_url'] = $careersUrl;
        }

        $contentHash = $event === FlynkEvent::CLOSE
            ? ''
            : self::contentHash($title, $description, $activity);

        return new FlynkTask($payload, $contentHash);
    }
}
