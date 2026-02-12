<?php

namespace Platform\Recruiting\Livewire\Dashboard;

use Livewire\Component;
use Livewire\Attributes\Computed;
use Platform\Recruiting\Models\RecPosition;
use Platform\Recruiting\Models\RecPosting;
use Platform\Recruiting\Models\RecApplicant;
use Platform\Crm\Models\CrmContact;

class Dashboard extends Component
{
    #[Computed]
    public function positionCount()
    {
        return RecPosition::forTeam(auth()->user()->currentTeam->id)->active()->count();
    }

    #[Computed]
    public function postingCount()
    {
        return RecPosting::forTeam(auth()->user()->currentTeam->id)->active()->count();
    }

    #[Computed]
    public function applicantCount()
    {
        return RecApplicant::forTeam(auth()->user()->currentTeam->id)->active()->count();
    }

    #[Computed]
    public function inboxApplicants()
    {
        return RecApplicant::forTeam(auth()->user()->currentTeam->id)
            ->active()
            ->whereDoesntHave('postings')
            ->whereDoesntHave('crmContactLinks')
            ->with(['applicantStatus'])
            ->orderByDesc('created_at')
            ->get();
    }

    #[Computed]
    public function assignedApplicants()
    {
        return RecApplicant::forTeam(auth()->user()->currentTeam->id)
            ->active()
            ->where(function ($q) {
                $q->whereHas('postings')
                  ->orWhereHas('crmContactLinks');
            })
            ->with(['applicantStatus', 'crmContactLinks.contact', 'postings.position'])
            ->orderByDesc('created_at')
            ->get();
    }

    #[Computed]
    public function availablePostings()
    {
        return RecPosting::with('position')
            ->forTeam(auth()->user()->currentTeam->id)
            ->active()
            ->orderBy('title')
            ->get();
    }

    #[Computed]
    public function availableContacts()
    {
        return CrmContact::active()
            ->where('team_id', auth()->user()->currentTeam->id)
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->get();
    }

    public function assignPosting(int $applicantId, int $postingId): void
    {
        $applicant = RecApplicant::forTeam(auth()->user()->currentTeam->id)->findOrFail($applicantId);
        $applicant->postings()->syncWithoutDetaching([$postingId => ['applied_at' => now()]]);
        unset($this->inboxApplicants, $this->assignedApplicants);
    }

    public function linkExistingContact(int $applicantId, int $contactId): void
    {
        $applicant = RecApplicant::forTeam(auth()->user()->currentTeam->id)->findOrFail($applicantId);
        $contact = CrmContact::findOrFail($contactId);
        $applicant->linkContact($contact);
        unset($this->inboxApplicants, $this->assignedApplicants);
    }

    public function render()
    {
        return view('recruiting::livewire.dashboard.dashboard')
            ->layout('platform::layouts.app');
    }
}
