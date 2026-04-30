<?php

namespace Platform\Recruiting\Livewire\HrDesk;

use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Platform\Recruiting\Models\RecApplicant;
use Platform\Recruiting\Models\RecHrDeskCase;
use Platform\Recruiting\Services\HrDeskRoutingService;

/**
 * HR-Schreibtisch — fokussierte Bewerber-Case-Liste.
 *
 * Zeigt ALLE Bewerber mit `is_on_hr_desk=true` und mind. einem offenen
 * RecHrDeskCase. Pro Bewerber-Card sieht HR den Reason, Datum, Bewerber-
 * Details und kann den Case freigeben (= zurück in normalen Flow) oder
 * ablehnen (= rejected_at gesetzt).
 *
 * Bewusst eigene Page statt Reuse vom Dashboard, weil die Aufgaben
 * unterschiedlich sind: Dashboard = Pipeline-/KPI-Sicht für laufende
 * Bewerber; HR-Desk = Triage-Liste für hängende Cases.
 */
class Index extends Component
{
    /** Filter-Reason-Code; 'all' für keinen Filter */
    public string $reasonFilter = 'all';

    /** Resolve-Modal State */
    public bool $resolveModalShow = false;
    public ?int $resolvingCaseId = null;
    public string $resolvingAction = ''; // 'approve' | 'reject'
    public string $resolveNotes = '';

    #[Computed]
    public function reasonCounts(): array
    {
        $teamId = (int) Auth::user()->currentTeam->id;
        $base = RecApplicant::forTeam($teamId)
            ->routed()
            ->where('is_active', true)
            ->where('is_parked', false)
            ->where('is_on_hr_desk', true)
            ->whereNull('rejected_at');

        $counts = ['all' => (clone $base)->count()];
        foreach (RecHrDeskCase::REASON_LABELS as $reason => $label) {
            $counts[$reason] = (clone $base)
                ->whereHas('hrDeskCases', fn ($q) => $q->where('reason', $reason)->open())
                ->count();
        }
        return $counts;
    }

    #[Computed]
    public function cases()
    {
        $teamId = (int) Auth::user()->currentTeam->id;

        $query = RecHrDeskCase::query()
            ->forTeam($teamId)
            ->open()
            ->with([
                'applicant.crmContactLinks.contact.emailAddresses',
                'applicant.crmContactLinks.contact.phoneNumbers',
                'applicant.phase',
                'applicant.postings.position',
            ])
            ->whereHas('applicant', function ($q) {
                $q->where('is_active', true)
                    ->where('is_parked', false)
                    ->where('is_on_hr_desk', true)
                    ->whereNull('rejected_at')
                    ->where('is_unrouted', false);
            })
            ->orderBy('opened_at', 'desc');

        if ($this->reasonFilter !== 'all') {
            $query->where('reason', $this->reasonFilter);
        }

        return $query->get();
    }

    public function openResolveModal(int $caseId, string $action): void
    {
        if (!in_array($action, ['approve', 'reject'])) {
            return;
        }
        $this->resolvingCaseId = $caseId;
        $this->resolvingAction = $action;
        $this->resolveNotes = '';
        $this->resolveModalShow = true;
    }

    public function closeResolveModal(): void
    {
        $this->resolveModalShow = false;
        $this->resolvingCaseId = null;
        $this->resolvingAction = '';
        $this->resolveNotes = '';
    }

    public function confirmResolve(): void
    {
        if (!$this->resolvingCaseId || !$this->resolvingAction) {
            return;
        }
        $teamId = (int) Auth::user()->currentTeam->id;
        $userId = (int) Auth::id();

        $case = RecHrDeskCase::forTeam($teamId)->find($this->resolvingCaseId);
        if (!$case || !$case->isOpen()) {
            $this->closeResolveModal();
            return;
        }

        $service = app(HrDeskRoutingService::class);
        $notes = trim($this->resolveNotes) ?: null;

        if ($this->resolvingAction === 'approve') {
            $service->approveCase($case, $userId, $notes);
            session()->flash('message', 'Case freigegeben — Bewerber zurück im normalen Flow.');
        } else {
            $service->rejectCase($case, $userId, $notes);
            session()->flash('message', 'Bewerber abgelehnt.');
        }

        unset($this->cases, $this->reasonCounts);
        $this->closeResolveModal();
    }

    public function render()
    {
        return view('recruiting::livewire.hr-desk.index')
            ->layout('platform::layouts.app');
    }
}
