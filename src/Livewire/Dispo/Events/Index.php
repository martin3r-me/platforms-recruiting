<?php

namespace Platform\Recruiting\Livewire\Dispo\Events;

use Livewire\Attributes\Computed;
use Livewire\Component;
use Platform\Recruiting\Models\RecDispoEvent;

/**
 * Disposition → Veranstaltungen: VAs aus dem ZAS-Webexport.
 * Kommende zuerst; Vergangene per Toggle. Team-los (siehe Migration).
 */
class Index extends Component
{
    public bool $showPast = false;

    public string $dateFrom = '';

    public string $dateTo = '';

    public string $filialeFilter = '';

    #[Computed]
    public function filialeOptions(): array
    {
        return RecDispoEvent::query()
            ->whereNotNull('filiale')
            ->where('filiale', '!=', '')
            ->distinct()
            ->orderBy('filiale')
            ->pluck('filiale')
            ->all();
    }

    private function isValidDate(string $value): bool
    {
        return (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $value);
    }

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

        $query->when(
            $this->dateFrom !== '' && $this->isValidDate($this->dateFrom),
            fn ($q) => $q->whereDate('starts_on', '>=', $this->dateFrom)
        );

        $query->when(
            $this->dateTo !== '' && $this->isValidDate($this->dateTo),
            fn ($q) => $q->whereDate('starts_on', '<=', $this->dateTo)
        );

        $query->when(
            $this->filialeFilter !== '',
            fn ($q) => $q->where('filiale', $this->filialeFilter)
        );

        $events = $query->orderByRaw('starts_on IS NULL, starts_on ASC')->get();

        return view('recruiting::livewire.dispo.events.index', ['events' => $events])
            ->layout('platform::layouts.app');
    }
}
