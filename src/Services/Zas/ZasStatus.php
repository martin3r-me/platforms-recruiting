<?php

namespace Platform\Recruiting\Services\Zas;

/**
 * Mitarbeiter-Status aus ZAS (Spalte `Status`) in Normalform.
 *
 * ZAS kennt GO (geringfuegig), MA (Mitarbeiter) und INAKTIV — letzteres in
 * wechselnder Schreibweise ("inaktiv", "Inaktive"). Alles, was mit INAKT
 * beginnt, gilt als INAKTIV. Leer heisst "nicht geliefert" (null), ein
 * unbekannter Wert bleibt grossgeschrieben erhalten, damit er im Bericht
 * sichtbar wird statt still verloren zu gehen.
 */
final class ZasStatus
{
    public const GO = 'GO';
    public const MA = 'MA';
    public const INAKTIV = 'INAKTIV';

    private const BEKANNT = [self::GO, self::MA, self::INAKTIV];

    public static function normalize(?string $raw): ?string
    {
        $s = mb_strtoupper(trim((string) $raw));
        if ($s === '') {
            return null;
        }
        if (str_starts_with($s, 'INAKT')) {
            return self::INAKTIV;
        }

        return $s;
    }

    public static function istBekannt(string $s): bool
    {
        return in_array($s, self::BEKANNT, true);
    }
}
