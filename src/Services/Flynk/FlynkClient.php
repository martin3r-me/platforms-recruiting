<?php

namespace Platform\Recruiting\Services\Flynk;

use Illuminate\Support\Facades\Http;

class FlynkClient
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $token,
        private readonly int $timeout,
        private readonly int $uploadTimeout = 60,
    ) {
    }

    /**
     * Ohne Datei: JSON wie bisher. Mit Datei: `multipart/form-data`, die Datei
     * in `files[]`, verschachtelte Werte als `meta[schluessel]` (FlynkMultipart).
     * Der Upload bekommt ein eigenes, laengeres Zeitlimit — 50 MB passen nicht
     * in die 10 Sekunden der JSON-Meldung.
     */
    public function createTask(array $payload, ?FlynkAttachment $attachment = null): FlynkResult
    {
        try {
            $request = Http::baseUrl($this->baseUrl)
                ->withToken($this->token)
                ->acceptJson();

            if ($attachment === null) {
                $response = $request->timeout($this->timeout)->asJson()->post('/webhooks/tasks', $payload);
            } else {
                // Kein eigener Content-Type fuer den Datei-Teil: Guzzle leitet ihn
                // aus der Endung ab. Der bei uns gespeicherte Typ stammt aus der
                // Inhaltserkennung und ist bei DOCX gern „application/zip".
                $response = $request->timeout(max($this->timeout, $this->uploadTimeout))
                    ->attach('files[]', $attachment->contents, $attachment->filename)
                    ->post('/webhooks/tasks', FlynkMultipart::flatten($payload));
            }
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

        return FlynkResponseMapper::map($response->status(), $body, fileSent: $attachment !== null);
    }
}
