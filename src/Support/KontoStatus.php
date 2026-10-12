<?php

namespace Platform\Recruiting\Support;

/**
 * Der Konto-Zustand eines Menschen — EINE Ableitung fuer Pilot-Ueberblick und
 * Konto-Kasten (Spec HR-Bereich §3.4/§4.1). Rein: bekommt die Personen-Felder
 * und "jetzt" ('Y-m-d H:i:s').
 */
final class KontoStatus
{
    public const NICHT_EINGELADEN = 'nicht_eingeladen';
    public const EINGELADEN       = 'eingeladen';
    public const ABGELAUFEN       = 'abgelaufen';
    public const REGISTRIERT      = 'registriert';
    public const ZURUECKGESETZT   = 'zurueckgesetzt';

    public const LANGE_NICHT_TAGE = 14;

    /** Filter-Chips des Pilot-Ueberblicks, in Anzeige-Reihenfolge. */
    public const FILTER = [
        'alle'             => 'Alle',
        'nicht_eingeladen' => 'nicht eingeladen',
        'eingeladen'       => 'eingeladen, nicht registriert',
        'abgelaufen'       => 'Einladung abgelaufen',
        'nie_angemeldet'   => 'registriert, nie angemeldet',
        'lange_nicht'      => 'seit 14 Tagen nicht angemeldet',
    ];

    public static function code(object $p, string $jetzt): string
    {
        if (!empty($p->password_hash ?? null)) {
            return self::REGISTRIERT;
        }
        if (($p->registered_at ?? null) !== null) {
            return self::ZURUECKGESETZT;
        }
        if (($p->invited_at ?? null) === null) {
            return self::NICHT_EINGELADEN;
        }

        return self::abgelaufen($p, $jetzt) ? self::ABGELAUFEN : self::EINGELADEN;
    }

    /** Abgelaufen GENAU ab invite_expires_at (wie KontoEinladen::einladungsstand). */
    public static function abgelaufen(object $p, string $jetzt): bool
    {
        $bis = $p->invite_expires_at ?? null;

        return $bis !== null
            && ($p->invite_used_at ?? null) === null
            && strtotime((string) $bis) <= strtotime($jetzt);
    }

    public static function text(object $p, string $jetzt): string
    {
        return match (self::code($p, $jetzt)) {
            self::NICHT_EINGELADEN => 'nicht eingeladen',
            self::EINGELADEN       => sprintf('eingeladen am %s (gilt bis %s)', self::tag($p->invited_at), self::tag($p->invite_expires_at)),
            self::ABGELAUFEN       => 'Einladung abgelaufen',
            self::REGISTRIERT      => 'registriert am ' . self::tag($p->registered_at),
            self::ZURUECKGESETZT   => sprintf('zurückgesetzt am %s (wartet auf neues Passwort)', self::tag($p->invited_at ?? $p->registered_at)),
        };
    }

    /** @return list<string> Schluessel aus FILTER, die auf diesen Menschen zutreffen ('alle' immer). */
    public static function filter(object $p, string $jetzt): array
    {
        $code = self::code($p, $jetzt);
        $treffer = ['alle'];

        if (in_array($code, [self::NICHT_EINGELADEN, self::EINGELADEN, self::ABGELAUFEN], true)) {
            $treffer[] = $code;
        }
        if ($code === self::REGISTRIERT) {
            $letzte = $p->letzte_anmeldung_at ?? null;
            if ($letzte === null) {
                $treffer[] = 'nie_angemeldet';
            } elseif (strtotime((string) $letzte) <= strtotime($jetzt . ' -' . self::LANGE_NICHT_TAGE . ' days')) {
                $treffer[] = 'lange_nicht';
            }
        }

        return $treffer;
    }

    private static function tag(mixed $wert): string
    {
        $zeit = $wert === null ? false : strtotime((string) $wert);

        return $zeit === false ? '—' : date('d.m.Y', $zeit);
    }
}
