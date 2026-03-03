<?php

namespace Platform\Recruiting\Livewire\Dashboard;

use Illuminate\Support\Facades\Cache;
use Livewire\Component;
use Livewire\Attributes\Computed;
use Platform\Crm\Models\CommsChannel;
use Platform\Crm\Models\CommsWhatsAppThread;
use Platform\Crm\Models\CrmContact;
use Platform\Crm\Models\CrmPhoneNumber;
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
                'crmContactLinks.contact.emailAddresses',
                'crmContactLinks.contact.phoneNumbers',
                'postings.position',
                'extraFieldValues',
                'preferredCommsChannel',
            ])
            ->orderByDesc('created_at')
            ->get();
    }

    #[Computed]
    public function needsReviewApplicants()
    {
        return RecApplicant::forTeam(auth()->user()->currentTeam->id)
            ->active()
            ->where('enrichment_status', 'no_contact')
            ->with([
                'crmContactLinks.contact.emailAddresses',
                'crmContactLinks.contact.phoneNumbers',
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
        // Get all enriched applicants, then filter out the completed ones and no_contact
        $all = RecApplicant::forTeam(auth()->user()->currentTeam->id)
            ->active()
            ->whereNotNull('enrichment_status')
            ->where('enrichment_status', '!=', 'no_contact')
            ->with([
                'crmContactLinks.contact.emailAddresses',
                'crmContactLinks.contact.phoneNumbers',
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
            ->where('enrichment_status', '!=', 'no_contact')
            ->with([
                'crmContactLinks.contact.emailAddresses',
                'crmContactLinks.contact.phoneNumbers',
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

    /**
     * Get WhatsApp status for an applicant.
     * Returns: 'green' (opted_in/available + window open), 'yellow' (opted_in/available, no window), 'gray' (unknown/unavailable)
     */
    public function getWhatsAppStatus(RecApplicant $applicant): array
    {
        $phoneNumber = null;
        $whatsappStatus = CrmPhoneNumber::WHATSAPP_UNKNOWN;

        // Find the first phone number with WhatsApp status
        foreach ($applicant->crmContactLinks as $link) {
            foreach ($link->contact?->phoneNumbers ?? [] as $phone) {
                if (!$phone->is_active) continue;
                $phoneNumber = $phone->international ?: $phone->raw_input;
                $whatsappStatus = $phone->whatsapp_status ?? CrmPhoneNumber::WHATSAPP_UNKNOWN;
                // Prefer phones with known WhatsApp status
                if ($whatsappStatus !== CrmPhoneNumber::WHATSAPP_UNKNOWN) {
                    break 2;
                }
            }
        }

        if (!$phoneNumber) {
            return ['color' => 'none', 'status' => 'no_phone', 'window_open' => false];
        }

        // Check if WhatsApp is available
        $isWhatsAppAvailable = in_array($whatsappStatus, [
            CrmPhoneNumber::WHATSAPP_AVAILABLE,
            CrmPhoneNumber::WHATSAPP_OPTED_IN,
        ]);

        if (!$isWhatsAppAvailable) {
            return [
                'color' => 'gray',
                'status' => $whatsappStatus,
                'window_open' => false,
            ];
        }

        // Check if 24h window is open by finding a WhatsApp thread
        $windowOpen = false;
        $morphClass = $applicant->getMorphClass();
        $fullClass = get_class($applicant);

        $thread = CommsWhatsAppThread::query()
            ->where(function ($q) use ($morphClass, $fullClass, $applicant) {
                $q->where(function ($q2) use ($morphClass, $applicant) {
                    $q2->where('context_model', $morphClass)
                        ->where('context_model_id', $applicant->id);
                })->orWhere(function ($q2) use ($fullClass, $applicant) {
                    $q2->where('context_model', $fullClass)
                        ->where('context_model_id', $applicant->id);
                });
            })
            ->orderByDesc('last_inbound_at')
            ->first();

        if ($thread && $thread->isWindowOpen()) {
            $windowOpen = true;
        }

        return [
            'color' => $windowOpen ? 'green' : 'yellow',
            'status' => $whatsappStatus,
            'window_open' => $windowOpen,
        ];
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
                    'owned_by_user_id' => auth()->user()->id,
                ]);
            }
        }

        unset($this->inboxApplicants, $this->needsReviewApplicants, $this->assignedApplicants, $this->completedApplicants, $this->autoPilotProcessingIds);
    }

    public function retryEnrichment(int $applicantId): void
    {
        $applicant = RecApplicant::forTeam(auth()->user()->currentTeam->id)->findOrFail($applicantId);
        $applicant->update(['enrichment_status' => null]);
        unset($this->inboxApplicants, $this->needsReviewApplicants, $this->assignedApplicants, $this->completedApplicants);
    }

    public function refreshDashboard(): void
    {
        unset(
            $this->positionCount,
            $this->postingCount,
            $this->applicantCount,
            $this->inboxApplicants,
            $this->needsReviewApplicants,
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
        unset($this->inboxApplicants, $this->needsReviewApplicants, $this->assignedApplicants, $this->completedApplicants);
    }

    public function linkExistingContact(int $applicantId, int $contactId): void
    {
        $applicant = RecApplicant::forTeam(auth()->user()->currentTeam->id)->findOrFail($applicantId);
        $contact = CrmContact::findOrFail($contactId);
        $applicant->linkContact($contact);
        unset($this->inboxApplicants, $this->needsReviewApplicants, $this->assignedApplicants, $this->completedApplicants);
    }

    public function dismissApplicant(int $applicantId): void
    {
        $applicant = RecApplicant::forTeam(auth()->user()->currentTeam->id)->findOrFail($applicantId);
        $applicant->update([
            'is_active' => false,
            'auto_pilot' => false,
        ]);
        unset($this->inboxApplicants, $this->needsReviewApplicants, $this->assignedApplicants, $this->completedApplicants, $this->applicantCount);
    }

    public function render()
    {
        return view('recruiting::livewire.dashboard.dashboard')
            ->layout('platform::layouts.app');
    }
}
