<?php

namespace Platform\Recruiting\Livewire\Applicant;

use Livewire\Component;
use Livewire\Attributes\Computed;
use Platform\Core\Models\Team;
use Platform\Recruiting\Models\RecApplicant;
use Platform\Recruiting\Models\RecApplicantStatus;
use Platform\Recruiting\Models\RecAutoPilotState;
use Platform\Recruiting\Models\RecPosition;
use Platform\Crm\Models\CrmContact;

class Index extends Component
{
    // Modal State
    public $modalShow = false;

    // Search & Filters
    public $search = '';
    public $positionFilter = null;
    public $statusFilter = null;
    public $autoPilotStateFilter = null;
    public $activeFilter = null;

    // Sorting
    public $sortField = 'created_at';
    public $sortDirection = 'desc';

    // Form Data
    public $contact_id = null;
    public $rec_applicant_status_id = null;
    public $applied_at = null;
    public $notes = '';

    protected $rules = [
        'rec_applicant_status_id' => 'nullable|exists:rec_applicant_statuses,id',
        'applied_at' => 'nullable|date',
        'notes' => 'nullable|string',
    ];

    #[Computed]
    public function applicants()
    {
        $teamId = auth()->user()->currentTeam->id;
        $allowedTeamIds = $this->getAllowedTeamIds($teamId);

        $query = RecApplicant::with([
            'crmContactLinks' => fn ($q) => $q->whereIn('team_id', $allowedTeamIds),
            'crmContactLinks.contact.emailAddresses' => function ($q) {
                $q->active()->orderByDesc('is_primary')->orderBy('id');
            },
            'applicantStatus',
            'autoPilotState',
            'ownedByUser',
            'postings.position',
        ])->forTeam($teamId);

        // Search
        if (!empty($this->search)) {
            $searchTerm = '%' . $this->search . '%';
            $query->where(function ($q) use ($searchTerm) {
                $q->whereHas('crmContactLinks.contact', function ($contactQuery) use ($searchTerm) {
                    $contactQuery->where('last_name', 'like', $searchTerm)
                        ->orWhere('first_name', 'like', $searchTerm)
                        ->orWhereRaw("CONCAT(first_name, ' ', last_name) LIKE ?", [$searchTerm]);
                });
            });
        }

        // Position filter
        if ($this->positionFilter) {
            $query->whereHas('postings', function ($q) {
                $q->where('rec_position_id', $this->positionFilter);
            });
        }

        // Status filter
        if ($this->statusFilter) {
            $query->where('rec_applicant_status_id', $this->statusFilter);
        }

        // AutoPilot state filter
        if ($this->autoPilotStateFilter) {
            $query->where('auto_pilot_state_id', $this->autoPilotStateFilter);
        }

        // Active filter
        if ($this->activeFilter !== null && $this->activeFilter !== '') {
            $query->where('is_active', (bool) $this->activeFilter);
        }

        $query->orderBy($this->sortField, $this->sortDirection);

        return $query->get();
    }

    #[Computed]
    public function availableStatuses()
    {
        return RecApplicantStatus::where('team_id', auth()->user()->currentTeam->id)
            ->where('is_active', true)
            ->orderBy('name')
            ->get();
    }

    #[Computed]
    public function availablePositions()
    {
        return RecPosition::forTeam(auth()->user()->currentTeam->id)
            ->active()
            ->orderBy('title')
            ->get();
    }

    #[Computed]
    public function availableAutoPilotStates()
    {
        return RecAutoPilotState::where(function ($q) {
            $q->whereNull('team_id')
                ->orWhere('team_id', auth()->user()->currentTeam->id);
        })->where('is_active', true)->orderBy('name')->get();
    }

    #[Computed]
    public function availableContacts()
    {
        $alreadyLinkedContactIds = \Platform\Crm\Models\CrmContactLink::query()
            ->where('linkable_type', 'rec_applicant')
            ->whereHas('linkable', function ($q) {
                $q->where('team_id', auth()->user()->currentTeam->id);
            })
            ->pluck('contact_id');

        return CrmContact::active()
            ->where('team_id', auth()->user()->currentTeam->id)
            ->whereNotIn('id', $alreadyLinkedContactIds)
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->get();
    }

    public function sortBy($field)
    {
        if ($this->sortField === $field) {
            $this->sortDirection = $this->sortDirection === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortField = $field;
            $this->sortDirection = 'asc';
        }
    }

    public function getAutoPilotColor(RecApplicant $applicant): string
    {
        $code = $applicant->autoPilotState?->code;
        return match ($code) {
            'completed' => 'green',
            'waiting_for_applicant', 'data_collection', 'contact_check' => 'yellow',
            'review_needed' => 'red',
            default => 'gray',
        };
    }

    public function rendered(): void
    {
        $this->dispatch('extrafields', [
            'context_type' => RecPosition::class,
            'context_id' => null,
        ]);
    }

    public function render()
    {
        return view('recruiting::livewire.applicant.index')
            ->layout('platform::layouts.app');
    }

    public function createApplicant()
    {
        $this->validate();

        $applicant = RecApplicant::create([
            'rec_applicant_status_id' => $this->rec_applicant_status_id,
            'applied_at' => $this->applied_at,
            'notes' => $this->notes,
            'team_id' => auth()->user()->currentTeam->id,
            'created_by_user_id' => auth()->id(),
            'is_active' => true,
        ]);

        if ($this->contact_id) {
            $contact = CrmContact::find($this->contact_id);
            if ($contact) {
                $applicant->linkContact($contact);
            }
        }

        $this->resetForm();
        $this->modalShow = false;
        session()->flash('message', 'Bewerber erfolgreich erstellt.');
    }

    public function resetForm()
    {
        $this->reset(['contact_id', 'rec_applicant_status_id', 'applied_at', 'notes']);
    }

    public function openCreateModal()
    {
        $this->resetForm();
        $this->modalShow = true;
    }

    public function closeCreateModal()
    {
        $this->modalShow = false;
        $this->resetForm();
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
