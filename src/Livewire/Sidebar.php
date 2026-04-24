<?php

namespace Platform\Recruiting\Livewire;

use Livewire\Component;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Platform\Core\Models\Team;
use Platform\Recruiting\Models\RecApplicant;
use Platform\Recruiting\Models\RecPosition;
use Platform\Recruiting\Models\RecPosting;

class Sidebar extends Component
{
    #[Computed]
    public function recentApplicants()
    {
        $teamId = auth()->user()->currentTeam->id;
        $allowedTeamIds = $this->getAllowedTeamIds($teamId);

        return RecApplicant::with([
            'crmContactLinks' => fn ($q) => $q->whereIn('team_id', $allowedTeamIds),
            'crmContactLinks.contact',
            'applicantStatus',
        ])
            ->forTeam($teamId)
            ->active()
            ->latest()
            ->take(5)
            ->get();
    }

    #[Computed]
    public function stats()
    {
        $teamId = auth()->user()->currentTeam->id;

        return [
            'total_positions' => RecPosition::forTeam($teamId)->count(),
            'active_positions' => RecPosition::forTeam($teamId)->active()->count(),
            'total_postings' => RecPosting::forTeam($teamId)->count(),
            'active_postings' => RecPosting::forTeam($teamId)->active()->count(),
            'total_applicants' => RecApplicant::forTeam($teamId)->count(),
            'active_applicants' => RecApplicant::forTeam($teamId)->active()->count(),
            'parked_applicants' => RecApplicant::forTeam($teamId)->where('is_active', true)->where('is_parked', true)->count(),
            'hr_desk_applicants' => RecApplicant::forTeam($teamId)->where('is_active', true)->where('is_on_hr_desk', true)->count(),
        ];
    }

    #[On('sidebar-refresh')]
    public function refreshSidebar(): void
    {
        unset($this->stats, $this->recentApplicants);
    }

    public function render()
    {
        return view('recruiting::livewire.sidebar');
    }

    private function getAllowedTeamIds(int $teamId): array
    {
        $team = Team::find($teamId);
        if (!$team) {
            return [$teamId];
        }

        return array_merge([$teamId], $team->getAllAncestors()->pluck('id')->all());
    }
}
