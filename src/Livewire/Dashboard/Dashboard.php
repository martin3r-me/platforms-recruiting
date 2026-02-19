<?php

namespace Platform\Recruiting\Livewire\Dashboard;

use Illuminate\Support\Facades\Cache;
use Livewire\Component;
use Livewire\Attributes\Computed;
use Platform\Crm\Models\CommsChannel;
use Platform\Crm\Models\CrmContact;
use Platform\Recruiting\Models\RecApplicant;
use Platform\Recruiting\Models\RecPosition;
use Platform\Recruiting\Models\RecPosting;

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
            ->whereNull('enrichment_status')
            ->with([
                'applicantStatus',
                'crmContactLinks.contact.emailAddresses',
                'postings.position',
                'extraFieldValues',
                'preferredCommsChannel',
            ])
            ->orderByDesc('created_at')
            ->get();
    }

    #[Computed]
    public function assignedApplicants()
    {
        // Get all enriched applicants, then filter out the completed ones
        $all = RecApplicant::forTeam(auth()->user()->currentTeam->id)
            ->active()
            ->whereNotNull('enrichment_status')
            ->with([
                'applicantStatus',
                'crmContactLinks.contact.emailAddresses',
                'postings.position',
                'extraFieldValues',
                'preferredCommsChannel',
            ])
            ->orderByDesc('created_at')
            ->get();

        // Filter out completed applicants (those with contact, all extra fields, and postings)
        return $all->filter(fn ($a) => !$this->isApplicantCompleted($a));
    }

    #[Computed]
    public function completedApplicants()
    {
        return RecApplicant::forTeam(auth()->user()->currentTeam->id)
            ->active()
            ->whereNotNull('enrichment_status')
            ->with([
                'applicantStatus',
                'crmContactLinks.contact.emailAddresses',
                'postings.position',
                'extraFieldValues',
                'preferredCommsChannel',
            ])
            ->orderByDesc('created_at')
            ->get()
            ->filter(fn ($a) => $this->isApplicantCompleted($a));
    }

    /**
     * Check if an applicant is "completed":
     * - Has at least one CRM contact linked
     * - All extra fields are filled (or no extra fields exist)
     * - Has at least one posting assigned
     */
    private function isApplicantCompleted(RecApplicant $applicant): bool
    {
        // Must have at least one contact linked
        if ($applicant->crmContactLinks->isEmpty()) {
            return false;
        }

        // Must have at least one posting
        if ($applicant->postings->isEmpty()) {
            return false;
        }

        // All extra fields must be filled (if any exist)
        $extraCounts = $this->getExtraFieldCounts($applicant);
        if ($extraCounts['total'] > 0 && $extraCounts['filled'] !== $extraCounts['total']) {
            return false;
        }

        return true;
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

    #[Computed]
    public function enrichingApplicantIds()
    {
        return $this->inboxApplicants
            ->filter(fn ($a) => Cache::has("enrichment:processing:{$a->id}"))
            ->pluck('id')
            ->toArray();
    }

    #[Computed]
    public function teamChannels()
    {
        return CommsChannel::where('team_id', auth()->user()->currentTeam->id)
            ->where('is_active', true)
            ->whereIn('type', ['email', 'whatsapp'])
            ->orderBy('type')
            ->get();
    }

    #[Computed]
    public function autoPilotProcessingIds()
    {
        return $this->assignedApplicants
            ->filter(fn ($a) => $a->auto_pilot && !$a->auto_pilot_completed_at)
            ->pluck('id')
            ->toArray();
    }

    public function getExtraFieldCounts(RecApplicant $applicant): array
    {
        $fields = $applicant->getExtraFieldsWithLabels();
        $total = count($fields);
        $filled = collect($fields)->filter(fn ($f) =>
            $f['value'] !== null && $f['value'] !== '' && $f['value'] !== []
        )->count();
        return ['filled' => $filled, 'total' => $total];
    }

    public function toggleAutoPilot(int $applicantId, string $channelType): void
    {
        $applicant = RecApplicant::forTeam(auth()->user()->currentTeam->id)->findOrFail($applicantId);

        $currentChannel = $applicant->preferredCommsChannel;
        if ($applicant->auto_pilot && $currentChannel?->type === $channelType) {
            $applicant->update([
                'auto_pilot' => false,
                'preferred_comms_channel_id' => null,
            ]);
        } else {
            $channel = CommsChannel::where('team_id', auth()->user()->currentTeam->id)
                ->where('type', $channelType)
                ->where('is_active', true)
                ->first();
            if ($channel) {
                $applicant->update([
                    'auto_pilot' => true,
                    'preferred_comms_channel_id' => $channel->id,
                ]);
            }
        }

        unset($this->inboxApplicants, $this->assignedApplicants, $this->completedApplicants, $this->autoPilotProcessingIds);
    }

    public function refreshDashboard(): void
    {
        unset(
            $this->positionCount,
            $this->postingCount,
            $this->applicantCount,
            $this->inboxApplicants,
            $this->assignedApplicants,
            $this->completedApplicants,
            $this->enrichingApplicantIds,
            $this->teamChannels,
            $this->autoPilotProcessingIds,
        );
    }

    public function assignPosting(int $applicantId, int $postingId): void
    {
        $applicant = RecApplicant::forTeam(auth()->user()->currentTeam->id)->findOrFail($applicantId);
        $applicant->postings()->syncWithoutDetaching([$postingId => ['applied_at' => now()]]);
        unset($this->inboxApplicants, $this->assignedApplicants, $this->completedApplicants);
    }

    public function linkExistingContact(int $applicantId, int $contactId): void
    {
        $applicant = RecApplicant::forTeam(auth()->user()->currentTeam->id)->findOrFail($applicantId);
        $contact = CrmContact::findOrFail($contactId);
        $applicant->linkContact($contact);
        unset($this->inboxApplicants, $this->assignedApplicants, $this->completedApplicants);
    }

    public function render()
    {
        return view('recruiting::livewire.dashboard.dashboard')
            ->layout('platform::layouts.app');
    }
}
