<?php

namespace Platform\Recruiting\Services\Zas\Dispo\Jobs;

use Platform\Recruiting\Models\RecDispoAssignment;
use Platform\Recruiting\Models\RecDispoJobOfferShift;
use Platform\Recruiting\Services\Zas\Dispo\DispoIdentityResolver;

/**
 * Verknuepft zugesagte Angebots-Schichten mit der aus ZAS angekommenen
 * Einbuchung (gleiche Person-Gruppe, gleiche VA, gleicher Tag, Status Auftrag).
 */
class DispoJobZasAbgleich
{
    /** @return int Anzahl neu gesetzter zas_assignment_id */
    public function run(): int
    {
        $offen = RecDispoJobOfferShift::query()
            ->with(['offer:id,rec_employee_id,anchor_employee_id', 'shift:id,rec_dispo_event_id,datum'])
            ->whereNotNull('accepted_at')->whereNull('zas_assignment_id')->get();
        if ($offen->isEmpty()) {
            return 0;
        }

        $employeeIds = $offen->pluck('offer.rec_employee_id')->map(fn ($v) => (int) $v)->unique()->values()->all();
        $groups = app(DispoIdentityResolver::class)->groupsFor($employeeIds);
        $gesetzt = 0;
        foreach ($offen as $os) {
            if ($os->offer === null || $os->shift === null) {
                continue;
            }
            $ids = $groups[(int) $os->offer->rec_employee_id] ?? [(int) $os->offer->rec_employee_id];
            $a = RecDispoAssignment::query()->whereIn('rec_employee_id', $ids)
                ->where('rec_dispo_event_id', $os->shift->rec_dispo_event_id)
                ->whereDate('datum', $os->shift->datum->format('Y-m-d'))
                ->where('status_id', RecDispoAssignment::STATUS_AUFTRAG)
                ->whereNull('missing_since')->whereNull('deletion_marked_at')
                ->orderBy('id')->first(['id']);
            if ($a !== null) {
                $os->update(['zas_assignment_id' => (int) $a->id]);
                $gesetzt++;
            }
        }

        return $gesetzt;
    }
}
