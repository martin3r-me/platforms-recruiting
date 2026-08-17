<?php

namespace Platform\Recruiting\Support;

/**
 * Datumsrechnung auf Y-m-d-Strings, ohne Carbon — pure Klassen im Modul
 * laufen ohne Laravel-Bootstrap.
 *
 * Zwei Eigenheiten, die hier bewusst behandelt werden:
 *  - createFromFormat rollt ungueltige Daten still weiter ('2026-02-30' →
 *    2. Maerz). Die Round-Trip-Pruefung faengt das, damit ein Tippfehler
 *    nicht als plausibler Wert durchgeht.
 *  - Zeitzonen: alles in UTC, damit ein Sommerzeit-Wechsel keine halben Tage
 *    erzeugt.
 */
final class YmdDate
{
    public static function isValid(string $ymd): bool
    {
        return self::parse($ymd) !== null;
    }

    /** Ganze Tage von $fromYmd bis $toYmd; negativ moeglich, null = unlesbar. */
    public static function daysBetween(string $fromYmd, string $toYmd): ?int
    {
        $from = self::parse($fromYmd);
        $to = self::parse($toYmd);
        if ($from === null || $to === null) {
            return null;
        }

        return (int) $from->diff($to)->format('%r%a');
    }

    private static function parse(string $ymd): ?\DateTimeImmutable
    {
        $d = \DateTimeImmutable::createFromFormat(
            '!Y-m-d',
            $ymd,
            new \DateTimeZone('UTC'),
        );

        // Round-Trip: nur wenn die Rueckformatierung identisch ist, war die
        // Eingabe wirklich ein gueltiges Y-m-d.
        return ($d !== false && $d->format('Y-m-d') === $ymd) ? $d : null;
    }
}
