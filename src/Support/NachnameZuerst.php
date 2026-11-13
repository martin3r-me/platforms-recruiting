<?php

namespace Platform\Recruiting\Support;

/**
 * „Nachname, Vorname“ fuer Listen der Disposition (Sheran 13.11.2026): so
 * steht es in ZAS, und so sucht die Dispo. Rein.
 */
final class NachnameZuerst
{
    public static function von(?string $vorname, ?string $nachname): string
    {
        $vorname = trim((string) $vorname);
        $nachname = trim((string) $nachname);
        if ($vorname === '' || $nachname === '') {
            return $nachname . $vorname;
        }

        return $nachname . ', ' . $vorname;
    }
}
