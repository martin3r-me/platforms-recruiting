<?php

namespace Platform\Recruiting\Services\Flynk;

final class FlynkResponseMapper
{
    /** @param array<string,mixed>|null $body */
    public static function map(?int $httpStatus, ?array $body, bool $connectionFailed = false, bool $fileSent = false): FlynkResult
    {
        if ($connectionFailed) {
            return new FlynkResult(false, null, null, false, false, false, 'connection_failed');
        }
        if ($httpStatus === 201) {
            $taskId = isset($body['id']) ? (string) $body['id'] : null;
            return new FlynkResult(true, 201, $taskId, false, false, false, null, $fileSent ? self::fileProblem($body) : null);
        }
        if ($httpStatus === 401) {
            return new FlynkResult(false, 401, null, false, false, true, 'unauthorized');
        }
        // 413 (zu gross) und 415 (Typ nicht erlaubt) sind wie 422 ein Fehler
        // DIESER Meldung: derselbe Upload scheitert beim naechsten Versuch genauso.
        if ($httpStatus === 413 || $httpStatus === 415) {
            return new FlynkResult(false, $httpStatus, null, true, false, false, self::stringify($body) ?? "http_{$httpStatus}");
        }
        if ($httpStatus === 422) {
            return new FlynkResult(false, 422, null, true, false, false, self::stringify($body));
        }
        if ($httpStatus === 429) {
            return new FlynkResult(false, 429, null, false, true, false, 'rate_limited');
        }

        // 5xx und alles andere → transient
        return new FlynkResult(false, $httpStatus, null, false, false, false, self::stringify($body));
    }

    /**
     * 201 heisst bei FLYNK nur „Aufgabe angelegt" — eine abgelehnte Datei steht
     * trotzdem unter `skipped_files`, eine angenommene unter `attachments`
     * (Mail 11/2026). Die genaue Form der Eintraege ist nicht dokumentiert,
     * deshalb defensiv: gefuelltes `skipped_files` ODER vorhandenes, aber leeres
     * `attachments` gilt als Problem. Fehlen beide Schluessel, wird NICHT
     * geraten (kein Fehlalarm bei geaenderter Antwortform).
     *
     * @param array<string,mixed>|null $body
     */
    private static function fileProblem(?array $body): ?string
    {
        if ($body === null) {
            return null;
        }
        $skipped = $body['skipped_files'] ?? null;
        if (is_array($skipped) && $skipped !== []) {
            $json = json_encode($skipped, JSON_UNESCAPED_UNICODE);
            return 'Datei abgelehnt: ' . mb_substr($json === false ? '?' : $json, 0, 800);
        }
        if (array_key_exists('attachments', $body) && is_array($body['attachments']) && $body['attachments'] === []) {
            return 'Datei nicht angekommen (attachments leer)';
        }

        return null;
    }

    private static function stringify(?array $body): ?string
    {
        if ($body === null) {
            return null;
        }
        $json = json_encode($body, JSON_UNESCAPED_UNICODE);
        return $json === false ? null : mb_substr($json, 0, 1000);
    }
}
