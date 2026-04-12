<?php

namespace Platform\Recruiting\Livewire\Position;

use Illuminate\Support\Facades\Cache;
use Livewire\Component;
use Livewire\Attributes\Computed;
use Platform\Crm\Models\CommsChannel;
use Platform\Crm\Models\CommsWhatsAppThread;
use Platform\Crm\Models\CrmPhoneNumber;
use Platform\Recruiting\Models\RecApplicant;
use Platform\Recruiting\Models\RecPhase;
use Platform\Recruiting\Models\RecPosition;
use Platform\Core\Livewire\Concerns\ResolvesAutoPilotChannel;
use Platform\Recruiting\Models\RecPosting;

class Dashboard extends Component
{
    use ResolvesAutoPilotChannel;
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
            ->where(fn ($q) => $q->whereNull('enrichment_status')->orWhere('enrichment_status', ''))
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
    public function phases()
    {
        return $this->position->phases()->active()->ordered()->get();
    }

    #[Computed]
    public function phasedApplicants()
    {
        $enrichedApplicants = $this->applicantBaseQuery()
            ->whereNotNull('enrichment_status')
            ->where('enrichment_status', '!=', 'no_contact')
            ->with([
                'crmContactLinks.contact.emailAddresses',
                'crmContactLinks.contact.phoneNumbers',
                'postings.position',
                'extraFieldValues',
                'preferredCommsChannel',
                'phase',
            ])
            ->orderByDesc('created_at')
            ->get();

        $grouped = [];
        foreach ($this->phases as $phase) {
            $grouped[$phase->id] = $enrichedApplicants
                ->filter(fn ($a) => $a->rec_phase_id === $phase->id)
                ->values();
        }

        return $grouped;
    }

    #[Computed]
    public function completedApplicants()
    {
        $lastPhase = $this->phases->last();
        if (!$lastPhase) {
            return collect();
        }

        return $this->applicantBaseQuery()
            ->whereNotNull('enrichment_status')
            ->where('enrichment_status', '!=', 'no_contact')
            ->where('rec_phase_id', $lastPhase->id)
            ->with([
                'crmContactLinks.contact.emailAddresses',
                'crmContactLinks.contact.phoneNumbers',
                'postings.position',
                'extraFieldValues',
                'preferredCommsChannel',
                'phase',
            ])
            ->orderByDesc('created_at')
            ->get()
            ->filter(fn ($a) => $a->auto_pilot_completed_at !== null);
    }

    public function advanceToNextPhase(int $applicantId): void
    {
        $applicant = RecApplicant::forTeam(auth()->user()->currentTeam->id)->findOrFail($applicantId);
        $applicant->advanceToNextPhase();
        unset($this->phasedApplicants, $this->completedApplicants);
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
        return collect($this->phasedApplicants)
            ->flatten()
            ->filter(fn ($a) => $a->auto_pilot && !$a->auto_pilot_completed_at)
            ->pluck('id')
            ->toArray();
    }

    public function getExtraFieldCounts(RecApplicant $applicant): array
    {
        $fields = $applicant->getExtraFieldsWithLabels();
        $total = count($fields);
        $filled = collect($fields)->filter(function ($f) {
            $v = $f['value'];
            return $v !== null && $v !== '' && $v !== [] && $v !== '[]';
        })->count();
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

    public function toggleAutoPilot(int $applicantId): void
    {
        $applicant = RecApplicant::forTeam(auth()->user()->currentTeam->id)->findOrFail($applicantId);

        if ($applicant->auto_pilot) {
            $applicant->update([
                'auto_pilot' => false,
                'preferred_comms_channel_id' => null,
            ]);
        } else {
            $channel = $this->resolvePreferredChannel($applicant);
            if ($channel) {
                $applicant->update([
                    'auto_pilot' => true,
                    'preferred_comms_channel_id' => $channel->id,
                    'owned_by_user_id' => auth()->user()->id,
                ]);
            }
        }

        unset($this->inboxApplicants, $this->needsReviewApplicants, $this->phasedApplicants, $this->completedApplicants, $this->autoPilotProcessingIds);
    }

    public function retryEnrichment(int $applicantId): void
    {
        $applicant = RecApplicant::forTeam(auth()->user()->currentTeam->id)->findOrFail($applicantId);
        $applicant->update(['enrichment_status' => null]);
        unset($this->inboxApplicants, $this->needsReviewApplicants, $this->phasedApplicants, $this->completedApplicants);
    }

    public function refreshDashboard(): void
    {
        unset(
            $this->postingCount,
            $this->applicantCount,
            $this->inboxApplicants,
            $this->needsReviewApplicants,
            $this->phasedApplicants,
            $this->completedApplicants,
            $this->enrichingApplicantIds,
            $this->teamChannels,
            $this->autoPilotProcessingIds,
            $this->positionsWithPostings,
            $this->phases,
        );
    }

    public function assignPosting(int $applicantId, int $postingId): void
    {
        $applicant = RecApplicant::forTeam(auth()->user()->currentTeam->id)->findOrFail($applicantId);
        $applicant->postings()->syncWithoutDetaching([$postingId => ['applied_at' => now()]]);
        unset($this->inboxApplicants, $this->needsReviewApplicants, $this->phasedApplicants, $this->completedApplicants);
    }

    public function dismissApplicant(int $applicantId): void
    {
        $applicant = RecApplicant::forTeam(auth()->user()->currentTeam->id)->findOrFail($applicantId);
        $applicant->update([
            'is_active' => false,
            'auto_pilot' => false,
        ]);
        unset($this->inboxApplicants, $this->needsReviewApplicants, $this->phasedApplicants, $this->completedApplicants, $this->applicantCount);
    }

    public function render()
    {
        return view('recruiting::livewire.position.dashboard')
            ->layout('platform::layouts.app');
    }
}
