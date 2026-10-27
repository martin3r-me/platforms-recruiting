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

    /**
     * Wellenplan je Anlass (Kunde 27.10.2026, Folie 4/6): hoechstens so viele
     * Personen je Welle, naechste Welle automatisch nach so vielen Minuten.
     * Je Anfrage aenderbar; keine Einstellungsseite.
     */
    public const WELLEN = [
        'dringend'       => ['max' => 20, 'abstand_minuten' => 360],
        'naechste_woche' => ['max' => 50, 'abstand_minuten' => 1440],
        'vier_wochen'    => ['max' => 50, 'abstand_minuten' => 4320],
    ];

    /** Auswahl „Naechste Welle automatisch nach“: Minuten => Beschriftung; 0 = keine. */
    public const ABSTAENDE = [
        0 => 'keine', 120 => '2 Stunden', 360 => '6 Stunden', 720 => '12 Stunden',
        1440 => '24 Stunden', 2880 => '2 Tage', 4320 => '3 Tage', 7200 => '5 Tage',
    ];

    /** @return array{max:?int, abstand_minuten:?int} */
    public static function wellen(string $anlass): array
    {
        return self::WELLEN[$anlass] ?? ['max' => null, 'abstand_minuten' => null];
    }

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
