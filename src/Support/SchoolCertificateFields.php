<?php

namespace Platform\Recruiting\Support;

/**
 * Welche Schul-/Immatrikulationsbescheinigungs-Felder das MA-Portal fuer
 * einen Beschaeftigungsstatus ("Ich bin", lookup beschaeftigung_art)
 * anzeigt: Schueler laden die Schulbescheinigung hoch, Studenten die
 * Immatrikulationsbescheinigung, alle anderen (und leerer Typ) nichts.
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
            'student'  => ['immatrikulation_file_id', 'school_certificate_valid_until'],
            default    => [],
        };
    }
}
