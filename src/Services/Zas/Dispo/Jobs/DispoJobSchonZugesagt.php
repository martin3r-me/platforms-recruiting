<?php

namespace Platform\Recruiting\Services\Zas\Dispo\Jobs;

use Platform\Recruiting\Models\RecDispoJobOfferShift;

/**
 * Spec §9: welche kuenftigen Schichten hat eine Person (Anker) schon in
 * irgendeiner Anfrage des Teams zugesagt? Fuer DispoJobRecipientPlanner —
 * eine anderswo zugesagte Schicht wird nicht noch einmal angeboten.
 */
final class DispoJobSchonZugesagt
{
    /** @return array<int, list<string>> Anker => DispoJobRecipientPlanner::schichtSchluessel() */
    public static function laden(int $teamId): array
    {
        $heute = now()->toDateString();
        $out = [];
        RecDispoJobOfferShift::query()
            ->whereNotNull('accepted_at')
            ->whereHas('shift', fn ($q) => $q->whereDate('datum', '>=', $heute)
                ->whereHas('request', fn ($r) => $r->where('team_id', $teamId)))
            ->with(['offer:id,anchor_employee_id', 'shift:id,rec_dispo_event_id,datum,von,taetigkeit'])
            ->get(['id', 'offer_id', 'shift_id'])
            ->each(function ($os) use (&$out) {
                if ($os->offer === null || $os->shift === null) {
                    return;
                }
                $out[(int) $os->offer->anchor_employee_id][] = DispoJobRecipientPlanner::schichtSchluessel(
                    $os->shift->rec_dispo_event_id, $os->shift->datum->format('Y-m-d'), $os->shift->von, $os->shift->taetigkeit
                );
            });

        return array_map(fn ($keys) => array_values(array_unique($keys)), $out);
    }
}
