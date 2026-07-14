<?php

namespace Platform\Recruiting\Services\Flynk;

final class FlynkPostingPayloadBuilder
{
    public static function contentHash(?string $title, ?string $description, ?string $activity, ?string $refCode = null): string
    {
        $base = trim((string) $title) . "\n" . trim((string) $description) . "\n" . trim((string) $activity);
        // Nur bei vorhandenem Code anhängen — Bestands-Postings ohne Code
        // behalten ihren Legacy-Hash (sonst Update-Welle an die Agentur).
        $refCode = trim((string) $refCode);
        if ($refCode !== '') {
            $base .= "\n" . $refCode;
        }

        return hash('sha256', $base);
    }

    public static function build(array $posting, string $event, ?string $careersUrl): FlynkTask
    {
        $title = (string) ($posting['title'] ?? '');
        $description = (string) ($posting['description'] ?? '');
        $activity = trim((string) ($posting['activity'] ?? ''));
        $activityLine = $activity !== '' ? "\nTätigkeit: {$activity}" : '';
        $refCode = trim((string) ($posting['ref_code'] ?? ''));
        $refCodeLine = $refCode !== ''
            ? "\n\nReferenz-Code: {$refCode} — bitte gut sichtbar in der Anzeige aufführen (dient der automatischen Zuordnung eingehender Bewerbungen)."
            : '';

        [$taskTitle, $taskType, $taskDescription] = match ($event) {
            FlynkEvent::PUBLISH => [
                "Stellenanzeige: {$title}",
                'new_section',
                $description . $activityLine . $refCodeLine . "\n\nBitte als Stellenanzeige auf der Karriereseite veröffentlichen.",
            ],
            FlynkEvent::UPDATE => [
                "Stellenanzeige aktualisieren: {$title}",
                'text_change',
                $description . $activityLine . $refCodeLine . "\n\nBestehende Anzeige mit diesem Stand aktualisieren.",
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
                'ref_code' => $refCode !== '' ? $refCode : null,
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
            : self::contentHash($title, $description, $activity, $refCode);

        return new FlynkTask($payload, $contentHash);
    }
}
