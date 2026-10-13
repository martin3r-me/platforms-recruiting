<?php

namespace Platform\Recruiting\Services\Zas\Dispo\Jobs;

use Platform\Recruiting\Models\RecApplicantSettings;

/**
 * Anlass einer Anfrage „Jobs anbieten“ (Kunde 13.10.2026): drei feste Faelle,
 * je Fall eine Vorlage in Disposition → Einstellungen. Rein.
 */
final class DispoJobAnlass
{
    public const ALLE = [
        'vier_wochen'    => 'Nächste vier Wochen',
        'naechste_woche' => 'Nächste Woche',
        'dringend'       => 'Dringend',
    ];

    public static function istGueltig(string $anlass): bool
    {
        return array_key_exists($anlass, self::ALLE);
    }

    public static function settingsKey(string $anlass): string
    {
        return 'dispo_job_template_' . $anlass . '_id';
    }

    /** Lesbarer Name; unbekannter Anlass → der Schluessel selbst. */
    public static function label(string $anlass): string
    {
        return self::ALLE[$anlass] ?? $anlass;
    }

    /** @return array<string, ?int> Anlass => Vorlagen-ID (null = keine hinterlegt) */
    public static function templateIds(RecApplicantSettings $s): array
    {
        $out = [];
        foreach (array_keys(self::ALLE) as $anlass) {
            $v = $s->getSetting(self::settingsKey($anlass));
            $id = null;
            if (is_int($v)) {
                $id = $v;
            } elseif (is_string($v) && ctype_digit($v)) {
                $id = (int) $v;
            }
            $out[$anlass] = ($id !== null && $id > 0) ? $id : null;
        }

        return $out;
    }
}
