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
 */
final class PortalProfileGuards
{
    /**
     * Fehlertext der ersten verletzten Regel oder null, wenn der Endzustand
     * (Formularwert, sonst Datensatz) alle drei Waechter passiert.
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
     */
    public static function fehler(array $formwerte, array $datensatz): ?string
    {
        // 1. Ersthelfer, MIT Dokumentpflicht (Portal). Die File-Id kommt
        //    immer vom Datensatz, nie aus dem Formular (R15, E8) — Dateien
        //    laufen ueber eigene Upload-Properties, nicht ueber $formwerte.
        $fehler = FirstAiderDateGuard::error(
            $formwerte['is_first_aider'] ?? self::dreiwertig($datensatz['is_first_aider'] ?? null),
            $formwerte['first_aider_valid_until'] ?? ($datensatz['first_aider_valid_until'] ?? null),
            $datensatz['first_aider_certificate_file_id'] ?? null,
            true,
        );
        if ($fehler !== null) {
            return $fehler;
        }

        // 2. Staatsangehoerigkeit (R16)
        $fehler = NationalityRequiredGuard::error(
            $formwerte['nationality'] ?? ($datensatz['nationality'] ?? null),
        );
        if ($fehler !== null) {
            return $fehler;
        }

        // 3. Haupt-/Nebenarbeitgeber (R17, R18)
        return MainEmployerRequiredGuard::error(
            $formwerte['is_main_employer'] ?? self::dreiwertig($datensatz['is_main_employer'] ?? null),
            $formwerte['other_employer'] ?? ($datensatz['other_employer'] ?? null),
        );
    }

    /** null bleibt null, true/false werden '1'/'0' — NIE (string) (E11). */
    private static function dreiwertig(?bool $wert): ?string
    {
        return $wert === null ? null : ($wert ? '1' : '0');
    }
}
