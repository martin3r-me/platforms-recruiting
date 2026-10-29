<?php

namespace Platform\Recruiting\Services\Zas\Dispo\Jobs;

/**
 * Der Link in der Jobangebots-Nachricht (Markus 29.10.: „der 04.11. ist verkehrt
 * für die Nachricht“): Meta erlaubt am URL-Button nur EIN Suffix, also tragen
 * wir Token und Anfrage zusammen darin — „{token}.{anfrage}“. Die Seite zeigt
 * dann nur diese Anfrage. Alte Links ohne Anfrage zeigen weiter alles. Rein.
 */
final class JobOffersLink
{
    public static function suffix(string $token, ?int $anfrageId): string
    {
        return $anfrageId !== null && $anfrageId > 0 ? $token . '.' . $anfrageId : $token;
    }

    /** @return array{token:string, anfrage:?int} */
    public static function lesen(string $roh): array
    {
        if (preg_match('/^(.+)\.(\d+)$/', $roh, $m)) {
            return ['token' => $m[1], 'anfrage' => (int) $m[2]];
        }

        return ['token' => $roh, 'anfrage' => null];
    }
}
