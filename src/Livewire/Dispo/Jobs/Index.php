<?php

namespace Platform\Recruiting\Livewire\Dispo\Jobs;

use Livewire\Component;
use Platform\Recruiting\Models\RecDispoJobOfferShift;
use Platform\Recruiting\Models\RecDispoJobRequest;
use Platform\Recruiting\Services\Zas\Dispo\Jobs\DispoJobAnfragen;
use Platform\Recruiting\Services\Zas\Dispo\Jobs\DispoJobOffeneZusagen;

/**
 * Jobs anbieten → Uebersicht der Anfragen des Teams (neueste zuerst).
 * Beim Laden werden Anfragen abgeschlossen, deren letzter Tag vorbei ist (Spec §4.7).
 */
class Index extends Component
{
    private const LIMIT = 200;

    public function mount(): void
    {
        DispoJobAnfragen::abgelaufeneSchliessen($this->teamId());
    }

    private function teamId(): int
    {
        return (int) (config('recruiting.zas.inbound_team_id') ?: auth()->user()->currentTeam->id);
    }

    public function render()
    {
        $anfragen = RecDispoJobRequest::query()
            ->where('team_id', $this->teamId())
            ->withCount('shifts')
            ->with(['waves' => fn ($q) => $q->orderByDesc('nr')])
            ->orderByDesc('id')
            ->limit(self::LIMIT)
            ->get();

        $ids = $anfragen->pluck('id')->all();
        $zusagen = $ids === [] ? [] : RecDispoJobOfferShift::query()
            ->join('rec_dispo_job_offers', 'rec_dispo_job_offers.id', '=', 'rec_dispo_job_offer_shifts.offer_id')
            ->whereIn('rec_dispo_job_offers.request_id', $ids)
            ->whereNotNull('rec_dispo_job_offer_shifts.accepted_at')
            ->groupBy('rec_dispo_job_offers.request_id')
            ->selectRaw('rec_dispo_job_offers.request_id as request_id, COUNT(*) as anzahl')
            ->pluck('anzahl', 'request_id')
            ->map(fn ($v) => (int) $v)
            ->all();

        return view('recruiting::livewire.dispo.jobs.index', ['anfragen' => $anfragen, 'zusagen' => $zusagen, 'offeneZusagen' => DispoJobOffeneZusagen::count($this->teamId())])
            ->layout('platform::layouts.app');
    }
}
