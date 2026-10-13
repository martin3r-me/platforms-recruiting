<?php

namespace Platform\Recruiting\Services\Zas\Dispo\Jobs;

use Platform\Recruiting\Models\RecDispoJobOfferShift;
use Platform\Recruiting\Models\RecDispoJobRequest;

/**
 * Badge „Jobs anbieten“ in der Sidebar (Runde 2, Spec §7): Zusagen, die der
 * Disponent noch in ZAS eintragen muss — angenommen, ohne Haken, nicht
 * angekommen, Anfrage offen, Schicht nicht vorbei. Wirft nie (Sidebar).
 */
final class DispoJobOffeneZusagen
{
    public static function count(?int $teamId = null): int
    {
        try {
            $teamId ??= (int) (config('recruiting.zas.inbound_team_id') ?: (auth()->user()?->currentTeam?->id ?? 0));
            if ($teamId <= 0) {
                return 0;
            }

            return (int) RecDispoJobOfferShift::query()
                ->join('rec_dispo_job_shifts as s', 's.id', '=', 'rec_dispo_job_offer_shifts.shift_id')
                ->join('rec_dispo_job_requests as r', 'r.id', '=', 's.request_id')
                ->where('r.team_id', $teamId)
                ->whereNull('r.closed_at')
                ->where('r.status', '!=', RecDispoJobRequest::STATUS_ABGESCHLOSSEN)
                ->whereDate('s.datum', '>=', now()->toDateString())
                ->whereNotNull('rec_dispo_job_offer_shifts.accepted_at')
                ->whereNull('rec_dispo_job_offer_shifts.zas_entered_at')
                ->whereNull('rec_dispo_job_offer_shifts.zas_assignment_id')
                ->count();
        } catch (\Throwable) {
            return 0;
        }
    }
}
