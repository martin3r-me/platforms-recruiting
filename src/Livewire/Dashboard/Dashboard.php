<?php

namespace Platform\Recruiting\Livewire\Dashboard;

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

    public bool $showParked = false;
    public ?int $positionFilter = null;
    public ?int $phaseFilter = null;
    public ?string $filterMonth = null;
    public array $positionStatsUniqueTotals = [];

    public function mount(): void
    {
        $this->showParked = request()->routeIs('recruiting.dashboard.parked');
    }

    private function applicantBaseQuery()
    {
        $query = RecApplicant::forTeam(auth()->user()->currentTeam->id)
            ->where('is_active', true)
            ->where('is_on_hr_desk', false)
            ->whereNull('rejected_at')
            ->where('is_parked', $this->showParked);
        $this->applyPositionFilter($query);

        if ($this->phaseFilter) {
            if ($this->positionFilter) {
                $query->where('rec_phase_id', $this->phaseFilter);
            } else {
                // phaseFilter is phase order — resolve all phase IDs with that order
                $phaseIds = RecPhase::forTeam(auth()->user()->currentTeam->id)
                    ->active()
                    ->where('order', $this->phaseFilter)
                    ->pluck('id');
                $query->whereIn('rec_phase_id', $phaseIds);
            }
        }

        if ($this->filterMonth) {
            $start = \Carbon\Carbon::createFromFormat('Y-m', $this->filterMonth)->startOfMonth();
            $end = $start->copy()->endOfMonth();
            $query->whereBetween('created_at', [$start, $end]);
        }

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
        if ($this->positionFilter) {
            $position = RecPosition::find($this->positionFilter);
            return $position?->phases()->active()->ordered()->get() ?? collect();
        }

        // Without position filter: distinct phase orders across all positions
        return RecPhase::forTeam(auth()->user()->currentTeam->id)
            ->active()
            ->ordered()
            ->get()
            ->groupBy('order')
            ->map(fn ($group) => (object) [
                'id' => 'order_' . $group->first()->order,
                'name' => $group->first()->name,
                'order' => $group->first()->order,
                'auto_advance' => $group->first()->auto_advance,
                'phase_ids' => $group->pluck('id')->toArray(),
            ])
            ->values();
    }

    #[Computed]
    public function phasedApplicants()
    {
        $query = $this->applicantBaseQuery()
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
            ->orderByDesc('created_at');

        // Without positionFilter: only include applicants that have NOT completed (same as activeApplicants)
        if (!$this->positionFilter) {
            $query->whereNull('auto_pilot_completed_at');
        }

        $enrichedApplicants = $query->get();

        $grouped = [];
        $assignedIds = collect();

        if ($this->positionFilter) {
            // Group by actual phase ID
            foreach ($this->phases as $phase) {
                $phaseApplicants = $enrichedApplicants
                    ->filter(fn ($a) => $a->rec_phase_id === $phase->id)
                    ->values();
                $grouped[$phase->id] = $phaseApplicants;
                $assignedIds = $assignedIds->merge($phaseApplicants->pluck('id'));
            }
        } else {
            // Group by phase order (aggregated across positions)
            foreach ($this->phases as $phase) {
                $phaseApplicants = $enrichedApplicants
                    ->filter(fn ($a) => in_array($a->rec_phase_id, $phase->phase_ids))
                    ->values();
                $grouped[$phase->id] = $phaseApplicants;
                $assignedIds = $assignedIds->merge($phaseApplicants->pluck('id'));
            }
        }

        // Applicants without a phase (or with a phase not matching any active phase)
        $unassigned = $enrichedApplicants->reject(fn ($a) => $assignedIds->contains($a->id))->values();
        if ($unassigned->isNotEmpty()) {
            $grouped['no_phase'] = $unassigned;
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
    public function positionStats(): array
    {
        $teamId = auth()->user()->currentTeam->id;

        $query = RecApplicant::forTeam($teamId)
            ->where('is_active', true)
            ->where('is_parked', false)
            ->where('is_on_hr_desk', false)
            ->whereNull('rejected_at')
            ->with(['postings.position', 'interviewBookings']);

        if ($this->filterMonth) {
            $start = \Carbon\Carbon::createFromFormat('Y-m', $this->filterMonth)->startOfMonth();
            $end = $start->copy()->endOfMonth();
            $query->whereBetween('created_at', [$start, $end]);
        }

        $applicants = $query->get();

        $stats = [];
        $noPosition = [];
        // Track unique applicant IDs per stat to avoid double-counting in Gesamt
        $uniqueIds = ['total' => [], 'contacted' => [], 'completed' => [], 'booked' => [], 'confirmed' => []];

        foreach ($applicants as $applicant) {
            $positions = $applicant->postings->map(fn ($p) => $p->position)->filter()->unique('id');

            if ($positions->isEmpty()) {
                $noPosition[] = $applicant;
                $uniqueIds['total'][] = $applicant->id;
                if ($applicant->enrichment_status && $applicant->enrichment_status !== 'no_contact') {
                    $uniqueIds['contacted'][] = $applicant->id;
                }
                if ($applicant->auto_pilot_completed_at) {
                    $uniqueIds['completed'][] = $applicant->id;
                }
                $bookings = $applicant->interviewBookings;
                if ($bookings->isNotEmpty()) {
                    $uniqueIds['booked'][] = $applicant->id;
                    if ($bookings->contains('status', 'confirmed')) {
                        $uniqueIds['confirmed'][] = $applicant->id;
                    }
                }
                continue;
            }

            // Track unique IDs (only once per applicant, even if multiple positions)
            $uniqueIds['total'][] = $applicant->id;
            if ($applicant->enrichment_status && $applicant->enrichment_status !== 'no_contact') {
                $uniqueIds['contacted'][] = $applicant->id;
            }
            if ($applicant->auto_pilot_completed_at) {
                $uniqueIds['completed'][] = $applicant->id;
            }
            $bookings = $applicant->interviewBookings;
            if ($bookings->isNotEmpty()) {
                $uniqueIds['booked'][] = $applicant->id;
                if ($bookings->contains('status', 'confirmed')) {
                    $uniqueIds['confirmed'][] = $applicant->id;
                }
            }

            foreach ($positions as $position) {
                if (!isset($stats[$position->id])) {
                    $stats[$position->id] = [
                        'position_title' => $position->title,
                        'total' => 0,
                        'contacted' => 0,
                        'completed' => 0,
                        'booked' => 0,
                        'confirmed' => 0,
                    ];
                }

                $stats[$position->id]['total']++;

                if ($applicant->enrichment_status && $applicant->enrichment_status !== 'no_contact') {
                    $stats[$position->id]['contacted']++;
                }

                if ($applicant->auto_pilot_completed_at) {
                    $stats[$position->id]['completed']++;
                }

                if ($bookings->isNotEmpty()) {
                    $stats[$position->id]['booked']++;

                    if ($bookings->contains('status', 'confirmed')) {
                        $stats[$position->id]['confirmed']++;
                    }
                }
            }
        }

        // Sort by title
        uasort($stats, fn ($a, $b) => strcasecmp($a['position_title'], $b['position_title']));

        $result = array_values($stats);

        // Add "Ohne Stelle" row if applicable
        if (!empty($noPosition)) {
            $row = [
                'position_title' => 'Ohne Stelle',
                'total' => 0,
                'contacted' => 0,
                'completed' => 0,
                'booked' => 0,
                'confirmed' => 0,
            ];

            foreach ($noPosition as $applicant) {
                $row['total']++;
                if ($applicant->enrichment_status && $applicant->enrichment_status !== 'no_contact') {
                    $row['contacted']++;
                }
                if ($applicant->auto_pilot_completed_at) {
                    $row['completed']++;
                }
                $bookings = $applicant->interviewBookings;
                if ($bookings->isNotEmpty()) {
                    $row['booked']++;
                    if ($bookings->contains('status', 'confirmed')) {
                        $row['confirmed']++;
                    }
                }
            }

            $result[] = $row;
        }

        // Add unique totals (deduplicated across positions)
        $this->positionStatsUniqueTotals = [
            'total' => count(array_unique($uniqueIds['total'])),
            'contacted' => count(array_unique($uniqueIds['contacted'])),
            'completed' => count(array_unique($uniqueIds['completed'])),
            'booked' => count(array_unique($uniqueIds['booked'])),
            'confirmed' => count(array_unique($uniqueIds['confirmed'])),
        ];

        return $result;
    }

    #[Computed]
    public function applicantCount()
    {
        return $this->applicantBaseQuery()->count();
    }

    #[Computed]
    public function hrDeskCount()
    {
        return RecApplicant::forTeam(auth()->user()->currentTeam->id)
            ->where('is_on_hr_desk', true)
            ->count();
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
        $this->phaseFilter = null;
        $this->refreshDashboard();
    }

    public function updatedPhaseFilter(): void
    {
        $this->refreshDashboard();
    }

    public function updatedFilterMonth(): void
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
            $this->positionStats,
            $this->hrDeskCount,
        );
    }

    public function assignPosting(int $applicantId, int $postingId): void
    {
        $applicant = RecApplicant::forTeam(auth()->user()->currentTeam->id)->findOrFail($applicantId);
        $applicant->postings()->syncWithoutDetaching([$postingId => ['applied_at' => now()]]);
        unset($this->inboxApplicants, $this->needsReviewApplicants, $this->activeApplicants, $this->completedApplicants, $this->phasedApplicants);
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
