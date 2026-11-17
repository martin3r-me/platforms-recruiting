<?php

namespace Platform\Recruiting\Services\Flynk;

final class FlynkPostingPayloadBuilder
{
    /**
     * $fileHash = SHA-256 der angehaengten Anzeige-Datei. Wie beim Referenz-Code
     * nur bei vorhandener Datei angehaengt: Ausschreibungen ohne Datei behalten
     * ihren bisherigen Hash (sonst Update-Welle an die Agentur). Ein Dateitausch
     * aendert den Hash → „aktualisieren"-Meldung; Entfernen der Datei ebenso.
     */
    public static function contentHash(?string $title, ?string $description, ?string $activity, ?string $refCode = null, ?string $fileHash = null): string
    {
        $base = trim((string) $title) . "\n" . trim((string) $description) . "\n" . trim((string) $activity);
        // Nur bei vorhandenem Code anhängen — Bestands-Postings ohne Code
        // behalten ihren Legacy-Hash (sonst Update-Welle an die Agentur).
        $refCode = trim((string) $refCode);
        if ($refCode !== '') {
            $base .= "\n" . $refCode;
        }
        $fileHash = trim((string) $fileHash);
        if ($fileHash !== '') {
            $base .= "\nfile:" . $fileHash;
        }

        return hash('sha256', $base);
    }

    /**
     * Enddatum als Vergleichswert je Meldung: `YYYY-MM-DD` oder `none`.
     * Steht NICHT im Inhalts-Hash (sonst Update-Welle fuer den Bestand),
     * sondern in einer eigenen Spalte der Meldungs-Zeile.
     */
    public static function closesKey(?string $closesAt): string
    {
        $date = substr(trim((string) $closesAt), 0, 10);

        return $date !== '' ? $date : 'none';
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
        $fileHash = trim((string) ($posting['anzeige_hash'] ?? ''));
        $fileName = trim((string) ($posting['anzeige_filename'] ?? ''));
        $fileLine = $fileHash !== ''
            ? "\n\nDie Stellenanzeige liegt als Datei bei" . ($fileName !== '' ? ": {$fileName}" : '') . '.'
            : '';
        // Laufzeit im Klartext: bei einer reinen Datumsaenderung ist das der
        // einzige Unterschied zur vorigen Meldung.
        $closesKey = self::closesKey($posting['closes_at'] ?? null);
        $laufzeitLine = $closesKey !== 'none'
            ? "\n\nLaufzeit: bis einschließlich " . self::deDate($closesKey) . '.'
            : "\n\nLaufzeit: ohne Enddatum.";

        [$taskTitle, $taskType, $taskDescription] = match ($event) {
            FlynkEvent::PUBLISH => [
                "Stellenanzeige: {$title}",
                'new_section',
                $description . $activityLine . $refCodeLine . $fileLine . $laufzeitLine . "\n\nBitte als Stellenanzeige auf der Karriereseite veröffentlichen.",
            ],
            FlynkEvent::UPDATE => [
                "Stellenanzeige aktualisieren: {$title}",
                'text_change',
                $description . $activityLine . $refCodeLine . $fileLine . $laufzeitLine . "\n\nBestehende Anzeige mit diesem Stand aktualisieren.",
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
                'published_at' => $posting['published_at'] ?? null,
                'closes_at' => $posting['closes_at'] ?? null,
                'event' => $event,
            ],
        ];

        if ($careersUrl !== null && $careersUrl !== '') {
            $payload['target_url'] = $careersUrl;
        }

        $contentHash = $event === FlynkEvent::CLOSE
            ? ''
            : self::contentHash($title, $description, $activity, $refCode, $fileHash);

        return new FlynkTask($payload, $contentHash);
    }

    private static function deDate(string $ymd): string
    {
        $p = explode('-', $ymd);

        return count($p) === 3 ? "{$p[2]}.{$p[1]}.{$p[0]}" : $ymd;
    }
}
