<?php

namespace Platform\Recruiting\Services\Flynk;

final class FlynkResponseMapper
{
    /** @param array<string,mixed>|null $body */
    public static function map(?int $httpStatus, ?array $body, bool $connectionFailed = false): FlynkResult
    {
        if ($connectionFailed) {
            return new FlynkResult(false, null, null, false, false, false, 'connection_failed');
        }
        if ($httpStatus === 201) {
            $taskId = isset($body['id']) ? (string) $body['id'] : null;
            return new FlynkResult(true, 201, $taskId, false, false, false, null);
        }
        if ($httpStatus === 401) {
            return new FlynkResult(false, 401, null, false, false, true, 'unauthorized');
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

    private static function stringify(?array $body): ?string
    {
        if ($body === null) {
            return null;
        }
        $json = json_encode($body, JSON_UNESCAPED_UNICODE);
        return $json === false ? null : mb_substr($json, 0, 1000);
    }
}
