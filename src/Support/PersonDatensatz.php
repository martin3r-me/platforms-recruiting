<?php

namespace Platform\Recruiting\Support;

/**
 * Personenweiter Wert eines Feldes (Spec 2026-10-22 §3): eigener Wert, wenn
 * gefuellt; sonst der erste gefuellte Wert der AKTIVEN Schwestern derselben
 * Person, juengste zuerst (Reihenfolge liefert der Aufrufer). Nur fuer
 * PersonenFelder::SPIEGELN — was je Gesellschaft gilt, bleibt je Akte.
 *
 * Reine Regel, kein Framework/DB.
 */
final class PersonDatensatz
{
    /** Leer = null, '' oder []. false/0 ist gefuellt (Spec §6). */
    public static function istLeer(mixed $wert): bool
    {
        return $wert === null || $wert === '' || $wert === [];
    }

    /** @param iterable<mixed> $schwesternWerte */
    public static function wert(string $feld, mixed $eigen, iterable $schwesternWerte): mixed
    {
        if (!self::istLeer($eigen) || !in_array($feld, PersonenFelder::SPIEGELN, true)) {
            return $eigen;
        }
        foreach ($schwesternWerte as $w) {
            if (!self::istLeer($w)) {
                return $w;
            }
        }

        return $eigen;
    }
}
