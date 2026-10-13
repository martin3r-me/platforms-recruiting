<?php

namespace Platform\Recruiting\Services\Zas\Dispo\Jobs;

use Platform\Recruiting\Models\RecDispoJobOfferShift;
use Platform\Recruiting\Models\RecDispoJobRequest;
use Platform\Recruiting\Models\RecDispoJobShift;

/**
 * Spec Runde 1 §9: Angebots-Zeilen derselben Person fuer DIESELBE Schicht
 * (VA, Tag, Beginn, Taetigkeit) in anderen laufenden Anfragen. Die Zusage
 * behandelt sie mit.
 */
final class JobOffersSchwestern
{
    /**
     * @param list<int> $employeeIds
     * @return list<RecDispoJobOfferShift>
     */
    public static function finden(RecDispoJobShift $shift, array $employeeIds, int $ohneId): array
    {
        $schluessel = DispoJobRecipientPlanner::schichtSchluessel($shift->rec_dispo_event_id, $shift->datum->format('Y-m-d'), $shift->von, $shift->taetigkeit);

        return RecDispoJobOfferShift::query()
            ->with('shift')
            ->where('id', '!=', $ohneId)
            ->whereNull('accepted_at')
            ->whereHas('offer', fn ($q) => $q->whereIn('rec_employee_id', $employeeIds))
            ->whereHas('shift', fn ($q) => $q->where('rec_dispo_event_id', $shift->rec_dispo_event_id)
                ->whereDate('datum', $shift->datum->format('Y-m-d'))
                ->where('id', '!=', $shift->id)
                ->whereHas('request', fn ($r) => $r->where('status', RecDispoJobRequest::STATUS_LAEUFT)->whereNull('closed_at')))
            ->get()
            // Beginn/Taetigkeit wie auf der Seite vergleichen (trim, Gross-/Kleinschreibung).
            ->filter(fn ($s) => $s->shift !== null && DispoJobRecipientPlanner::schichtSchluessel(
                $s->shift->rec_dispo_event_id, $s->shift->datum->format('Y-m-d'), $s->shift->von, $s->shift->taetigkeit
            ) === $schluessel)
            ->values()->all();
    }
}
