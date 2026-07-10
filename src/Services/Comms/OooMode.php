<?php

namespace Platform\Recruiting\Services\Comms;

/**
 * Alleinige Source of Truth fuer den HR-Abwesenheitsmodus (OOO).
 * Reine Datums-Logik auf 'Y-m-d'-Strings (lexikographisch == chronologisch),
 * dependency-frei -> unit-testbar (Modul-Test-Konvention).
 *
 * Es gibt KEINEN Reset des enabled-Flags irgendwo — der Zustand ergibt sich
 * rein aus den Werten (lazy Auto-Off ab dem Wieder-da-Tag).
 */
final class OooMode
{
    public const STATE_OFF = 'off';
    public const STATE_PENDING = 'pending'; // geplant, Abwesenheit noch nicht begonnen
    public const STATE_ACTIVE = 'active';

    public static function state(bool $enabled, ?string $fromYmd, ?string $backAtYmd, string $todayYmd): string
    {
        if (!$enabled || $fromYmd === null || $backAtYmd === null) {
            return self::STATE_OFF; // fehlende Daten: nie "ewig aktiv"
        }
        if ($todayYmd >= $backAtYmd) {
            return self::STATE_OFF; // ab dem Wieder-da-Tag ist HR zurueck
        }
        if ($todayYmd < $fromYmd) {
            return self::STATE_PENDING;
        }
        return self::STATE_ACTIVE;
    }

    public static function isActive(bool $enabled, ?string $fromYmd, ?string $backAtYmd, string $todayYmd): bool
    {
        return self::state($enabled, $fromYmd, $backAtYmd, $todayYmd) === self::STATE_ACTIVE;
    }
}
