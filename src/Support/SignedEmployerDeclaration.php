<?php

namespace Platform\Recruiting\Support;

use Illuminate\Support\Facades\DB;

/**
 * Nachleseweg fuer die Arbeitgeber-Erklaerung.
 *
 * Der Mitarbeiter entsteht erst beim Abschluss der Schulungsphase
 * (creates_employee_on_completion), die Erklaerung faellt aber schon bei der
 * Vertragsunterschrift an. Je nach Reihenfolge gibt es zu dem Zeitpunkt noch
 * keinen Mitarbeiter — dann holt CreateEmployeeFromApplicantService sie hier
 * bei der Anlage nach.
 *
 * Auswahlkriterien wie bei ZasEmployeeFieldResolver::avContractExtraDate
 * (Praefix AV, nicht storniert), zusaetzlich: nur unterschriebene Vertraege.
 * Ein verschickter, aber nicht unterzeichneter Vertrag ist keine Erklaerung.
 *
 * Bewusst ueber den Query Builder statt ueber das Modell: hier wird nur
 * gelesen, und der Aufrufer (MA-Anlage) laeuft in einem Pfad, in dem
 * Beziehungen noch nicht geladen sind.
 */
final class SignedEmployerDeclaration
{
    /**
     * @return array{is_main_employer?: bool, other_employer?: ?string}
     */
    public static function forApplicant(?int $applicantId): array
    {
        if (!$applicantId) {
            return [];
        }

        $row = DB::table('rec_contracts as c')
            ->join('rec_contract_templates as t', 'c.rec_contract_template_id', '=', 't.id')
            ->where('c.rec_applicant_id', $applicantId)
            ->whereNotNull('c.signed_at')
            ->where('c.status', '!=', 'cancelled')
            // Gleiches Praedikat wie ContractPreSigningType::forCode — nur
            // Vertraege, die den Schritt ueberhaupt bekommen, koennen eine
            // Erklaerung tragen. 'AV%' waere weiter und wuerde Vorlagen
            // einsammeln, denen nie eine Erklaerung geschrieben wurde.
            ->where('t.code', 'like', 'AV-%')
            // signed_at zuerst, id als Stichentscheid: zwei Vertraege am
            // selben Tag sind moeglich (Neuausstellung).
            ->orderByDesc('c.signed_at')
            ->orderByDesc('c.id')
            ->select('c.pre_signing_data')
            ->first();

        if (!$row || $row->pre_signing_data === null) {
            return [];
        }

        $data = json_decode((string) $row->pre_signing_data, true);
        if (!is_array($data)) {
            return [];
        }

        return EmployerDeclaration::toEmployeeAttributes($data);
    }
}
