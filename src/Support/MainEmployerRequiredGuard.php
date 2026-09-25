<?php

namespace Platform\Recruiting\Support;

/**
 * Pflichtangabe Haupt-/Nebenarbeitgeber im MA-Portal (Markus 24.09.2026:
 * "Die Angabe Haupt-/Nebenarbeitgeber soll fuer den Bewerber Pflicht sein").
 *
 * Endzustands-Pruefung wie NationalityRequiredGuard: blockt auch Saves, die
 * nur andere Felder aendern. An der Angabe haengt die Steuerklasse — ein
 * Bestands-MA soll sie beim naechsten Portalbesuch nachtragen muessen und
 * nicht beliebig lange umgehen koennen.
 *
 * Der Name des anderen Arbeitgebers ist NUR bei "nein" Pflicht. Wer sagt,
 * wir seien der Hauptarbeitgeber, darf trotzdem nebenher woanders arbeiten —
 * erlaubt, aber keine Bringschuld.
 *
 * NUR Portal. Die HR-Akte (Employees/Show) ruft das bewusst nicht auf, sonst
 * waeren Bestandsakten ohne Wert fuer HR unspeicherbar (gleiche Regel wie
 * beim Ersthelfer-Schein und bei der Staatsangehoerigkeit).
 *
 * Pure Funktion auf den rohen Formularwerten (Strings aus wire:model).
 */
final class MainEmployerRequiredGuard
{
    /** Fehlertext oder null, wenn die Angaben vollstaendig sind. */
    public static function error(mixed $isMainEmployer, mixed $otherEmployer): ?string
    {
        $flag = trim((string) ($isMainEmployer ?? ''));

        if ($flag === '') {
            return 'Angabe zum Hauptarbeitgeber fehlt: bitte im Profil auswaehlen — sie ist Pflicht. Es wurde nichts gespeichert.';
        }

        // Nur bei "nein" brauchen wir den Namen: dann laeuft die Anmeldung
        // als Nebenbeschaeftigung, und dafuer muss feststehen, wo der
        // Hauptarbeitgeber sitzt.
        if ($flag === '0' && trim((string) ($otherEmployer ?? '')) === '') {
            return 'Name des Hauptarbeitgebers fehlt: bitte eintragen, wenn wir nicht der Hauptarbeitgeber sind. Es wurde nichts gespeichert.';
        }

        return null;
    }
}
