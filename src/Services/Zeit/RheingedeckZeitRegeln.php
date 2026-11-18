<?php

namespace Platform\Recruiting\Services\Zeit;

use Carbon\CarbonImmutable;
use Platform\Recruiting\Zeit\ZeitBezug;
use Platform\Recruiting\Zeit\ZeitRegeln;

/**
 * Rheingedeck-Regeln fuers Stempeln (Spec 2026-11-16 §2.2; Fenster und Rundung nach Markus 17./18.11.2026:
 * Check-in ab 30 Minuten vor Schichtbeginn, Check-out bis 4 Stunden nach dem geplanten Ende,
 * jede Zeit auf die naechste Viertelstunde).
 */
final class RheingedeckZeitRegeln implements ZeitRegeln
{
    public const VOR_MINUTEN = 30;
    public const NACH_MINUTEN = 240;
    public const RUNDUNG_MINUTEN = 15;

    /** Kommen fruehestens 30 Minuten vor Schichtbeginn; ohne Beginn kein Selbst-Stempeln. */
    public function fruehestesKommen(ZeitBezug $b): ?CarbonImmutable
    {
        return $b->sollVon?->subMinutes(self::VOR_MINUTEN);
    }

    /** Selbst einchecken geht bis zum geplanten Schichtende — danach traegt der Teamleiter nach. */
    public function spaetestesKommen(ZeitBezug $b): ?CarbonImmutable
    {
        return $b->sollBis;
    }

    /** Selbst auschecken spaetestens 4 Stunden nach dem geplanten Schichtende. */
    public function spaetestesStempeln(ZeitBezug $b): ?CarbonImmutable
    {
        return $b->sollBis?->addMinutes(self::NACH_MINUTEN);
    }

    /** Naechste Viertelstunde, die Mitte (7:30) rundet auf. Zone bleibt die des Eingangswerts. */
    public function runde(\DateTimeInterface $zeit): CarbonImmutable
    {
        $z = CarbonImmutable::instance($zeit);
        $schritt = self::RUNDUNG_MINUTEN * 60;
        $sekunden = $z->getTimestamp();

        return $z->setTimestamp(intdiv($sekunden + intdiv($schritt, 2), $schritt) * $schritt);
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
