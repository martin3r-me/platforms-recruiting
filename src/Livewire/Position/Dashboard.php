<?php

namespace Platform\Recruiting\Livewire\Position;

use Illuminate\Support\Facades\Cache;
use Livewire\Component;
use Livewire\Attributes\Computed;
use Platform\Crm\Models\CommsChannel;
use Platform\Crm\Models\CommsWhatsAppThread;
use Platform\Crm\Models\CrmContact;
use Platform\Crm\Models\CrmContactLink;
use Platform\Crm\Models\CrmPhoneNumber;
use Platform\Recruiting\Models\RecApplicant;
use Platform\Recruiting\Models\RecPosition;
use Platform\Recruiting\Models\RecPosting;

class Dashboard extends Component
{
    public RecPosition $position;

    public function mount(RecPosition $position): void
    {
        $this->position = $position;
    }

    #[Computed]
    public function positionsWithPostings()
    {
        $teamId = auth()->user()->currentTeam->id;

        return RecPosition::forTeam($teamId)
            ->active()
            ->whereHas('postings', fn ($q) => $q->active())
            ->withCount(['postings as applicant_count' => function ($q) {
                $q->active()->whereHas('applicants', fn ($a) => $a->where('is_active', true));
            }])
            ->orderBy('title')
            ->get()
            ->each(function ($pos) {
                // Count unique active applicants across all postings of this position
                $pos->applicant_count = RecApplicant::where('is_active', true)
                    ->whereHas('postings', fn ($q) => $q->where('rec_position_id', $pos->id))
                    ->count();
            });
    }

    private function postingIds()
    {
        return $this->position->postings()->pluck('rec_postings.id');
    }

    private function applicantBaseQuery()
    {
        $teamId = auth()->user()->currentTeam->id;
        $postingIds = $this->postingIds();

        return RecApplicant::forTeam($teamId)
            ->active()
            ->whereHas('postings', fn ($q) => $q->whereIn('rec_postings.id', $postingIds));
    }

    #[Computed]
    public function postingCount()
    {
        return $this->position->postings()->active()->count();
    }

    #[Computed]
    public function applicantCount()
    {
        return $this->applicantBaseQuery()->count();
    }

    #[Computed]
    public function inboxApplicants()
    {
        return $this->applicantBaseQuery()
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
        return $this->applicantBaseQuery()
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
        $all = $this->applicantBaseQuery()
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

        return $all->filter(fn ($a) => !$this->isApplicantCompleted($a));
    }

    #[Computed]
    public function completedApplicants()
    {
        return $this->applicantBaseQuery()
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

    private function isApplicantCompleted(RecApplicant $applicant): bool
    {
        if ($applicant->crmContactLinks->isEmpty()) {
            return false;
        }

        if ($applicant->postings->isEmpty()) {
            return false;
        }

        $extraCounts = $this->getExtraFieldCounts($applicant);
        if ($extraCounts['total'] > 0 && $extraCounts['filled'] !== $extraCounts['total']) {
            return false;
        }

        return true;
    }

    #[Computed]
    public function availablePostings()
    {
        return $this->position->postings()
            ->with('position')
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

    public function getWhatsAppStatus(RecApplicant $applicant): array
    {
        $phoneNumber = null;
        $whatsappStatus = CrmPhoneNumber::WHATSAPP_UNKNOWN;

        foreach ($applicant->crmContactLinks as $link) {
            foreach ($link->contact?->phoneNumbers ?? [] as $phone) {
                if (!$phone->is_active) continue;
                $phoneNumber = $phone->international ?: $phone->raw_input;
                $whatsappStatus = $phone->whatsapp_status ?? CrmPhoneNumber::WHATSAPP_UNKNOWN;
                if ($whatsappStatus !== CrmPhoneNumber::WHATSAPP_UNKNOWN) {
                    break 2;
                }
            }
        }

        if (!$phoneNumber) {
            return ['color' => 'none', 'status' => 'no_phone', 'window_open' => false];
        }

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
            $this->postingCount,
            $this->applicantCount,
            $this->inboxApplicants,
            $this->needsReviewApplicants,
            $this->assignedApplicants,
            $this->completedApplicants,
            $this->enrichingApplicantIds,
            $this->teamChannels,
            $this->autoPilotProcessingIds,
            $this->positionsWithPostings,
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

        // Create link directly (bypasses hasContacts() guard in trait)
        CrmContactLink::firstOrCreate([
            'contact_id' => $contact->id,
            'linkable_id' => $applicant->id,
            'linkable_type' => $applicant->getMorphClass(),
        ], [
            'team_id' => $applicant->team_id,
            'created_by_user_id' => auth()->id(),
        ]);

        // Manual linking = enrichment done
        if (in_array($applicant->enrichment_status, [null, 'no_contact'], true)) {
            $applicant->update(['enrichment_status' => 'enriched']);
        }

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
        return view('recruiting::livewire.position.dashboard')
            ->layout('platform::layouts.app');
    }
}
