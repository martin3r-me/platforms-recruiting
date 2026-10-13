<?php

namespace Platform\Recruiting\Services\Zas\Dispo\Jobs;

use Platform\Recruiting\Models\RecDispoJobOfferShift;
use Platform\Recruiting\Models\RecDispoJobRequest;
use Platform\Recruiting\Models\RecDispoJobShift;

/**
 * Zahlen der Uebersicht (Runde 2, Spec §10): besetzt / gesucht und „noch in ZAS“
 * je Anfrage, wenige Queries fuer alle. `besetzt` deckelt je Schicht auf gesucht
 * (wie DispoJobFortschritt), `zugesagt` bleibt die rohe Summe.
 */
final class DispoJobUebersichtZahlen
{
    /** @param list<int> $requestIds @return array<int, array{zugesagt:int, gesucht:int, noch_zas:int, besetzt:int}> */
    public static function laden(array $requestIds): array
    {
        $ids = array_values(array_unique(array_map('intval', $requestIds)));
        if ($ids === []) {
            return [];
        }
        $leer = ['zugesagt' => 0, 'gesucht' => 0, 'noch_zas' => 0, 'besetzt' => 0];
        $out = [];
        $schichten = RecDispoJobShift::query()->whereIn('request_id', $ids)->get(['id', 'request_id', 'gesucht']);
        foreach ($schichten as $s) {
            $out[(int) $s->request_id] ??= $leer;
            $out[(int) $s->request_id]['gesucht'] += (int) $s->gesucht;
        }
        $zeilen = RecDispoJobOfferShift::query()
            ->join('rec_dispo_job_offers as o', 'o.id', '=', 'rec_dispo_job_offer_shifts.offer_id')
            ->whereIn('o.request_id', $ids)
            ->whereNotNull('rec_dispo_job_offer_shifts.accepted_at')
            ->groupBy('o.request_id', 'rec_dispo_job_offer_shifts.shift_id')
            ->selectRaw('o.request_id as request_id, rec_dispo_job_offer_shifts.shift_id as shift_id, COUNT(*) as zugesagt, SUM(CASE WHEN rec_dispo_job_offer_shifts.zas_entered_at IS NULL AND rec_dispo_job_offer_shifts.zas_assignment_id IS NULL THEN 1 ELSE 0 END) as noch_zas')
            ->get();
        $gesuchtJeSchicht = $schichten->pluck('gesucht', 'id');
        foreach ($zeilen as $r) {
            $id = (int) $r->request_id;
            $out[$id] ??= $leer;
            $out[$id]['zugesagt'] += (int) $r->zugesagt;
            $out[$id]['noch_zas'] += (int) $r->noch_zas;
            $out[$id]['besetzt'] += min((int) $r->zugesagt, max(0, (int) ($gesuchtJeSchicht[(int) $r->shift_id] ?? 0)));
        }
        // Anfragen ohne Schicht/Zusage bekommen Nullen — unbekannte IDs nicht.
        foreach (RecDispoJobRequest::query()->whereIn('id', $ids)->pluck('id') as $id) {
            $out[(int) $id] ??= $leer;
        }

        return $out;
    }
}
