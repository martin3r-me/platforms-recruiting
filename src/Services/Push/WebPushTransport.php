<?php

namespace Platform\Recruiting\Services\Push;

use Platform\Recruiting\Models\RecPushAbo;

/**
 * Web Push nach Standard (VAPID) ueber minishlink/web-push (Spec Jobs anbieten 6.7).
 * Fehlt die Bibliothek oder ein Schluessel: 'fehler', nie eine Ausnahme — eine
 * Welle darf an Push nie scheitern. Im Test-vendor ist die Bibliothek nicht
 * installiert; diese Klasse ist nur am echten Geraet geprueft.
 */
class WebPushTransport implements PushTransport
{
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
            return $report->isSubscriptionExpired() ? 'erloschen' : 'fehler';
        } catch (\Throwable) {
            return 'fehler';
        }
    }
}
