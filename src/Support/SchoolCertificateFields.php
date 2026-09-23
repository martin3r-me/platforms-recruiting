<?php

namespace Platform\Recruiting\Support;

/**
 * Welche Schul-/Immatrikulationsbescheinigungs-Felder das MA-Portal fuer
 * einen Beschaeftigungsstatus ("Ich bin", lookup beschaeftigung_art)
 * anzeigt: Schueler laden die Schulbescheinigung hoch, Studenten die
 * Immatrikulationsbescheinigung, alle anderen (und leerer Typ) nichts.
 *
 * `student_erwerbstaetig` ist seit 23.09.2026 ein eigener Lookup-Wert
 * (Clara-Liste 28.08.2026). Davor uebersetzte ZasLookupReverseResolver die
 * ZAS-Schreibweise "Student erwerbst." auf `student` — die Nachweispflicht
 * bestand also bereits und musste beim Aufteilen mitwandern, sonst haetten
 * genau diese Leute sie stillschweigend verloren.
 *
 * Gilt bewusst NUR fuers MA-Portal (RecEmployee::editableFieldGroups) —
 * die HR-Ansicht (Employees/Show) zeigt weiterhin beide Felder, damit HR
 * auch bei (noch) falsch gesetztem Typ korrigieren kann. Der ZAS-Export
 * kodiert dieselbe Semantik in BeschErforderlich (ZasEmployeeFieldResolver).
 *
 * Reine Logik (kein Framework/DB) → pure-unit-testbar.
 */
final class SchoolCertificateFields
{
    /** @return list<string> Feld-Keys der sichtbaren Bescheinigungs-Felder */
    public static function forEmploymentType(?string $employmentType): array
    {
        return match ($employmentType) {
            'schueler' => ['schulbescheinigung_file_id', 'school_certificate_valid_until'],
            'student', 'student_erwerbstaetig' => ['immatrikulation_file_id', 'school_certificate_valid_until'],
            default    => [],
        };
    }

    /**
     * Braucht dieser Status ueberhaupt eine Bescheinigung?
     *
     * Der ZAS-Export (BeschErforderlich) fuehrte dieselbe Regel bis
     * 23.09.2026 als zweite Liste. Zwei Listen fuer eine Frage waren die
     * Falle, in die jeder neue Status laufen konnte; beide Aufrufer lesen
     * jetzt hier.
     */
    public static function requiresCertificate(?string $employmentType): bool
    {
        return self::forEmploymentType($employmentType) !== [];
    }
}
