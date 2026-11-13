<?php

namespace Platform\Recruiting\Services\Push;

use Platform\Recruiting\Models\RecPushAbo;

/**
 * Push-Abos aus dem Portal speichern und loeschen (Spec Jobs anbieten 6.7).
 * Eingabe kommt vom Browser (PushSubscription.toJSON()) — nur https, Laengen geprueft.
 */
class PushAbos
{
    public const MAX_ENDPOINT = 2048;
    public const MAX_SCHLUESSEL = 255;

    /**
     * Ein Geraet = ein Endpunkt. Meldet sich auf demselben Geraet eine andere Person
     * an, gehoert das Abo ab jetzt ihr (sonst bekaeme der Vorbesitzer ihre Hinweise).
     *
     * @param array{endpoint?:mixed, keys?:array{p256dh?:mixed, auth?:mixed}} $abo
     */
    public function speichern(int $employeeId, array $abo, ?string $userAgent): bool
    {
        $endpoint = $abo['endpoint'] ?? null;
        $p256dh = $abo['keys']['p256dh'] ?? null;
        $auth = $abo['keys']['auth'] ?? null;

        if ($employeeId <= 0 || !is_string($endpoint) || !is_string($p256dh) || !is_string($auth)) {
            return false;
        }
        if (!str_starts_with($endpoint, 'https://') || strlen($endpoint) > self::MAX_ENDPOINT
            || filter_var($endpoint, FILTER_VALIDATE_URL) === false) {
            return false;
        }
        foreach ([$p256dh, $auth] as $schluessel) {
            if ($schluessel === '' || strlen($schluessel) > self::MAX_SCHLUESSEL) {
                return false;
            }
        }

        RecPushAbo::query()->updateOrCreate(
            ['endpoint_hash' => RecPushAbo::hash($endpoint)],
            [
                'rec_employee_id' => $employeeId,
                'endpoint' => $endpoint,
                'p256dh' => $p256dh,
                'auth' => $auth,
                'user_agent' => $userAgent === null || $userAgent === '' ? null : mb_substr($userAgent, 0, 255),
            ],
        );

        return true;
    }

    /**
     * Ausschalten im Portal: nur ein Abo der eigenen Identitaets-Gruppe, nie ein fremdes.
     *
     * @param list<int> $gruppe
     */
    public function loeschen(int $employeeId, array $gruppe, string $endpoint): void
    {
        $ids = array_values(array_unique(array_map('intval', array_merge([$employeeId], $gruppe))));

        RecPushAbo::query()
            ->where('endpoint_hash', RecPushAbo::hash($endpoint))
            ->whereIn('rec_employee_id', $ids)
            ->delete();
    }
}
