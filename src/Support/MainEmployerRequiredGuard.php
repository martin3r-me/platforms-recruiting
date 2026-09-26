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
 * ZWEI PORTALE, ZWEI REICHWEITEN (Fixrunde 2 zu Aufgabe 6, 26.09.2026): "blockt
 * auch Saves, die nur andere Felder aendern" gilt VOLLSTAENDIG im ALTEN
 * Portal (EmployeePortal::saveAll() ruft diesen Waechter direkt auf,
 * ausserhalb jeder Gruppe, ALLE Felder stehen dort auf EINER Seite) und im
 * gruppenlosen Pfad von PortalProfileWriter/PortalProfileGuards
 * (Vollspeicherung ohne Gruppe, aktuell ohne Produktionsaufrufer). Im NEUEN
 * Portal (PortalShell, gruppenweises Speichern seit C1) blockt sie NICHT
 * MEHR jeden Save, sondern nur noch das Speichern der Gruppe "Arbeitgeber"
 * selbst — eine fehlende Angabe hier haelt Bankdaten, Ausweis oder
 * Arbeitskleidung nicht mehr auf. Grund: die alte Regel funktioniert nur,
 * solange alle Felder auf einer Seite stehen — bei Gruppen wuerde daraus ein
 * Deadlock, der das ganze Profil einfriert, sobald zwei cross-cutting
 * Pflichtangaben gleichzeitig fehlen (siehe PortalProfileGuards-Docblock).
 * Was den Druck im neuen Portal ersetzt: der Vollstaendigkeitsring und der
 * Offen-Zaehler im Start-Bereich.
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
    /**
     * Laenge der Spalte rec_employees.other_employer. Ohne Grenze schlaegt
     * ein laengerer Wert als SQLSTATE 22001 durch und reisst den ganzen
     * Speichervorgang mit — derselbe Abbruch, der am 25.08.2026 die
     * MA-Anlage gekillt hat.
     */
    public const MAX_OTHER_EMPLOYER = 128;

    /** Fehlertext oder null, wenn die Angaben vollstaendig sind. */
    public static function error(mixed $isMainEmployer, mixed $otherEmployer): ?string
    {
        // Gemeinsame Quelle mit dem Schreibpfad: was PortalBoolValue nicht
        // versteht, wuerde als NULL gespeichert — der Guard muss es also als
        // unbeantwortet abweisen, sonst meldet das Portal "gespeichert",
        // waehrend eine gueltige Antwort still geloescht wurde.
        $flag  = PortalBoolValue::parse($isMainEmployer);
        $other = trim((string) ($otherEmployer ?? ''));

        if ($flag === null) {
            return 'Angabe zum Hauptarbeitgeber fehlt: bitte im Profil auswaehlen — sie ist Pflicht. Es wurde nichts gespeichert.';
        }

        // Nur bei "nein" brauchen wir den Namen: dann laeuft die Anmeldung
        // als Nebenbeschaeftigung, und dafuer muss feststehen, wo der
        // Hauptarbeitgeber sitzt.
        if ($flag === false && $other === '') {
            return 'Name des Hauptarbeitgebers fehlt: bitte eintragen, wenn wir nicht der Hauptarbeitgeber sind. Es wurde nichts gespeichert.';
        }

        if (mb_strlen($other) > self::MAX_OTHER_EMPLOYER) {
            return 'Name des Arbeitgebers ist zu lang: bitte auf ' . self::MAX_OTHER_EMPLOYER
                . ' Zeichen kuerzen. Es wurde nichts gespeichert.';
        }

        return null;
    }
}
