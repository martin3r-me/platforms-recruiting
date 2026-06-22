<?php

namespace Platform\Recruiting\Livewire;

use Livewire\Component;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Platform\Core\Models\Team;
use Platform\Recruiting\Models\RecApplicant;
use Platform\Recruiting\Models\RecEmployee;
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

        $commsCounts = app(\Platform\Recruiting\Services\Comms\ConversationInboxService::class)->counts($teamId);

        return [
            'unread_conversations' => $commsCounts['unread'],
            'escalation_conversations' => $commsCounts['escalation'],
            'total_positions' => RecPosition::forTeam($teamId)->count(),
            'active_positions' => RecPosition::forTeam($teamId)->active()->count(),
            'total_postings' => RecPosting::forTeam($teamId)->count(),
            'active_postings' => RecPosting::forTeam($teamId)->active()->count(),
            'total_applicants' => RecApplicant::forTeam($teamId)->count(),
            'active_applicants' => RecApplicant::forTeam($teamId)->active()
                ->whereHas('postings.position', fn ($q) => $q->where('title', 'not like', '% bis %'))
                ->count(),
            'legacy_applicants' => RecApplicant::forTeam($teamId)->active()
                ->whereHas('postings.position', fn ($q) => $q->where('title', 'like', '% bis %'))
                ->count(),
            'parked_applicants' => RecApplicant::forTeam($teamId)->where('is_active', true)->where('is_parked', true)->count(),
            'hr_desk_applicants' => RecApplicant::forTeam($teamId)->where('is_active', true)->where('is_on_hr_desk', true)->count(),
            'unrouted_applicants' => RecApplicant::forTeam($teamId)->where('is_active', true)->where('is_unrouted', true)->count(),
            'direct_hire_positions' => RecPosition::forTeam($teamId)->directHire()->where('is_active', true)->count(),
            'direct_hire_new' => RecApplicant::forTeam($teamId)->where('is_active', true)->where('is_parked', false)
                ->where(fn ($q) => $q->whereNull('rec_phase_id')
                    ->orWhereHas('phase', fn ($q2) => $q2->where('order', 1)))
                ->whereHas('postings.position', fn ($q) => $q->where('is_direct_hire', true)->where('is_active', true))
                ->count(),
            'active_employees' => RecEmployee::where('team_id', $teamId)->where('is_active', true)->count(),
            'pending_payroll_changes' => RecEmployee::where('team_id', $teamId)
                ->where('is_active', true)
                ->whereNotNull('payroll_data_changed_at')
                ->count(),
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
