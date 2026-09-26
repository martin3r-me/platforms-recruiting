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
 * Im neuen Portal steht immer nur EINE Gruppe im Formular. Die Rueckfaelle
 * auf den Datensatz sind damit der Normalfall — und der Rueckfall beim
 * dreiwertigen is_main_employer geht ausdruecklich NICHT ueber (string):
 * (string) false ergibt '', also genau die Form, die der Waechter als
 * "unbeantwortet" liest. Wer ordentlich "nein" geantwortet hat, koennte
 * dann nie wieder speichern (E11/R18).
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
 * Waechter betroffen. RICHTIGSTELLUNG (Fixrunde 2, 26.09.2026): das ist NICHT
 * "der Gleichstand mit EmployeePortal::saveAll()" — das alte Portal ruft
 * diese Klasse gar nicht auf, es hat seine eigene, unberuehrte Kaskade (drei
 * direkte Aufrufe der Waechter in EmployeePortal.php). Der gruppenlose Pfad
 * ist der Vollspeicherungs-Pfad DIESES Schreibwegs (PortalProfileWriter) —
 * aktuell ohne Produktionsaufrufer.
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
     * Die drei Waechter mit ihren Feldern -- EINE Quelle, in der bindenden
     * Reihenfolge der Kaskade.
     *
     * Oeffentlich seit dem 26.09.2026 (Schlussfix F1): Ring, Offen-Zaehler
     * und Start-Bildschirm muessen wissen, WELCHE Angaben Pflicht sind. Bis
     * dahin wusste das nur diese Klasse, und der Offen-Zaehler zaehlte
     * ausschliesslich Nachweise -- der Start-Bildschirm sagte "Wir haben
     * alles, was wir von dir brauchen", waehrend der Ring daneben die
     * fehlende Staatsangehoerigkeit anmahnte.
     *
     * Wer hier einen Waechter ergaenzt, ergaenzt ihn damit ueberall: die
     * Anzeige tippt keine zweite Liste ab, sie liest diese.
     *
     * @var array<string, list<string>>
     */
    public const WAECHTER = [
        'ersthelfer'           => self::FIRST_AIDER_FELDER,
        'staatsangehoerigkeit' => self::NATIONALITY_FELDER,
        'hauptarbeitgeber'     => self::MAIN_EMPLOYER_FELDER,
    ];

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

    /**
     * Blockt genau DIESER Waechter den Datensatz, so wie er heute dasteht?
     *
     * Gemessen wird mit demselben fehler() wie beim Speichern -- ohne
     * Formularwerte (es steht gerade nichts im Formular) und mit einer
     * Reichweite aus genau den Feldern dieses einen Waechters. Damit kann
     * die Anzeige nicht auseinanderlaufen mit dem, was beim Speichern
     * wirklich passiert: es ist derselbe Aufruf.
     *
     * @param array<string,mixed> $datensatz gecastete Werte des Mitarbeiters
     */
    public static function blocktWaechter(string $name, array $datensatz): bool
    {
        $felder = self::WAECHTER[$name] ?? null;
        if ($felder === null) {
            return false;
        }

        return self::fehler([], $datensatz, array_fill_keys($felder, true)) !== null;
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
