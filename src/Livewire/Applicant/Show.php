<?php

namespace Platform\Recruiting\Livewire\Applicant;

use Livewire\Component;
use Livewire\Attributes\Computed;
use Platform\Core\Livewire\Concerns\WithExtraFields;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Platform\Core\Models\Team;
use Platform\Recruiting\Models\RecApplicant;
use Platform\Recruiting\Models\RecApplicantStatus;
use Platform\Recruiting\Models\RecPosition;
use Platform\Crm\Models\CrmContact;

class Show extends Component
{
    use WithExtraFields;
    public RecApplicant $applicant;

    public $contactLinkModalShow = false;
    public $contactCreateModalShow = false;

    public $contactForm = [
        'first_name' => '',
        'last_name' => '',
        'middle_name' => '',
        'nickname' => '',
        'birth_date' => '',
        'notes' => '',
    ];

    public $contactLinkForm = [
        'contact_id' => null,
    ];

    public $availableContacts = [];

    public function mount(RecApplicant $applicant)
    {
        $allowedTeamIds = $this->getAllowedTeamIds($applicant->team_id);

        $this->applicant = $applicant->load([
            'crmContactLinks' => fn ($q) => $q->whereIn('team_id', $allowedTeamIds),
            'crmContactLinks.contact.emailAddresses' => function ($q) {
                $q->active()->orderByDesc('is_primary')->orderBy('id');
            },
            'crmContactLinks.contact.phoneNumbers' => function ($q) {
                $q->active()->orderByDesc('is_primary')->orderBy('id');
            },
            'applicantStatus',
            'autoPilotState',
            'postings.position',
        ]);

        $this->loadAvailableContacts();
        $this->loadExtraFieldValues($this->applicant);
    }

    public function rules(): array
    {
        return array_merge([
            'applicant.rec_applicant_status_id' => 'nullable|exists:rec_applicant_statuses,id',
            'applicant.owned_by_user_id' => 'nullable|exists:users,id',
            'applicant.notes' => 'nullable|string',
            'applicant.applied_at' => 'nullable|date',
            'applicant.is_active' => 'boolean',
            'applicant.auto_pilot' => 'boolean',
        ], $this->getExtraFieldValidationRules());
    }

    public function messages(): array
    {
        return $this->getExtraFieldValidationMessages();
    }

    public function deleteApplicant(): void
    {
        DB::transaction(function () {
            $this->applicant->crmContactLinks()->delete();
            $this->applicant->delete();
        });

        session()->flash('message', 'Bewerbung erfolgreich gelöscht.');
        $this->redirect(route('recruiting.applicants.index'), navigate: true);
    }

    public function save(): void
    {
        $this->validate();
        $this->applicant->save();
        $this->saveExtraFieldValues($this->applicant);

        $this->applicant->progress = $this->applicant->calculateProgress();
        $this->applicant->save();

        session()->flash('message', 'Bewerber erfolgreich aktualisiert.');
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
    public function teamUsers()
    {
        return Auth::user()
            ->currentTeam
            ->users()
            ->orderBy('name')
            ->get()
            ->map(fn ($user) => [
                'id' => $user->id,
                'name' => $user->fullname ?? $user->name,
            ]);
    }

    #[Computed]
    public function isDirty()
    {
        return $this->applicant->isDirty() || $this->isExtraFieldsDirty();
    }

    #[Computed]
    public function autoPilotLogs()
    {
        return $this->applicant->autoPilotLogs()
            ->orderByDesc('created_at')
            ->limit(20)
            ->get();
    }

    public function linkContact(): void
    {
        $this->contactLinkForm = ['contact_id' => null];
        $this->loadAvailableContacts();
        $this->contactLinkModalShow = true;
    }

    public function addContact(): void
    {
        $this->contactForm = [
            'first_name' => '', 'last_name' => '', 'middle_name' => '',
            'nickname' => '', 'birth_date' => '', 'notes' => '',
        ];
        $this->contactCreateModalShow = true;
    }

    public function saveContactLink(): void
    {
        $this->validate(['contactLinkForm.contact_id' => 'required|exists:crm_contacts,id']);
        $contact = CrmContact::find($this->contactLinkForm['contact_id']);
        $this->applicant->linkContact($contact);
        $this->closeContactLinkModal();
        $this->applicant->load(['crmContactLinks.contact']);
        session()->flash('message', 'Kontakt verknüpft.');
    }

    public function saveContact(): void
    {
        $this->validate([
            'contactForm.first_name' => 'required|string|max:255',
            'contactForm.last_name' => 'required|string|max:255',
            'contactForm.middle_name' => 'nullable|string|max:255',
            'contactForm.nickname' => 'nullable|string|max:255',
            'contactForm.birth_date' => 'nullable|date',
            'contactForm.notes' => 'nullable|string|max:1000',
        ]);

        $contact = CrmContact::create(array_merge($this->contactForm, [
            'team_id' => $this->applicant->team_id,
            'created_by_user_id' => auth()->id(),
        ]));

        $this->applicant->linkContact($contact);
        $this->closeContactCreateModal();
        $this->applicant->load('crmContactLinks.contact');
        session()->flash('message', 'Kontakt erstellt und verknüpft.');
    }

    public function unlinkContact($contactId): void
    {
        $this->applicant->crmContactLinks()
            ->where('contact_id', $contactId)
            ->delete();
        $this->applicant->load('crmContactLinks.contact');
        session()->flash('message', 'Kontakt-Verknüpfung entfernt.');
    }

    public function closeContactLinkModal(): void
    {
        $this->contactLinkModalShow = false;
        $this->contactLinkForm = ['contact_id' => null];
    }

    public function closeContactCreateModal(): void
    {
        $this->contactCreateModalShow = false;
        $this->contactForm = [
            'first_name' => '', 'last_name' => '', 'middle_name' => '',
            'nickname' => '', 'birth_date' => '', 'notes' => '',
        ];
    }

    private function loadAvailableContacts(): void
    {
        $linkedContactIds = $this->applicant->crmContactLinks->pluck('contact_id');
        $allowedTeamIds = $this->getAllowedTeamIds($this->applicant->team_id);

        $this->availableContacts = CrmContact::active()
            ->whereIn('team_id', $allowedTeamIds)
            ->whereNotIn('id', $linkedContactIds)
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->get();
    }

    public function rendered(): void
    {
        $this->dispatch('extrafields', [
            'context_type' => RecPosition::class,
            'context_id' => null,
        ]);

        $this->dispatch('tagging', [
            'context_type' => get_class($this->applicant),
            'context_id' => $this->applicant->id,
        ]);

        $this->dispatch('files', [
            'context_type' => get_class($this->applicant),
            'context_id' => $this->applicant->id,
        ]);

        $primaryContact = $this->applicant->crmContactLinks->first()?->contact;
        $subject = 'Bewerbung #' . $this->applicant->id;
        if ($primaryContact) {
            $subject .= ' – ' . $primaryContact->full_name;
        }

        $this->dispatch('comms', [
            'model' => get_class($this->applicant),
            'modelId' => $this->applicant->id,
            'subject' => $subject,
            'description' => $this->applicant->notes ?? '',
            'url' => route('recruiting.applicants.show', $this->applicant),
            'source' => 'recruiting.applicant.view',
            'recipients' => [],
            'capabilities' => ['manage_channels' => false, 'threads' => true],
            'meta' => [
                'status' => $this->applicant->applicantStatus?->name,
                'progress' => $this->applicant->progress,
                'applied_at' => $this->applicant->applied_at?->toIso8601String(),
                'is_active' => $this->applicant->is_active,
            ],
        ]);
    }

    public function render()
    {
        return view('recruiting::livewire.applicant.show')
            ->layout('platform::layouts.app');
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
