<?php

namespace Platform\Recruiting\Support;

/**
 * Nachleseweg fuer die Arbeitgeber-Erklaerung.
 *
 * Der Mitarbeiter entsteht erst beim Abschluss der Schulungsphase
 * (creates_employee_on_completion), die Erklaerung faellt aber schon bei der
 * Vertragsunterschrift an. Je nach Reihenfolge gibt es zu dem Zeitpunkt noch
 * keinen Mitarbeiter — dann holt CreateEmployeeFromApplicantService sie hier
 * bei der Anlage nach.
 *
 * Welche Vertraege zaehlen, entscheidet SignedContractDeclarations — dort
 * stehen die Auswahlregeln fuer alle Leser gemeinsam. Gewinner ist der
 * juengste Vertrag, der wirklich eine Arbeitgeber-Erklaerung traegt.
 */
final class SignedEmployerDeclaration
{
    /**
     * @return array{is_main_employer?: bool, other_employer?: ?string}
     */
    public static function forApplicant(?int $applicantId): array
    {
        return self::fromDeclarations(SignedContractDeclarations::preSigningDataFor($applicantId));
    }

    /**
     * Variante fuer Aufrufer, die die Erklaerungen schon gelesen haben — die
     * MA-Anlage braucht dieselbe Liste auch fuer den Startwert des
     * Tagekontos und soll die Abfrage nicht zweimal stellen.
     *
     * @param  list<array<string,mixed>> $declarations juengste zuerst
     * @return array{is_main_employer?: bool, other_employer?: ?string}
     */
    public static function fromDeclarations(array $declarations): array
    {
        foreach ($declarations as $data) {
            $attributes = EmployerDeclaration::toEmployeeAttributes($data);
            if ($attributes !== []) {
                return $attributes;
            }
        }

        return [];
    }
}
