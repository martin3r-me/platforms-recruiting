<?php

namespace Platform\Recruiting\Services\Zeit;

use Carbon\CarbonImmutable;
use Platform\Recruiting\Services\Comms\TeamClock;

/**
 * Anzeige und Eingabe von Zeiten in Ortszeit. Gespeichert wird in der
 * PHP-Standard-Zeitzone (Host: UTC), Schichten und Formulare sind Berliner Zeit.
 */
final class ZeitAnzeige
{
    private static function zone(): string
    {
        return TeamClock::resolveTimezone(null);
    }

    /** Zeitpunkt in der Team-Zone (fuer die Anzeige). */
    public static function ortszeit(?\DateTimeInterface $t): ?CarbonImmutable
    {
        return $t === null ? null : CarbonImmutable::instance($t)->setTimezone(self::zone());
    }

    /** H:i in Ortszeit, leer bei null. */
    public static function uhrzeit(?\DateTimeInterface $t): string
    {
        return self::ortszeit($t)?->format('H:i') ?? '';
    }

    /** Parst Y-m-d\TH:i bzw. Y-m-d H:i ALS Ortszeit; null bei ungueltig. */
    public static function ausEingabe(string $ortszeitString): ?CarbonImmutable
    {
        $text = trim($ortszeitString);
        foreach (['Y-m-d\TH:i', 'Y-m-d H:i'] as $format) {
            $t = \DateTimeImmutable::createFromFormat('!' . $format, $text, new \DateTimeZone(self::zone()));
            $fehler = \DateTimeImmutable::getLastErrors();
            if ($t !== false && ($fehler === false || ($fehler['warning_count'] === 0 && $fehler['error_count'] === 0))) {
                $ergebnis = CarbonImmutable::instance($t);
                // Nicht existierende Ortszeit (Umstellungsluecke) wuerde still weiterrollen.
                return $ergebnis->format($format) === $text ? $ergebnis : null;
            }
        }

        return null;
    }
}
