<?php

namespace Platform\Recruiting\Services\Zas\Dispo\Jobs;

use Platform\Recruiting\Models\RecDispoJobOfferShift;
use Platform\Recruiting\Models\RecDispoJobRequest;
use Platform\Recruiting\Models\RecDispoJobShift;

/** Zahlen der Uebersicht (Runde 2, Spec §10): zugesagt / gesucht und „noch in ZAS“ je Anfrage, zwei Queries fuer alle. */
final class DispoJobUebersichtZahlen
{
    /** @param list<int> $requestIds @return array<int, array{zugesagt:int, gesucht:int, noch_zas:int}> */
    public static function laden(array $requestIds): array
    {
        $ids = array_values(array_unique(array_map('intval', $requestIds)));
        if ($ids === []) {
            return [];
        }
        $out = [];
        foreach (RecDispoJobShift::query()->whereIn('request_id', $ids)->groupBy('request_id')->selectRaw('request_id, SUM(gesucht) as gesucht')->get() as $r) {
            $out[(int) $r->request_id] = ['zugesagt' => 0, 'gesucht' => (int) $r->gesucht, 'noch_zas' => 0];
        }
        $zeilen = RecDispoJobOfferShift::query()
            ->join('rec_dispo_job_offers as o', 'o.id', '=', 'rec_dispo_job_offer_shifts.offer_id')
            ->whereIn('o.request_id', $ids)
            ->whereNotNull('rec_dispo_job_offer_shifts.accepted_at')
            ->groupBy('o.request_id')
            ->selectRaw('o.request_id as request_id, COUNT(*) as zugesagt, SUM(CASE WHEN rec_dispo_job_offer_shifts.zas_entered_at IS NULL AND rec_dispo_job_offer_shifts.zas_assignment_id IS NULL THEN 1 ELSE 0 END) as noch_zas')
            ->get();
        foreach ($zeilen as $r) {
            $id = (int) $r->request_id;
            $out[$id] ??= ['zugesagt' => 0, 'gesucht' => 0, 'noch_zas' => 0];
            $out[$id]['zugesagt'] = (int) $r->zugesagt;
            $out[$id]['noch_zas'] = (int) $r->noch_zas;
        }
        // Anfragen ohne Schicht/Zusage bekommen Nullen — unbekannte IDs nicht.
        foreach (RecDispoJobRequest::query()->whereIn('id', $ids)->pluck('id') as $id) {
            $out[(int) $id] ??= ['zugesagt' => 0, 'gesucht' => 0, 'noch_zas' => 0];
        }

        return $out;
    }
}
