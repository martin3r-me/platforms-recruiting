<?php

namespace Platform\Recruiting\Livewire\Dispo\Jobs;

use Livewire\Attributes\Url;
use Livewire\Component;
use Platform\Recruiting\Models\RecDispoJobRequest;
use Platform\Recruiting\Services\Zas\Dispo\Jobs\DispoJobAnfragen;
use Platform\Recruiting\Services\Zas\Dispo\Jobs\DispoJobOffeneZusagen;
use Platform\Recruiting\Services\Zas\Dispo\Jobs\DispoJobUebersichtZahlen;

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

    #[Url]
    public string $filter = 'offen';

    public function render()
    {
        $filter = in_array($this->filter, ['offen', 'zu', 'alle'], true) ? $this->filter : 'offen';
        $anfragen = RecDispoJobRequest::query()
            ->where('team_id', $this->teamId())
            ->when($filter === 'offen', fn ($q) => $q->whereNull('closed_at')->where('status', '!=', RecDispoJobRequest::STATUS_ABGESCHLOSSEN))
            ->when($filter === 'zu', fn ($q) => $q->where(fn ($w) => $w->whereNotNull('closed_at')->orWhere('status', RecDispoJobRequest::STATUS_ABGESCHLOSSEN)))
            ->withCount('shifts')
            ->with(['waves' => fn ($q) => $q->orderByDesc('nr')])
            ->orderByDesc('id')
            ->limit(self::LIMIT)
            ->get();

        return view('recruiting::livewire.dispo.jobs.index', [
            'anfragen'      => $anfragen,
            'zahlen'        => DispoJobUebersichtZahlen::laden($anfragen->pluck('id')->all()),
            'filter'        => $filter,
            'offeneZusagen' => DispoJobOffeneZusagen::count($this->teamId()),
        ])->layout('platform::layouts.app');
    }
}
