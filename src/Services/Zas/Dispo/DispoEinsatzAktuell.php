<?php

namespace Platform\Recruiting\Services\Zas\Dispo;

/**
 * Wann ein Einsatz auf der Einsatz-Seite noch oben („Deine Einsaetze“) steht
 * und wann er zu den vergangenen gehoert (Kunde 28.10.: Anhaenge waren um
 * 00:00 Uhr weg, obwohl der Check-out erst danach war).
 *
 * Regel: aktuell, solange der Tag nicht vorbei ist ODER das Schichtende plus
 * Nachlauf noch nicht erreicht ist. Endzeit kleiner/gleich Beginn heisst Ende
 * am Folgetag. Ohne Endzeit zaehlt nur der Tag. Pur, damit testbar.
 */
final class DispoEinsatzAktuell
{
    public const NACHLAUF_STUNDEN = 6;

    public static function istAktuell(string $datum, ?string $von, ?string $bis, \DateTimeInterface $now): bool
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $datum)) {
            return false;
        }
        $heute = \DateTimeImmutable::createFromInterface($now)->format('Y-m-d');
        if ($datum >= $heute) {
            return true;
        }

        $ende = self::ende($datum, $von, $bis, $now->getTimezone());
        if ($ende === null) {
            return false;
        }

        return $ende->modify('+' . self::NACHLAUF_STUNDEN . ' hours') > $now;
    }

    /**
     * Fruehestes Datum, das die Datenbank-Abfrage noch holen muss, damit der
     * PHP-Filter entscheiden kann: der Vortag (laenger als Ende + Nachlauf
     * ragt kein Einsatz ueber Mitternacht hinaus).
     */
    public static function abDatum(\DateTimeInterface $now): string
    {
        return \DateTimeImmutable::createFromInterface($now)->modify('-1 day')->format('Y-m-d');
    }

    private static function ende(string $datum, ?string $von, ?string $bis, \DateTimeZone $tz): ?\DateTimeImmutable
    {
        if ($bis === null || !preg_match('/^(\d{2}):(\d{2})/', $bis, $b)) {
            return null;
        }
        $ende = new \DateTimeImmutable($datum . ' ' . $b[1] . ':' . $b[2] . ':00', $tz);
        if ($von !== null && preg_match('/^(\d{2}):(\d{2})/', $von, $v) && $bis <= $von) {
            $ende = $ende->modify('+1 day');
        }

        return $ende;
    }
}
