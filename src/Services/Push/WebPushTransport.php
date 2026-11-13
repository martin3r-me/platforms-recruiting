<?php

namespace Platform\Recruiting\Services\Push;

use Illuminate\Support\Facades\Log;
use Platform\Recruiting\Models\RecPushAbo;

/**
 * Web Push nach Standard (VAPID) ueber minishlink/web-push (Spec Jobs anbieten 6.7).
 * Fehlt die Bibliothek oder ein Schluessel: 'fehler', nie eine Ausnahme — eine
 * Welle darf an Push nie scheitern. Im Test-vendor ist die Bibliothek nicht
 * installiert; diese Klasse ist nur am echten Geraet geprueft.
 */
class WebPushTransport implements PushTransport
{
    /** Hoechstens eine Fehlerzeile je Instanz — der Job loest den Transport je Lauf frisch auf. */
    private bool $fehlerGemeldet = false;

    public function senden(RecPushAbo $abo, array $payload): string
    {
        if (!class_exists(\Minishlink\WebPush\WebPush::class) || !class_exists(\Minishlink\WebPush\Subscription::class)) {
            return 'fehler';
        }
        $public = (string) config('recruiting.push.vapid.public', '');
        $private = (string) config('recruiting.push.vapid.private', '');
        // Die Bibliothek verlangt einen Absender (mailto: oder URL); ohne eigenen die App-Adresse.
        $subject = (string) (config('recruiting.push.vapid.subject') ?: config('app.url', ''));
        if ($public === '' || $private === '' || $subject === '') {
            return 'fehler';
        }

        $endpunkt = (string) $abo->endpoint;
        $geheim = [(string) $abo->p256dh, (string) $abo->auth];
        try {
            $webPush = new \Minishlink\WebPush\WebPush(['VAPID' => [
                'subject' => $subject,
                'publicKey' => $public,
                'privateKey' => $private,
            ]]);
            $subscription = \Minishlink\WebPush\Subscription::create([
                'endpoint' => (string) $abo->endpoint,
                'publicKey' => (string) $abo->p256dh,
                'authToken' => (string) $abo->auth,
                // Standard nach RFC 8291; der Browser liefert die Kodierung im toJSON() nicht mit.
                'contentEncoding' => 'aes128gcm',
            ]);
            $report = $webPush->sendOneNotification($subscription, (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            if ($report->isSuccess()) {
                return 'ok';
            }

            // isSubscriptionExpired = HTTP 404 oder 410 vom Push-Dienst.
            if ($report->isSubscriptionExpired()) {
                return 'erloschen';
            }
            $this->fehlerMelden((string) $report->getReason(), $endpunkt, $geheim);

            return 'fehler';
        } catch (\Throwable $e) {
            $this->fehlerMelden($e->getMessage(), $endpunkt, $geheim);

            return 'fehler';
        }
    }

    /**
     * Gesamtpruefung I4 (13.11.2026): vorher kein Wort, warum Push scheitert. Geloggt
     * werden Grund und Host; jede URL im Text wird auf ihren Host gekuerzt (der Pfad
     * des Endpunkts ist das Abo selbst), Schluessel werden entfernt. Wirft nie.
     *
     * @param list<string> $geheim Werte, die nie im Log landen duerfen (p256dh, auth)
     */
    protected function fehlerMelden(string $grund, string $endpunkt, array $geheim = []): void
    {
        if ($this->fehlerGemeldet) {
            return;
        }
        $this->fehlerGemeldet = true;

        try {
            $host = parse_url($endpunkt, PHP_URL_HOST);
            $grund = str_replace(array_values(array_filter($geheim, fn ($g) => $g !== '')), '…', $grund);
            $grund = (string) preg_replace_callback('~https?://[^\s`\'"<>]+~i', fn ($m) => (string) (parse_url($m[0], PHP_URL_HOST) ?: '…'), $grund);
            $grund = trim($grund);
            Log::warning('recruiting.portal_push.transport_fehler', [
                'host'  => is_string($host) && $host !== '' ? $host : null,
                'grund' => $grund === '' ? '(ohne Text)' : mb_substr($grund, 0, 300),
            ]);
        } catch (\Throwable) {
            // Ein kaputtes Log darf den Versand nicht aufhalten.
        }
    }
}
