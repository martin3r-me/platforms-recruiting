<?php

namespace Platform\Recruiting\Livewire\Dashboard;

use Illuminate\Support\Facades\Cache;
use Livewire\Component;
use Livewire\Attributes\Computed;
use Platform\Crm\Models\CommsChannel;
use Platform\Crm\Models\CommsWhatsAppThread;
use Platform\Crm\Models\CrmPhoneNumber;
use Platform\Recruiting\Models\RecApplicant;
use Platform\Recruiting\Models\RecPosition;
use Platform\Core\Livewire\Concerns\ResolvesAutoPilotChannel;
use Platform\Recruiting\Models\RecPosting;

class Dashboard extends Component
{
    use ResolvesAutoPilotChannel;

    public bool $showParked = false;
    public ?int $positionFilter = null;

    public function mount(): void
    {
        $this->showParked = request()->routeIs('recruiting.dashboard.parked');
    }

    private function applicantBaseQuery()
    {
        $query = RecApplicant::forTeam(auth()->user()->currentTeam->id)
            ->where('is_active', true)
            ->where('is_parked', $this->showParked);
        $this->applyPositionFilter($query);
        return $query;
    }

    #[Computed]
    public function positions()
    {
        return RecPosition::forTeam(auth()->user()->currentTeam->id)
            ->active()
            ->orderBy('title')
            ->get();
    }

    #[Computed]
    public function phases()
    {
        if (!$this->positionFilter) return collect();
        $position = RecPosition::find($this->positionFilter);
        return $position?->phases()->active()->ordered()->get() ?? collect();
    }

    #[Computed]
    public function phasedApplicants()
    {
        if (!$this->positionFilter) return [];

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

    private function postingIdsForPosition()
    {
        if (!$this->positionFilter) return null;
        return RecPosting::where('rec_position_id', $this->positionFilter)->pluck('id');
    }

    private function applyPositionFilter($query)
    {
        if ($this->positionFilter) {
            $postingIds = $this->postingIdsForPosition();
            $query->whereHas('postings', fn ($q) => $q->whereIn('rec_postings.id', $postingIds));
        }
        return $query;
    }

    #[Computed]
    public function positionCount()
    {
        return RecPosition::forTeam(auth()->user()->currentTeam->id)->active()->count();
    }

    #[Computed]
    public function postingCount()
    {
        if ($this->positionFilter) {
            return RecPosting::where('rec_position_id', $this->positionFilter)->active()->count();
        }
        return RecPosting::forTeam(auth()->user()->currentTeam->id)->active()->count();
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
    public function activeApplicants()
    {
        return $this->applicantBaseQuery()
            ->whereNotNull('enrichment_status')
            ->where('enrichment_status', '!=', 'no_contact')
            ->whereNull('auto_pilot_completed_at')
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
    }

    #[Computed]
    public function completedApplicants()
    {
        return $this->applicantBaseQuery()
            ->whereNotNull('enrichment_status')
            ->where('enrichment_status', '!=', 'no_contact')
            ->whereNotNull('auto_pilot_completed_at')
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
    }

    public function advanceToNextPhase(int $applicantId): void
    {
        $applicant = RecApplicant::forTeam(auth()->user()->currentTeam->id)->findOrFail($applicantId);
        $applicant->advanceToNextPhase();
        unset($this->activeApplicants, $this->completedApplicants, $this->phasedApplicants);
    }

    #[Computed]
    public function availablePostings()
    {
        if ($this->positionFilter) {
            $position = RecPosition::find($this->positionFilter);
            return $position?->postings()->with('position')->active()->orderBy('title')->get() ?? collect();
        }
        return RecPosting::with('position')
            ->forTeam(auth()->user()->currentTeam->id)
            ->active()
            ->orderBy('title')
            ->get();
    }

    public function parkApplicant(int $applicantId): void
    {
        $applicant = RecApplicant::forTeam(auth()->user()->currentTeam->id)->findOrFail($applicantId);
        $applicant->update([
            'is_parked' => true,
            'parked_at' => now(),
            'auto_pilot' => false,
        ]);
        unset($this->inboxApplicants, $this->needsReviewApplicants, $this->activeApplicants, $this->completedApplicants, $this->applicantCount, $this->autoPilotProcessingIds, $this->phasedApplicants);
        $this->dispatch('sidebar-refresh');
    }

    public function unparkApplicant(int $applicantId): void
    {
        $applicant = RecApplicant::forTeam(auth()->user()->currentTeam->id)->findOrFail($applicantId);
        $applicant->update([
            'is_parked' => false,
            'parked_at' => null,
        ]);
        unset($this->inboxApplicants, $this->needsReviewApplicants, $this->activeApplicants, $this->completedApplicants, $this->applicantCount, $this->phasedApplicants);
        $this->dispatch('sidebar-refresh');
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
        if ($this->positionFilter) {
            return collect($this->phasedApplicants)
                ->flatten()
                ->filter(fn ($a) => $a->auto_pilot && !$a->auto_pilot_completed_at)
                ->pluck('id')
                ->toArray();
        }
        return $this->activeApplicants
            ->filter(fn ($a) => $a->auto_pilot)
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

        unset($this->inboxApplicants, $this->needsReviewApplicants, $this->activeApplicants, $this->completedApplicants, $this->autoPilotProcessingIds, $this->phasedApplicants);
    }

    public function retryEnrichment(int $applicantId): void
    {
        $applicant = RecApplicant::forTeam(auth()->user()->currentTeam->id)->findOrFail($applicantId);
        $applicant->update(['enrichment_status' => null]);
        unset($this->inboxApplicants, $this->needsReviewApplicants, $this->activeApplicants, $this->completedApplicants, $this->phasedApplicants);
    }

    public function updatedPositionFilter(): void
    {
        $this->refreshDashboard();
    }

    public function refreshDashboard(): void
    {
        unset(
            $this->positionCount,
            $this->postingCount,
            $this->applicantCount,
            $this->inboxApplicants,
            $this->needsReviewApplicants,
            $this->activeApplicants,
            $this->completedApplicants,
            $this->enrichingApplicantIds,
            $this->teamChannels,
            $this->autoPilotProcessingIds,
            $this->positions,
            $this->phases,
            $this->phasedApplicants,
            $this->availablePostings,
        );
    }

    public function assignPosting(int $applicantId, int $postingId): void
    {
        $applicant = RecApplicant::forTeam(auth()->user()->currentTeam->id)->findOrFail($applicantId);
        $applicant->postings()->syncWithoutDetaching([$postingId => ['applied_at' => now()]]);
        unset($this->inboxApplicants, $this->needsReviewApplicants, $this->activeApplicants, $this->completedApplicants, $this->phasedApplicants);
    }

    public function dismissApplicant(int $applicantId): void
    {
        $applicant = RecApplicant::forTeam(auth()->user()->currentTeam->id)->findOrFail($applicantId);
        $applicant->update([
            'is_active' => false,
            'auto_pilot' => false,
        ]);
        unset($this->inboxApplicants, $this->needsReviewApplicants, $this->activeApplicants, $this->completedApplicants, $this->applicantCount, $this->phasedApplicants);
        $this->dispatch('sidebar-refresh');
    }

    public function deleteApplicant(int $applicantId): void
    {
        $applicant = RecApplicant::forTeam(auth()->user()->currentTeam->id)->findOrFail($applicantId);
        $applicant->postings()->detach();
        $applicant->extraFieldValues()->delete();
        $applicant->crmContactLinks()->delete();
        $applicant->delete();
        unset($this->inboxApplicants, $this->needsReviewApplicants, $this->activeApplicants, $this->completedApplicants, $this->applicantCount, $this->phasedApplicants);
        $this->dispatch('sidebar-refresh');
    }

    public function deleteAndBlacklistApplicant(int $applicantId): void
    {
        $applicant = RecApplicant::forTeam(auth()->user()->currentTeam->id)->findOrFail($applicantId);

        foreach ($applicant->crmContactLinks as $link) {
            if ($link->contact) {
                $link->contact->update(['is_blacklisted' => true]);
            }
        }

        $applicant->postings()->detach();
        $applicant->extraFieldValues()->delete();
        $applicant->crmContactLinks()->delete();
        $applicant->delete();
        unset($this->inboxApplicants, $this->needsReviewApplicants, $this->activeApplicants, $this->completedApplicants, $this->applicantCount, $this->phasedApplicants);
        $this->dispatch('sidebar-refresh');
    }

    public function render()
    {
        return view('recruiting::livewire.dashboard.dashboard')
            ->layout('platform::layouts.app');
    }
}
