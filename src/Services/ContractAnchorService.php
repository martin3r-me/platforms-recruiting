<?php

namespace Platform\Recruiting\Services;

use Illuminate\Support\Facades\DB;
use Platform\Recruiting\Models\RecContract;
use Platform\Recruiting\Models\RecEmployee;

/**
 * Haengt die Vertraege eines Bewerbers an seine frisch angelegte Anstellung
 * (Spec Vertrag an der Anstellung §3.3 a). Alle Arten — AV, IFSG, AT —, nur
 * mit leerem Anker, nur wenn die Vorlage die Firma der Anstellung traegt
 * (RecContractTemplate::giltFuerAnstellung, die eine Stelle).
 *
 * Query Builder, nicht RecContract::save(): der saved-Hook des Vertrags
 * schreibt beim Signieren auf die HR-Daten und wuerde hier den ZAS-Marker
 * stempeln (RecEmployeeExportObserver auf RecEmployeeHrData::saved).
 */
class ContractAnchorService
{
    public function anAnstellungHaengen(RecEmployee $anstellung): int
    {
        if ($anstellung->rec_applicant_id === null) {
            return 0;
        }

        $ids = RecContract::query()
            ->where('rec_applicant_id', $anstellung->rec_applicant_id)
            ->whereNull('rec_employee_id')
            ->with('contractTemplate')
            ->get(['id', 'rec_contract_template_id'])
            ->filter(fn (RecContract $c) => $c->contractTemplate?->giltFuerAnstellung($anstellung) === true)
            ->pluck('id')
            ->all();

        if ($ids === []) {
            return 0;
        }

        return DB::table('rec_contracts')
            ->whereIn('id', $ids)
            ->update(['rec_employee_id' => $anstellung->id, 'updated_at' => now()]);
    }
}
