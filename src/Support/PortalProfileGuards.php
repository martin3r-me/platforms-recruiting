<?php

namespace Platform\Recruiting\Support;

/**
 * Die drei Waechter des Mitarbeiterportals, in der bindenden Reihenfolge aus
 * EmployeePortal::saveAll() (Bestandsaufnahme §2.1): Ersthelfer,
 * Staatsangehoerigkeit, Hauptarbeitgeber — jeder mit Early-Return.
 *
 * Die Reihenfolge ist Verhalten, keine Formalie: der erste Fehler gewinnt,
 * und der Mensch soll beim naechsten Speichern nicht dreimal hintereinander
 * eine andere Meldung sehen. Eine parallele Sammelvalidierung wuerde andere
 * Fehlertexte zeigen als heute.
 *
 * GEDREHT 25.09.2026, Fixrunde 1 zu Aufgabe 6 (Ruling des Koordinators, C1):
 * ein Waechter blockt nur noch, wenn mindestens eines SEINER Felder in der
 * uebergebenen REICHWEITE liegt. Vorher liefen alle drei immer, unabhaengig
 * davon, welche Gruppe gerade gespeichert wird — im alten Portal war das
 * richtig, weil mount() ALLE Felder auf einmal ins Formular laedt und der
 * Mensch Staatsangehoerigkeit UND Hauptarbeitgeber auf derselben Seite
 * beantworten kann. Im neuen, gruppenweisen Portal fror das das GANZE Profil
 * ein, sobald zwei cross-cutting Pflichtangaben gleichzeitig fehlten: die
 * Adresse-Gruppe (enthaelt nationality) liess sich nicht speichern, weil
 * is_main_employer fehlte, UND die Arbeitgeber-Gruppe liess sich nicht
 * speichern, weil nationality fehlte — ein echter Ping-Pong-Deadlock, aus
 * dem der Mensch nicht mehr herauskam (nicht mal die Schuhgroesse liess sich
 * noch speichern). Fund und Beleg: PortalShellProfilTest, Fixrunde 1.
 *
 * Bei einer VOLLSPEICHERUNG ohne Gruppe (reichweite = ALLE editierbaren
 * Felder, siehe PortalProfileWriter) sind zwangslaeufig immer alle drei
 * Waechter betroffen — der Gleichstand mit EmployeePortal::saveAll() bleibt
 * fuer diesen Pfad also unangetastet.
 */
final class PortalProfileGuards
{
    /** @var list<string> Felder, an denen der Ersthelfer-Waechter haengt. */
    private const FIRST_AIDER_FELDER = ['is_first_aider', 'first_aider_valid_until', 'first_aider_certificate_file_id'];

    /** @var list<string> */
    private const NATIONALITY_FELDER = ['nationality'];

    /** @var list<string> */
    private const MAIN_EMPLOYER_FELDER = ['is_main_employer', 'other_employer'];

    /**
     * Fehlertext der ersten verletzten Regel oder null, wenn der Endzustand
     * (Formularwert, sonst Datensatz) alle BETROFFENEN Waechter passiert.
     *
     * @param array<string,mixed> $formwerte  rohe wire:model-Werte der offenen Gruppe
     * @param array{
     *     is_first_aider:?bool,
     *     first_aider_valid_until:mixed,
     *     first_aider_certificate_file_id:?int,
     *     nationality:?string,
     *     is_main_employer:?bool,
     *     other_employer:?string,
     * } $datensatz gecastete Werte des Mitarbeiters
     * @param array<string,mixed> $reichweite Felder der GERADE zu speichernden
     *        Reichweite (die offene Gruppe, oder ALLE editierbaren Felder bei
     *        einer Vollspeicherung) -- ein Waechter blockt nur, wenn
     *        mindestens eines seiner Felder darin vorkommt (C1).
     */
    public static function fehler(array $formwerte, array $datensatz, array $reichweite): ?string
    {
        // 1. Ersthelfer, MIT Dokumentpflicht (Portal). Die File-Id kommt
        //    immer vom Datensatz, nie aus dem Formular (R15, E8) — Dateien
        //    laufen ueber eigene Upload-Properties, nicht ueber $formwerte.
        if (self::betrifft($reichweite, self::FIRST_AIDER_FELDER)) {
            $fehler = FirstAiderDateGuard::error(
                $formwerte['is_first_aider'] ?? self::dreiwertig($datensatz['is_first_aider'] ?? null),
                $formwerte['first_aider_valid_until'] ?? ($datensatz['first_aider_valid_until'] ?? null),
                $datensatz['first_aider_certificate_file_id'] ?? null,
                true,
            );
            if ($fehler !== null) {
                return $fehler;
            }
        }

        // 2. Staatsangehoerigkeit (R16)
        if (self::betrifft($reichweite, self::NATIONALITY_FELDER)) {
            $fehler = NationalityRequiredGuard::error(
                $formwerte['nationality'] ?? ($datensatz['nationality'] ?? null),
            );
            if ($fehler !== null) {
                return $fehler;
            }
        }

        // 3. Haupt-/Nebenarbeitgeber (R17, R18)
        if (self::betrifft($reichweite, self::MAIN_EMPLOYER_FELDER)) {
            return MainEmployerRequiredGuard::error(
                $formwerte['is_main_employer'] ?? self::dreiwertig($datensatz['is_main_employer'] ?? null),
                $formwerte['other_employer'] ?? ($datensatz['other_employer'] ?? null),
            );
        }

        return null;
    }

    /** Betrifft dieser Waechter die Reichweite -- liegt mindestens eines seiner Felder darin? */
    private static function betrifft(array $reichweite, array $felder): bool
    {
        foreach ($felder as $feld) {
            if (array_key_exists($feld, $reichweite)) {
                return true;
            }
        }

        return false;
    }

    /** null bleibt null, true/false werden '1'/'0' — NIE (string) (E11). */
    private static function dreiwertig(?bool $wert): ?string
    {
        return $wert === null ? null : ($wert ? '1' : '0');
    }
}
