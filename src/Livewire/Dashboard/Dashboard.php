<?php

namespace Platform\Recruiting\Livewire\Dashboard;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Livewire\Component;
use Livewire\Attributes\Computed;
use Platform\Crm\Models\CommsChannel;
use Platform\Crm\Models\CommsWhatsAppThread;
use Platform\Crm\Models\CrmPhoneNumber;
use Platform\Recruiting\Models\RecApplicant;
use Platform\Recruiting\Models\RecContract;
use Platform\Recruiting\Models\RecInterviewBooking;
use Platform\Recruiting\Models\RecPhase;
use Platform\Recruiting\Models\RecPosition;
use Platform\Core\Livewire\Concerns\ResolvesAutoPilotChannel;
use Platform\Recruiting\Models\RecPosting;

class Dashboard extends Component
{
    use ResolvesAutoPilotChannel;

    public bool $showParked = false;
    public bool $showHrDesk = false;
    /**
     * Legacy-Modus: zeigt nur Stellen mit '_old'-Suffix + alle aktiven
     * Phasen davon (ignoriert show_in_dashboard-Flag). Wird via Subklasse
     * DashboardLegacy aktiviert. Default-Dashboard zeigt nur Stellen
     * OHNE '_old'-Suffix.
     */
    public bool $legacyMode = false;
    public ?int $positionFilter = null;
    public ?int $phaseFilter = null;
    public ?string $activityFilter = null;
    public ?string $filterFrom = null; // Y-m-d
    public ?string $filterTo = null;   // Y-m-d
    public array $positionStatsUniqueTotals = [];
    public array $activityStatsUniqueTotals = [];

    public function mount(): void
    {
        $this->showParked = request()->routeIs('recruiting.dashboard.parked');
        $this->showHrDesk = request()->routeIs('recruiting.dashboard.hr-desk');
    }

    /**
     * Liefert die IDs der Stellen die im aktuellen Mode (legacy vs.
     * production) sichtbar sind. Production: ohne '_old'-Suffix.
     * Legacy: mit '_old'-Suffix. Wird zentral fuer alle Queries genutzt
     * sodass die Filter-Logik nicht in mehreren Methoden dupliziert wird.
     */
    protected function modeScopedPositionIds(): array
    {
        $cacheKey = 'mode_scoped_positions_' . ($this->legacyMode ? 'legacy' : 'prod') . '_' . auth()->user()->currentTeam->id;
        return Cache::remember($cacheKey, 30, function () {
            $q = RecPosition::forTeam(auth()->user()->currentTeam->id);
            // Legacy-Marker: Title enthaelt " bis " (z.B. "Duesseldorf bis
            // 22.05.26"). Production-Stellen haben keinen solchen Suffix.
            if ($this->legacyMode) {
                $q->where('title', 'like', '% bis %');
            } else {
                $q->where('title', 'not like', '% bis %');
            }
            return $q->pluck('id')->all();
        });
    }

    private function applicantBaseQuery()
    {
        $query = RecApplicant::forTeam(auth()->user()->currentTeam->id)
            ->routed()
            ->withoutImports()
            ->where('is_active', true)
            ->whereNull('rejected_at');

        // Mode-Scoping: nur Bewerber von Stellen des aktuellen Modes
        $scopedPositionIds = $this->modeScopedPositionIds();
        $query->whereHas('postings', fn ($q) => $q->whereIn('rec_position_id', $scopedPositionIds));

        if ($this->showHrDesk) {
            $query->where('is_on_hr_desk', true)->where('is_parked', false);
        } else {
            $query->where('is_on_hr_desk', false)->where('is_parked', $this->showParked);
        }
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

        if ($this->filterFrom) {
            $query->where('applied_at', '>=', $this->filterFrom);
        }
        if ($this->filterTo) {
            $query->where('applied_at', '<=', $this->filterTo);
        }

        if ($this->activityFilter) {
            $query->whereHas('postings', fn ($q) => $q->where('activity', $this->activityFilter));
        }

        return $query;
    }

    #[Computed]
    public function positions()
    {
        return RecPosition::forTeam(auth()->user()->currentTeam->id)
            ->active()
            ->whereIn('id', $this->modeScopedPositionIds())
            ->orderBy('title')
            ->get();
    }

    #[Computed]
    public function phases()
    {
        if ($this->positionFilter) {
            $position = RecPosition::find($this->positionFilter);
            // Im Legacy-Mode show_in_dashboard-Flag ignorieren (alte
            // Phasen haben false, sollen aber im Legacy-Dashboard sichtbar
            // sein).
            $query = $position?->phases()->active()->ordered();
            if ($query && !$this->legacyMode) {
                $query->where('show_in_dashboard', true);
            }
            return $query?->get() ?? collect();
        }

        // Ohne Position-Filter: distinct Phasen-Orders der mode-scoped
        // Stellen. Legacy-Mode ignoriert show_in_dashboard.
        $phaseQuery = RecPhase::forTeam(auth()->user()->currentTeam->id)
            ->active()
            ->whereIn('rec_position_id', $this->modeScopedPositionIds());
        if (!$this->legacyMode) {
            $phaseQuery->where('show_in_dashboard', true);
        }
        return $phaseQuery
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
            ->when(!$this->legacyMode, fn ($q) => $q->whereHas('phase', fn ($q2) => $q2->where('show_in_dashboard', true)))
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
        return RecPosition::forTeam(auth()->user()->currentTeam->id)
            ->active()
            ->whereIn('id', $this->modeScopedPositionIds())
            ->count();
    }

    #[Computed]
    public function postingCount()
    {
        if ($this->positionFilter) {
            return RecPosting::where('rec_position_id', $this->positionFilter)->active()->count();
        }
        return RecPosting::forTeam(auth()->user()->currentTeam->id)
            ->active()
            ->whereIn('rec_position_id', $this->modeScopedPositionIds())
            ->count();
    }

    /**
     * Returns the applicants used for both positionStats and activityStats:
     * the active, non-parked, non-HR-desk, non-rejected pool inside the date range,
     * eager-loaded with postings.position, interviewBookings and contracts.
     */
    private function statsApplicantPool()
    {
        $teamId = auth()->user()->currentTeam->id;

        $query = RecApplicant::forTeam($teamId)
            ->routed()
            ->withoutImports()
            ->where('is_active', true)
            ->where('is_parked', false)
            ->where('is_on_hr_desk', false)
            ->whereNull('rejected_at')
            ->with(['postings.position', 'interviewBookings', 'contracts']);

        if ($this->filterFrom) {
            $query->where('applied_at', '>=', $this->filterFrom);
        }
        if ($this->filterTo) {
            $query->where('applied_at', '<=', $this->filterTo);
        }

        if ($this->activityFilter) {
            $query->whereHas('postings', fn ($q) => $q->where('activity', $this->activityFilter));
        }

        return $query->get();
    }

    private function emptyStatRow(string $label): array
    {
        return [
            'label' => $label,
            'total' => 0,
            'contacted' => 0,
            'completed' => 0,
            'booked' => 0,
            'confirmed' => 0,
            'signed' => 0,
            'conversion' => 0,
        ];
    }

    private function bumpStatRow(array $row, RecApplicant $applicant, $bookings, $contracts): array
    {
        $row['total']++;
        if ($applicant->enrichment_status && $applicant->enrichment_status !== 'no_contact') {
            $row['contacted']++;
        }
        if ($applicant->auto_pilot_completed_at) {
            $row['completed']++;
        }
        if ($bookings->isNotEmpty()) {
            $row['booked']++;
            if ($bookings->contains('status', 'confirmed')) {
                $row['confirmed']++;
            }
        }
        if ($contracts->whereNotNull('signed_at')->isNotEmpty()) {
            $row['signed']++;
        }
        return $row;
    }

    private function finalizeStatRow(array $row): array
    {
        $row['conversion'] = $row['total'] > 0
            ? (int) round(($row['signed'] / $row['total']) * 100)
            : 0;
        unset($row['label']); // not used in row output anymore
        return $row;
    }

    #[Computed]
    public function positionStats(): array
    {
        $applicants = $this->statsApplicantPool();

        $stats = [];
        $noPosition = [];
        $uniqueIds = ['total' => [], 'contacted' => [], 'completed' => [], 'booked' => [], 'confirmed' => [], 'signed' => []];

        foreach ($applicants as $applicant) {
            $bookings = $applicant->interviewBookings;
            $contracts = $applicant->contracts;
            $hasSigned = $contracts->whereNotNull('signed_at')->isNotEmpty();

            // Unique-Tracking (ein Bewerber zählt einmal egal auf wie vielen Positionen)
            $uniqueIds['total'][] = $applicant->id;
            if ($applicant->enrichment_status && $applicant->enrichment_status !== 'no_contact') {
                $uniqueIds['contacted'][] = $applicant->id;
            }
            if ($applicant->auto_pilot_completed_at) {
                $uniqueIds['completed'][] = $applicant->id;
            }
            if ($bookings->isNotEmpty()) {
                $uniqueIds['booked'][] = $applicant->id;
                if ($bookings->contains('status', 'confirmed')) {
                    $uniqueIds['confirmed'][] = $applicant->id;
                }
            }
            if ($hasSigned) {
                $uniqueIds['signed'][] = $applicant->id;
            }

            $positions = $applicant->postings->map(fn ($p) => $p->position)->filter()->unique('id');

            if ($positions->isEmpty()) {
                $noPosition[] = ['applicant' => $applicant, 'bookings' => $bookings, 'contracts' => $contracts];
                continue;
            }

            foreach ($positions as $position) {
                if (!isset($stats[$position->id])) {
                    $stats[$position->id] = $this->emptyStatRow($position->title);
                }
                $stats[$position->id] = $this->bumpStatRow($stats[$position->id], $applicant, $bookings, $contracts);
            }
        }

        // Sort by title
        uasort($stats, fn ($a, $b) => strcasecmp($a['label'], $b['label']));

        $result = [];
        foreach ($stats as $row) {
            $title = $row['label'];
            $row = $this->finalizeStatRow($row);
            $row['position_title'] = $title;
            $result[] = $row;
        }

        // Add "Ohne Stelle" row if applicable
        if (!empty($noPosition)) {
            $row = $this->emptyStatRow('Ohne Stelle');
            foreach ($noPosition as $entry) {
                $row = $this->bumpStatRow($row, $entry['applicant'], $entry['bookings'], $entry['contracts']);
            }
            $row = $this->finalizeStatRow($row);
            $row['position_title'] = 'Ohne Stelle';
            $result[] = $row;
        }

        $totalUnique = count(array_unique($uniqueIds['total']));
        $signedUnique = count(array_unique($uniqueIds['signed']));
        $this->positionStatsUniqueTotals = [
            'total' => $totalUnique,
            'contacted' => count(array_unique($uniqueIds['contacted'])),
            'completed' => count(array_unique($uniqueIds['completed'])),
            'booked' => count(array_unique($uniqueIds['booked'])),
            'confirmed' => count(array_unique($uniqueIds['confirmed'])),
            'signed' => $signedUnique,
            'conversion' => $totalUnique > 0 ? (int) round(($signedUnique / $totalUnique) * 100) : 0,
        ];

        return $result;
    }

    #[Computed]
    public function activityStats(): array
    {
        $applicants = $this->statsApplicantPool();

        $stats = [];
        $noActivity = [];
        $uniqueIds = ['total' => [], 'contacted' => [], 'completed' => [], 'booked' => [], 'confirmed' => [], 'signed' => []];

        foreach ($applicants as $applicant) {
            $bookings = $applicant->interviewBookings;
            $contracts = $applicant->contracts;
            $hasSigned = $contracts->whereNotNull('signed_at')->isNotEmpty();

            $uniqueIds['total'][] = $applicant->id;
            if ($applicant->enrichment_status && $applicant->enrichment_status !== 'no_contact') {
                $uniqueIds['contacted'][] = $applicant->id;
            }
            if ($applicant->auto_pilot_completed_at) {
                $uniqueIds['completed'][] = $applicant->id;
            }
            if ($bookings->isNotEmpty()) {
                $uniqueIds['booked'][] = $applicant->id;
                if ($bookings->contains('status', 'confirmed')) {
                    $uniqueIds['confirmed'][] = $applicant->id;
                }
            }
            if ($hasSigned) {
                $uniqueIds['signed'][] = $applicant->id;
            }

            $activities = $applicant->postings
                ->pluck('activity')
                ->filter(fn ($a) => $a !== null && $a !== '')
                ->unique()
                ->values();

            if ($activities->isEmpty()) {
                $noActivity[] = ['applicant' => $applicant, 'bookings' => $bookings, 'contracts' => $contracts];
                continue;
            }

            foreach ($activities as $activity) {
                if (!isset($stats[$activity])) {
                    $stats[$activity] = $this->emptyStatRow($activity);
                }
                $stats[$activity] = $this->bumpStatRow($stats[$activity], $applicant, $bookings, $contracts);
            }
        }

        ksort($stats, SORT_NATURAL | SORT_FLAG_CASE);

        $result = [];
        foreach ($stats as $key => $row) {
            $row = $this->finalizeStatRow($row);
            $row['activity'] = $key;
            $result[] = $row;
        }

        if (!empty($noActivity)) {
            $row = $this->emptyStatRow('Ohne Tätigkeit');
            foreach ($noActivity as $entry) {
                $row = $this->bumpStatRow($row, $entry['applicant'], $entry['bookings'], $entry['contracts']);
            }
            $row = $this->finalizeStatRow($row);
            $row['activity'] = 'Ohne Tätigkeit';
            $result[] = $row;
        }

        $totalUnique = count(array_unique($uniqueIds['total']));
        $signedUnique = count(array_unique($uniqueIds['signed']));
        $this->activityStatsUniqueTotals = [
            'total' => $totalUnique,
            'contacted' => count(array_unique($uniqueIds['contacted'])),
            'completed' => count(array_unique($uniqueIds['completed'])),
            'booked' => count(array_unique($uniqueIds['booked'])),
            'confirmed' => count(array_unique($uniqueIds['confirmed'])),
            'signed' => $signedUnique,
            'conversion' => $totalUnique > 0 ? (int) round(($signedUnique / $totalUnique) * 100) : 0,
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
            ->routed()
            ->withoutImports()
            ->where('is_on_hr_desk', true)
            ->count();
    }

    #[Computed]
    public function unroutedCount()
    {
        return RecApplicant::forTeam(auth()->user()->currentTeam->id)
            ->unrouted()
            ->withoutImports()
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

    #[Computed]
    public function availableActivities()
    {
        return RecPosting::forTeam(auth()->user()->currentTeam->id)
            ->whereNotNull('activity')
            ->where('activity', '!=', '')
            ->distinct()
            ->orderBy('activity')
            ->pluck('activity')
            ->values();
    }

    /**
     * Time-to-Hire (median + average) in days, applied_at -> signed_at,
     * over applicants in the current filter scope whose contract is signed.
     */
    #[Computed]
    public function timeToHire(): array
    {
        $teamId = auth()->user()->currentTeam->id;

        $applicantQuery = RecApplicant::forTeam($teamId)
            ->routed()
            ->withoutImports()
            ->whereNotNull('applied_at')
            ->whereHas('contracts', fn ($q) => $q->whereNotNull('signed_at'));

        if ($this->filterFrom) {
            $applicantQuery->where('applied_at', '>=', $this->filterFrom);
        }
        if ($this->filterTo) {
            $applicantQuery->where('applied_at', '<=', $this->filterTo);
        }
        if ($this->positionFilter) {
            $postingIds = $this->postingIdsForPosition();
            $applicantQuery->whereHas('postings', fn ($q) => $q->whereIn('rec_postings.id', $postingIds));
        }
        if ($this->activityFilter) {
            $applicantQuery->whereHas('postings', fn ($q) => $q->where('activity', $this->activityFilter));
        }

        $applicants = $applicantQuery->with(['contracts' => fn ($q) => $q->whereNotNull('signed_at')->orderBy('signed_at')])
            ->get();

        $days = [];
        foreach ($applicants as $applicant) {
            $signedAt = $applicant->contracts->first()?->signed_at;
            if (!$applicant->applied_at || !$signedAt) continue;
            $days[] = max(0, $applicant->applied_at->startOfDay()->diffInDays($signedAt->startOfDay()));
        }

        if (empty($days)) {
            return ['median' => null, 'avg' => null, 'count' => 0];
        }

        sort($days);
        $count = count($days);
        $median = $count % 2 === 0
            ? (int) round(($days[$count / 2 - 1] + $days[$count / 2]) / 2)
            : $days[(int) floor($count / 2)];
        $avg = (int) round(array_sum($days) / $count);

        return ['median' => $median, 'avg' => $avg, 'count' => $count];
    }

    /**
     * Block C — Stuck-Indikatoren.
     * 1) AutoPilot hängt:    auto_pilot=true, !completed, letzter Reminder > 5 Tage
     * 2) Booking ohne Vertrag: nicht-cancelled Booking älter als 3 Tage, kein Vertrag
     * 3) Vertrag versendet:    sent_at gesetzt, signed_at NULL, sent_at > 3 Tage
     */
    #[Computed]
    public function stuckCounts(): array
    {
        $teamId = auth()->user()->currentTeam->id;
        $now = now();

        // 1) AutoPilot stuck — Bewerber > 5 Tage im AutoPilot ohne Abschluss
        $autoPilotStuck = RecApplicant::forTeam($teamId)
            ->routed()
            ->withoutImports()
            ->where('is_active', true)
            ->whereNull('rejected_at')
            ->where('auto_pilot', true)
            ->whereNull('auto_pilot_completed_at')
            ->where('applied_at', '<=', $now->copy()->subDays(5)->toDateString())
            ->count();

        // 2) Interview gebucht, kein Vertrag (älter als 3 Tage)
        $cutoff3 = $now->copy()->subDays(3);
        $bookingApplicantIds = RecInterviewBooking::query()
            ->where('team_id', $teamId)
            ->whereNotIn('status', ['cancelled'])
            ->where('created_at', '<=', $cutoff3)
            ->pluck('rec_applicant_id')
            ->unique();

        $interviewWithoutContract = RecApplicant::forTeam($teamId)
            ->routed()
            ->withoutImports()
            ->where('is_active', true)
            ->whereNull('rejected_at')
            ->whereIn('id', $bookingApplicantIds)
            ->whereDoesntHave('contracts')
            ->count();

        // 3) Vertrag versendet, nicht unterschrieben (sent_at > 3 Tage)
        //    Imports rausfiltern: Altbestand-Bewerber sollen nicht in den
        //    Recruiting-Stuck-Indikatoren auftauchen.
        $contractSentNotSigned = RecContract::query()
            ->where('team_id', $teamId)
            ->whereNotNull('sent_at')
            ->whereNull('signed_at')
            ->where('sent_at', '<=', $cutoff3)
            ->whereHas('applicant', fn ($q) => $q->withoutImports())
            ->distinct('rec_applicant_id')
            ->count('rec_applicant_id');

        return [
            'autopilot_stuck' => $autoPilotStuck,
            'interview_no_contract' => $interviewWithoutContract,
            'contract_sent_not_signed' => $contractSentNotSigned,
        ];
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

    public function updatedActivityFilter(): void
    {
        $this->refreshDashboard();
    }

    public function updatedFilterFrom(): void
    {
        $this->refreshDashboard();
    }

    public function updatedFilterTo(): void
    {
        $this->refreshDashboard();
    }

    /**
     * Quick-range presets for the date filter. UI calls these via wire:click.
     * `preset` is one of: this_week, this_month, last_month, q1, q2, q3, q4, clear.
     */
    public function applyDatePreset(string $preset): void
    {
        $now = now();
        switch ($preset) {
            case 'this_week':
                $this->filterFrom = $now->copy()->startOfWeek()->toDateString();
                $this->filterTo   = $now->copy()->endOfWeek()->toDateString();
                break;
            case 'this_month':
                $this->filterFrom = $now->copy()->startOfMonth()->toDateString();
                $this->filterTo   = $now->copy()->endOfMonth()->toDateString();
                break;
            case 'last_month':
                $last = $now->copy()->subMonthNoOverflow();
                $this->filterFrom = $last->copy()->startOfMonth()->toDateString();
                $this->filterTo   = $last->copy()->endOfMonth()->toDateString();
                break;
            case 'q1':
            case 'q2':
            case 'q3':
            case 'q4':
                $q = (int) substr($preset, 1);
                $startMonth = ($q - 1) * 3 + 1;
                $this->filterFrom = $now->copy()->setMonth($startMonth)->startOfMonth()->toDateString();
                $this->filterTo   = $now->copy()->setMonth($startMonth + 2)->endOfMonth()->toDateString();
                break;
            case 'clear':
                $this->filterFrom = null;
                $this->filterTo   = null;
                break;
        }
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
            $this->availableActivities,
            $this->positionStats,
            $this->activityStats,
            $this->timeToHire,
            $this->stuckCounts,
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
