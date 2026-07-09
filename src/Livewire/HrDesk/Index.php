<?php

namespace Platform\Recruiting\Livewire\HrDesk;

use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Platform\Recruiting\Exceptions\LegalStatusNotCheckedException;
use Platform\Recruiting\Models\RecApplicant;
use Platform\Recruiting\Models\RecContractTemplate;
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
                'applicant.legalStatus.additionalContractTemplate',
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
            try {
                $service->approveCase($case, $userId, $notes);
                session()->flash('message', 'Case freigegeben — Bewerber zurück im normalen Flow.');
            } catch (LegalStatusNotCheckedException) {
                session()->flash('message', 'Rechtsstatus noch nicht geprüft — bitte zuerst als geprüft markieren.');
            }
        } else {
            $service->rejectCase($case, $userId, $notes);
            session()->flash('message', 'Bewerber abgelehnt.');
        }

        unset($this->cases, $this->reasonCounts);
        $this->closeResolveModal();
    }

    /**
     * Verfuegbare AT-* Zusatzvertraege fuer das Dropdown auf der HR-Card.
     * Convention: Aufenthaltstitel-bezogene Templates mit Code-Praefix
     * 'AT-' (analog AV-* fuer Arbeitsvertraege, IFSG fuer Infektionsschutz).
     */
    #[Computed]
    public function availableAdditionalContractTemplates()
    {
        $teamId = (int) Auth::user()->currentTeam->id;

        return RecContractTemplate::where('team_id', $teamId)
            ->where('is_active', true)
            ->where('code', 'like', 'AT-%')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get(['id', 'name', 'code']);
    }

    /**
     * HR togglet den Rechtsstatus-Geprueft-Flag fuer einen Bewerber.
     * NULL → setzt now(). Timestamp → setzt NULL (Pruefung zurueckgenommen).
     */
    public function toggleLegalStatusChecked(int $applicantId): void
    {
        $teamId = (int) Auth::user()->currentTeam->id;

        $applicant = RecApplicant::forTeam($teamId)->with('legalStatus')->find($applicantId);
        if (!$applicant || !$applicant->legalStatus) {
            return;
        }

        $legalStatus = $applicant->legalStatus;
        $legalStatus->legal_status_checked_at = $legalStatus->legal_status_checked_at ? null : now();
        $legalStatus->save();

        session()->flash('message', $legalStatus->legal_status_checked_at
            ? 'Rechtsstatus als geprueft markiert.'
            : 'Rechtsstatus-Pruefung zurueckgenommen.');

        unset($this->cases);
    }

    /**
     * HR weist einem nicht-EU-Bewerber einen optionalen Zusatzvertrag zu.
     * Wert 0 / leer → setzt NULL (kein Zusatzvertrag).
     */
    public function setAdditionalContractTemplate(int $applicantId, $templateId): void
    {
        $teamId = (int) Auth::user()->currentTeam->id;

        $applicant = RecApplicant::forTeam($teamId)->with('legalStatus')->find($applicantId);
        if (!$applicant || !$applicant->legalStatus) {
            return;
        }

        $resolvedId = ((int) $templateId) > 0 ? (int) $templateId : null;

        // Optional Validation: Template muss aktiv sein und Code AT-* haben.
        // Wenn nicht: einfach NULL setzen (defensiv gegen manipulierten POST).
        if ($resolvedId !== null) {
            $valid = RecContractTemplate::where('team_id', $teamId)
                ->where('is_active', true)
                ->where('code', 'like', 'AT-%')
                ->where('id', $resolvedId)
                ->exists();
            if (!$valid) {
                $resolvedId = null;
            }
        }

        $applicant->legalStatus->additional_contract_template_id = $resolvedId;
        $applicant->legalStatus->save();

        session()->flash('message', $resolvedId
            ? 'Zusatzvertrag zugewiesen.'
            : 'Zusatzvertrag entfernt.');

        unset($this->cases);
    }

    public function render()
    {
        return view('recruiting::livewire.hr-desk.index')
            ->layout('platform::layouts.app');
    }
}
