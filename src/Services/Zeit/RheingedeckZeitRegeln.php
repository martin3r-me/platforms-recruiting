<?php

namespace Platform\Recruiting\Services\Zeit;

use Carbon\CarbonImmutable;
use Platform\Recruiting\Zeit\ZeitBezug;
use Platform\Recruiting\Zeit\ZeitRegeln;

/** Rheingedeck-Regeln fuers Stempeln (Spec 2026-11-16 §2.2). */
final class RheingedeckZeitRegeln implements ZeitRegeln
{
    public const VOR_MINUTEN = 60;
    public const NACH_MINUTEN = 60;

    /** Kommen fruehestens 60 Minuten vor Schichtbeginn; ohne Beginn kein Selbst-Stempeln. */
    public function fruehestesKommen(ZeitBezug $b): ?CarbonImmutable
    {
        return $b->sollVon?->subMinutes(self::VOR_MINUTEN);
    }

    /** Stempeln (Kommen wie Gehen) spaetestens 60 Minuten nach Schichtende. */
    public function spaetestesStempeln(ZeitBezug $b): ?CarbonImmutable
    {
        return $b->sollBis?->addMinutes(self::NACH_MINUTEN);
    }

    /** Mehr als 6 Stunden anwesend: 30 Minuten, mehr als 9 Stunden: 45, sonst 0. */
    public function pauseMinuten(int $anwesendMinuten): int
    {
        if ($anwesendMinuten > 540) {
            return 45;
        }

        return $anwesendMinuten > 360 ? 30 : 0;
    }
}
