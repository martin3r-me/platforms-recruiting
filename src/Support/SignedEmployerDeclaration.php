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

        $rows = DB::table('rec_contracts as c')
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
            // Nicht nur den juengsten: ein juengerer Vertrag kann
            // unterschrieben sein, ohne eine Erklaerung zu tragen —
            // UpdateContractTool setzt signed_at ohne pre_signing_data. Blind
            // den juengsten zu nehmen wuerde eine vorhandene Erklaerung
            // verdecken und den Mitarbeiter ohne Angabe anlegen. Gewinner ist
            // der juengste, der WIRKLICH eine traegt.
            //
            // Die Grenze haelt die Abfrage beschraenkt; mehr als eine Handvoll
            // Arbeitsvertraege hat in der Praxis niemand.
            ->limit(10)
            ->get();

        foreach ($rows as $row) {
            if ($row->pre_signing_data === null) {
                continue;
            }

            $data = json_decode((string) $row->pre_signing_data, true);
            if (!is_array($data)) {
                continue;
            }

            $attributes = EmployerDeclaration::toEmployeeAttributes($data);
            if ($attributes !== []) {
                return $attributes;
            }
        }

        return [];
    }
}
