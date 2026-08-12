<?php

namespace Platform\Recruiting\Livewire\Dispo\Events;

use Livewire\Component;
use Platform\Recruiting\Models\RecDispoEvent;

/**
 * Disposition → Veranstaltungen: VAs aus dem ZAS-Webexport.
 * Kommende zuerst; Vergangene per Toggle. Team-los (siehe Migration).
 */
class Index extends Component
{
    public bool $showPast = false;

    public function render()
    {
        $query = RecDispoEvent::query()
            ->withCount([
                'assignments',
                'assignments as matched_count' => fn ($q) => $q->whereNotNull('rec_employee_id'),
            ]);

        if (!$this->showPast) {
            $query->where(fn ($q) => $q->whereNull('ends_on')->orWhereDate('ends_on', '>=', now()->toDateString()));
        }

        $events = $query->orderByRaw('starts_on IS NULL, starts_on ASC')->get();

        return view('recruiting::livewire.dispo.events.index', ['events' => $events])
            ->layout('platform::layouts.app');
    }
}
