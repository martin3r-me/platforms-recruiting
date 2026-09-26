<?php

namespace Platform\Recruiting\Support;

/**
 * Pflichtfeld Staatsangehoerigkeit im MA-Portal (Kundenentscheidung
 * 23.09.2026): ohne Wert verlaesst kein Save die Maske. Endzustands-Pruefung
 * wie FirstAiderDateGuard — blockt auch Saves, die nur andere Felder aendern,
 * damit ein Bestands-MA ohne Wert beim naechsten Besuch nachtraegt.
 *
 * ZWEI PORTALE, ZWEI REICHWEITEN (Fixrunde 2 zu Aufgabe 6, 26.09.2026): diese
 * Zusicherung — blockt JEDEN Save, unabhaengig davon, welches Feld sich
 * geaendert hat — gilt VOLLSTAENDIG im ALTEN Portal (EmployeePortal::
 * saveAll() ruft diesen Waechter direkt auf, ausserhalb jeder Gruppe, ALLE
 * Felder stehen dort auf EINER Seite) und im gruppenlosen Pfad von
 * PortalProfileWriter/PortalProfileGuards (Vollspeicherung ohne Gruppe,
 * aktuell ohne Produktionsaufrufer). Im NEUEN Portal (PortalShell,
 * gruppenweises Speichern seit C1) gilt sie NICHT MEHR fuer JEDEN Save,
 * sondern nur noch, wenn die Gruppe offen ist, die nationality selbst
 * enthaelt (Adresse) — Speichern einer anderen Gruppe (Bankdaten,
 * Arbeitgeber, ...) blockt nicht mehr deswegen. Grund: "blockt auch andere
 * Felder" funktioniert nur, solange alle Felder auf einer Seite stehen —
 * bei Gruppen wuerde daraus ein Deadlock, der das ganze Profil einfriert,
 * sobald zwei cross-cutting Pflichtangaben gleichzeitig fehlen (siehe
 * PortalProfileGuards-Docblock). Was den Druck im neuen Portal ersetzt: der
 * Vollstaendigkeitsring und der Offen-Zaehler im Start-Bereich — die
 * fehlende Angabe bleibt sichtbar, blockt aber keine fremde Gruppe mehr.
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
