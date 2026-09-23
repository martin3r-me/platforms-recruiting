<?php

namespace Platform\Recruiting\Support;

/**
 * Pflichtfeld Staatsangehoerigkeit im MA-Portal (Kundenentscheidung
 * 23.09.2026): ohne Wert verlaesst kein Save die Maske. Endzustands-Pruefung
 * wie FirstAiderDateGuard — blockt auch Saves, die nur andere Felder aendern,
 * damit ein Bestands-MA ohne Wert beim naechsten Besuch nachtraegt.
 *
 * NUR Portal. Die HR-Akte (Employees/Show) ruft das bewusst nicht: HR darf
 * nicht an einem Feld haengenbleiben, das der Mitarbeiter liefern muss —
 * sonst waeren Bestandsakten ohne Wert fuer HR unspeicherbar.
 *
 * Pure Funktion auf dem rohen Formularwert (String aus wire:model).
 */
final class NationalityRequiredGuard
{
    /** Fehlertext oder null, wenn ein Wert vorliegt. */
    public static function error(mixed $nationality): ?string
    {
        if (trim((string) ($nationality ?? '')) !== '') {
            return null;
        }

        return 'Staatsangehoerigkeit fehlt: bitte im Profil auswaehlen — sie ist Pflicht. Es wurde nichts gespeichert.';
    }
}
