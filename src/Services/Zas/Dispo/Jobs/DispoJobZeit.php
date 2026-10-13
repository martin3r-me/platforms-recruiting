<?php

namespace Platform\Recruiting\Services\Zas\Dispo\Jobs;

/**
 * Zeitspanne einer Schicht fuer die Anzeige („16:00–23:00“) und die Pruefung
 * einer von Hand eingegebenen Schicht (Runde 2, Spec §8). Rein rechnend.
 */
final class DispoJobZeit
{
    public const MUSTER = '/^([01]\d|2[0-3]):[0-5]\d$/';

    public static function spanne(?string $von, ?string $bis): string
    {
        $von = trim((string) $von);
        $bis = trim((string) $bis);
        if ($von === '') {
            return '';
        }

        return $bis !== '' ? $von . '–' . $bis : $von;
    }

    /**
     * Meldung fuer den Disponenten oder null. Beide Felder duerfen leer sein.
     * Ein Ende vor dem Beginn ist gueltig (Nachtschicht ueber Mitternacht, z. B. 18:00–02:00);
     * nur identische Zeiten werden abgelehnt.
     */
    public static function problem(string $von, string $bis): ?string
    {
        $von = trim($von);
        $bis = trim($bis);
        if ($von !== '' && preg_match(self::MUSTER, $von) !== 1) {
            return 'Der Beginn muss als Uhrzeit (HH:MM) angegeben werden.';
        }
        if ($bis !== '' && preg_match(self::MUSTER, $bis) !== 1) {
            return 'Das Ende muss als Uhrzeit (HH:MM) angegeben werden.';
        }
        if ($von !== '' && $bis !== '' && $bis === $von) {
            return 'Beginn und Ende dürfen nicht gleich sein.';
        }

        return null;
    }
}
