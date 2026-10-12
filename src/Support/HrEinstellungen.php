<?php

namespace Platform\Recruiting\Support;

use Platform\Recruiting\Models\RecApplicantSettings;

/**
 * Die drei Team-Einstellungen des HR-Bereichs (Spec §6, §7.2) — gelesen NUR
 * hier, mit Begrenzung. Liest ohne Zeile anzulegen (kein getOrCreateForTeam):
 * eine Leseabfrage darf keine Einstellungszeile erzeugen.
 */
final class HrEinstellungen
{
    public const NOTFALL_WEG   = 'konto_notfall_weg_aktiv';
    public const ERINNERT_TAGE = 'hr_erinnert_tage';
    public const RUHE_TAGE     = 'hr_kontaktiert_ruhe_tage';

    public static function notfallWegAktiv(?int $teamId): bool
    {
        return $teamId !== null && (bool) self::wert($teamId, self::NOTFALL_WEG);
    }

    public static function erinnertTage(int $teamId): int
    {
        return self::begrenze(self::wert($teamId, self::ERINNERT_TAGE), 1, 60, 5);
    }

    public static function kontaktiertRuheTage(int $teamId): int
    {
        return self::begrenze(self::wert($teamId, self::RUHE_TAGE), 1, 30, 3);
    }

    /** Leer oder unlesbar = Vorgabe (ein geleertes Feld soll nicht "1 Tag" heissen). */
    public static function begrenze(mixed $wert, int $min, int $max, int $vorgabe): int
    {
        if ($wert === null || $wert === '' || !is_numeric($wert)) {
            return $vorgabe;
        }

        return max($min, min($max, (int) $wert));
    }

    private static function wert(int $teamId, string $schluessel): mixed
    {
        $zeile = RecApplicantSettings::query()->where('team_id', $teamId)->first();

        return $zeile !== null
            ? $zeile->getSetting($schluessel)
            : (RecApplicantSettings::DEFAULT_SETTINGS[$schluessel] ?? null);
    }
}
