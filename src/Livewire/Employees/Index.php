<?php

namespace Platform\Recruiting\Livewire\Employees;

use Livewire\Attributes\Computed;
use Livewire\Component;
use Platform\Recruiting\Models\RecEmployee;
use Platform\Recruiting\Models\RecPosition;

/**
 * HR-Backend-Liste aller Mitarbeiter (rec_employees).
 *
 * Losgeloest vom Bewerber-Funnel. Filterbar nach Stelle + Status +
 * Such-Text. Click auf MA fuehrt zur Show-Page wo HR alle Felder
 * editieren kann (auch die Login-stabilen first_name/last_name/
 * birth_date/identity_card_number, im MA-Portal sind die verboten).
 */
class Index extends Component
{
    public string $search = '';
    public ?int $positionFilter = null;
    public string $activeFilter = 'active'; // 'active' | 'inactive' | 'all'

    public function updatingSearch(): void
    {
        // reset zu Page 1 bei Such-Aenderung — falls wir spaeter Pagination einbauen
    }

    public function resetFilters(): void
    {
        $this->search = '';
        $this->positionFilter = null;
        $this->activeFilter = 'active';
    }

    #[Computed]
    public function positions()
    {
        return RecPosition::forTeam(auth()->user()->currentTeam->id)
            ->orderBy('title')
            ->get(['id', 'title']);
    }

    #[Computed]
    public function employees()
    {
        $teamId = auth()->user()->currentTeam->id;

        $query = RecEmployee::with(['position', 'applicant'])
            ->where('team_id', $teamId);

        if ($this->activeFilter === 'active') {
            $query->where('is_active', true);
        } elseif ($this->activeFilter === 'inactive') {
            $query->where('is_active', false);
        }

        if ($this->positionFilter) {
            $query->where('rec_position_id', $this->positionFilter);
        }

        if ($this->search !== '') {
            $needle = '%' . $this->search . '%';
            $query->where(function ($q) use ($needle) {
                $q->where('first_name', 'like', $needle)
                  ->orWhere('last_name', 'like', $needle)
                  ->orWhere('email', 'like', $needle)
                  ->orWhere('phone', 'like', $needle);
            });
        }

        return $query->orderBy('last_name')->orderBy('first_name')->get();
    }

    public function render()
    {
        return view('recruiting::livewire.employees.index')
            ->layout('platform::layouts.app');
    }
}
