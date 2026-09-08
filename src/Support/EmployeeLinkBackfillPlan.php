<?php

namespace Platform\Recruiting\Support;

/**
 * Entscheidet, welche Link-Nachtraege (rec_employees.rec_applicant_id) gesetzt
 * werden duerfen — getrennt vom Schreiben, damit die Regel testbar ist.
 *
 * Eingang sind die Ansprueche aus EmployeeMatchResolver::linkableEmployeeIds()
 * ueber ALLE Bewerber: Mitarbeiter-ID => Liste der Bewerber, die diesen MA
 * beanspruchen. Beansprucht mehr als ein Bewerber denselben MA, wird NICHT
 * geschrieben: das sind Namensvettern mit gleichem Geburtsdatum, und ein
 * falscher Link haengt einem Menschen die Vertraege eines anderen an die Akte
 * (ZasEmployeeFileController loest die Personalakte ueber rec_applicant_id
 * auf). Ein fehlender Link kostet einen zweiten Lauf, ein falscher kostet
 * Vertrauen.
 *
 * Ein Bewerber mit ZWEI Mitarbeitern ist dagegen erlaubt und normal: ZAS
 * bedient zwei Firmen (RG-/MA-Nummer), eine Person kann bei beiden angestellt
 * sein — beide Zeilen zeigen dann legitim auf denselben Bewerber.
 *
 * Pure PHP, keine Laravel-Abhaengigkeit.
 */
final class EmployeeLinkBackfillPlan
{
    /**
     * @param array<int,array<int,int>> $claims Mitarbeiter-ID => Bewerber-IDs
     * @return array{link:array<int,int>,ambiguous:array<int,array<int,int>>}
     */
    public static function build(array $claims): array
    {
        $link = [];
        $ambiguous = [];

        foreach ($claims as $employeeId => $applicantIds) {
            $applicantIds = array_values(array_unique(array_map('intval', $applicantIds)));
            sort($applicantIds);

            if (count($applicantIds) === 1) {
                $link[(int) $employeeId] = $applicantIds[0];
                continue;
            }

            $ambiguous[(int) $employeeId] = $applicantIds;
        }

        ksort($link);
        ksort($ambiguous);

        return ['link' => $link, 'ambiguous' => $ambiguous];
    }
}
