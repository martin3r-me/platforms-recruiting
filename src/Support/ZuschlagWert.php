<?php

namespace Platform\Recruiting\Support;

/**
 * Der Zuschlag in Euro je Stunde — EINE Stelle fuer Eingabe, Lesen und
 * Anzeige. Gespeichert wird er am Vertrag als Text im deutschen Format
 * ("0,60"), wie ReissueContractService::createSuccessor() es schon tut;
 * gelesen wird alles, was je dort stand ("0,60", "0.60", 0.6).
 */
final class ZuschlagWert
{
    /** HR-Eingabe: Ziffern, optional Komma/Punkt, hoechstens zwei Stellen (wie reissueContract()). */
    public static function ausEingabe(?string $roh): ?float
    {
        $s = trim((string) $roh);
        if (!preg_match('/^\d{1,3}([.,]\d{1,2})?$/', $s)) {
            return null;
        }

        return round((float) str_replace(',', '.', $s), 2);
    }

    public static function lesen(mixed $wert): ?float
    {
        if (is_int($wert) || is_float($wert)) {
            return round((float) $wert, 2);
        }
        $s = str_replace(',', '.', trim((string) $wert));

        return ($s !== '' && is_numeric($s)) ? round((float) $s, 2) : null;
    }

    public static function format(float $zuschlag): string
    {
        return number_format($zuschlag, 2, ',', '.');
    }

    /** Alt-Varianten mit Betrag im Code: AV-060 → 0,60 (wie ZasEmployeeFieldResolver::getZuschlag()). */
    public static function ausAvCode(?string $code): ?float
    {
        if (!preg_match('/^AV-(\d{3})$/', trim((string) $code), $m)) {
            return null;
        }

        return round(((int) $m[1]) / 100, 2);
    }
}
