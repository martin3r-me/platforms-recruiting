<?php

namespace Platform\Recruiting\Services\Flynk;

use Illuminate\Support\Facades\Http;

class FlynkClient
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $token,
        private readonly int $timeout,
    ) {
    }

    public function createTask(array $payload): FlynkResult
    {
        try {
            $response = Http::baseUrl($this->baseUrl)
                ->timeout($this->timeout)
                ->withToken($this->token)
                ->asJson()
                ->acceptJson()
                ->post('/webhooks/tasks', $payload);
        } catch (\Throwable) {
            return FlynkResponseMapper::map(null, null, connectionFailed: true);
        }

        $body = null;
        try {
            $decoded = $response->json();
            $body = is_array($decoded) ? $decoded : null;
        } catch (\Throwable) {
            $body = null;
        }

        return FlynkResponseMapper::map($response->status(), $body);
    }
}
