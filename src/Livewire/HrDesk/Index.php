<?php

namespace Platform\Recruiting\Livewire\HrDesk;

use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Platform\Recruiting\Exceptions\LegalStatusNotCheckedException;
use Platform\Recruiting\Models\RecApplicant;
use Platform\Recruiting\Models\RecContract;
use Platform\Recruiting\Models\RecContractTemplate;
use Platform\Recruiting\Models\RecHrDeskCase;
use Platform\Recruiting\Models\RecInterviewBooking;
use Platform\Recruiting\Models\RecAutoPilotLog;
use Platform\Recruiting\Models\RecTrainingCertificate;
use Platform\Recruiting\Services\Comms\HoldingTemplateSender;
use Platform\Recruiting\Services\ContractDispatchService;
use Platform\Recruiting\Services\ContractSendEligibility;
use Platform\Recruiting\Services\HrDeskRoutingService;
use Platform\Recruiting\Services\IssueTrainingCertificateService;
use Platform\Recruiting\Support\CertificateIssuanceEligibility;

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
    // Absage-Nachricht beim Ablehnen von Jugendschutz-Fällen (nutzt das
    // U16-Template aus den Bewerber-Einstellungen). Nur angeboten, wenn
    // Template konfiguriert ist und der Bewerber eine Nummer hat.
    public bool $sendRejectionMessage = false;
    public bool $canSendRejectionMessage = false;
    // Teilnahme-Zertifikat beim Ablehnen mit ausstellen. NICHT vorausgewählt
    // (anders als sendRejectionMessage) — HR setzt den Haken bewusst. Sichtbar
    // nur bei attended-Buchung und eingeschaltetem Team-Schalter, aber für
    // JEDEN Ablehnungsgrund: Kriterium ist die Teilnahme, nicht der Grund.
    public bool $issueCertificate = false;
    public bool $canIssueCertificate = false;

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
                'applicant.contractTemplate',
                'applicant.contracts:id,rec_applicant_id,rec_contract_template_id,status,sent_at',
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

        $this->canSendRejectionMessage = false;
        $this->sendRejectionMessage = false;
        $this->canIssueCertificate = false;
        $this->issueCertificate = false;
        if ($action === 'reject') {
            $teamId = (int) Auth::user()->currentTeam->id;
            $case = RecHrDeskCase::forTeam($teamId)->find($caseId);
            $applicant = $case?->applicant;
            if ($case?->reason === RecHrDeskCase::REASON_MINOR) {
                $templateOk = app(HoldingTemplateSender::class)
                    ->configuredTemplateName($teamId, 'minor_rejection_template_id') !== null;
                $this->canSendRejectionMessage = $templateOk
                    && $applicant !== null
                    && $applicant->primaryContactPhone() !== null;
                // Default AN — bewusste Abwahl statt vergessener Absage.
                $this->sendRejectionMessage = $this->canSendRejectionMessage;
            }

            // Zertifikat-Angebot: bewusst OHNE Grund-Bedingung, also außerhalb
            // des Minderjährigen-Zweigs. $issueCertificate bleibt false — nur
            // die Sichtbarkeit wird hier entschieden.
            $this->canIssueCertificate = $this->certificateAvailableFor($applicant, $teamId);
        }

        $this->resolveModalShow = true;
    }

    public function closeResolveModal(): void
    {
        $this->resolveModalShow = false;
        $this->resolvingCaseId = null;
        $this->resolvingAction = '';
        $this->resolveNotes = '';
        $this->sendRejectionMessage = false;
        $this->canSendRejectionMessage = false;
        $this->issueCertificate = false;
        $this->canIssueCertificate = false;
    }

    /**
     * Darf für diesen Bewerber ein Zertifikat angeboten und ausgestellt werden?
     *
     * Wird ZWEIMAL gebraucht, und der zweite Aufruf ist der wichtige:
     * $canIssueCertificate und $issueCertificate sind öffentliche Livewire-
     * Properties und damit vom Client schreibbar — beim Bestätigen muss die
     * Frage deshalb erneut serverseitig gestellt werden, nicht nur beim Öffnen
     * des Modals.
     *
     * NICHT billig (Team-Schalter + exists). Im Bestätigen-Pfad steht der Haken
     * deshalb ZUERST im &&, damit eine Ablehnung ohne Haken keinen zusätzlichen
     * Query kostet.
     */
    private function certificateAvailableFor(?RecApplicant $applicant, int $teamId): bool
    {
        if ($applicant === null) {
            return false;
        }

        return CertificateIssuanceEligibility::isAvailable(
            // attendedApplicantIds ist eine MAP applicantId => Position
            // (pluck→flip), die Frage ist also isset() auf dem SCHLÜSSEL — so
            // wie es die Card in der Blade-Datei auch tut. Ein
            // in_array($applicant->id, ...) wäre hier still falsch: es prüfte
            // gegen die Positions-Werte 0, 1, 2 …
            isset($this->attendedApplicantIds[$applicant->id]),
            app(IssueTrainingCertificateService::class)->isEnabledForTeam($teamId),
            // Mit kind-Filter, weil die Dedup-Dimension von
            // rec_training_certificates (Bewerber, Art) ist: ein Zertifikat
            // einer ANDEREN Schulungsart darf das hier nicht verdecken.
            RecTrainingCertificate::where('rec_applicant_id', $applicant->id)
                ->where('kind', RecTrainingCertificate::KIND_SERVICE_BASIS)
                ->exists(),
        );
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
            // Der Haken steht ZUERST: er schließt die Nachprüfung im Normalfall
            // kurz (PHP wertet die rechte Seite samt Argumenten nur bei true
            // aus), damit eine Ablehnung ohne Haken exakt so viele Queries
            // kostet wie vor dem Umbau. Die Nachprüfung selbst ist nicht
            // optional — siehe certificateAvailableFor().
            $issueCertificate = $this->issueCertificate
                && $this->certificateAvailableFor($case->applicant, $teamId);

            $service->rejectCase($case, $userId, $notes, $issueCertificate);

            $messageSent = false;
            if ($this->sendRejectionMessage
                && $this->canSendRejectionMessage
                && $case->reason === RecHrDeskCase::REASON_MINOR) {
                $applicant = $case->applicant;
                $phone = $applicant?->primaryContactPhone();
                if ($applicant && $phone !== null) {
                    $firstName = trim((string) ($applicant->getExtraField('vorname')
                        ?? $applicant->crmContactLinks->first()?->contact?->first_name ?? ''));
                    $result = app(HoldingTemplateSender::class)
                        ->sendOne($teamId, $phone, $firstName, 'minor_rejection_template_id');
                    $messageSent = ($result['sent'] ?? 0) > 0;

                    try {
                        RecAutoPilotLog::create([
                            'rec_applicant_id' => $applicant->id,
                            'type' => 'rejection_message_sent',
                            'summary' => $messageSent
                                ? 'Absage-Nachricht (Jugendschutz-Template) per WhatsApp versendet (HR-Schreibtisch).'
                                : 'Absage-Nachricht konnte NICHT versendet werden: ' . ($result['error'] ?? 'Sendefehler'),
                            'details' => ['template_result' => $result],
                        ]);
                    } catch (\Throwable) {}
                }
            }

            session()->flash('message', $messageSent
                ? 'Bewerber abgelehnt — Absage-Nachricht versendet.'
                : 'Bewerber abgelehnt.');
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

    /** Vertragslaufzeit-Eingaben pro Bewerber: [applicantId => ['vertragsbeginn' => ?, 'vertragsende' => ?]] */
    public array $deskContractDates = [];

    #[Computed]
    public function defaultContractTemplate()
    {
        return RecContractTemplate::where('team_id', (int) Auth::user()->currentTeam->id)
            ->where('code', 'AV-default')
            ->where('is_active', true)
            ->first();
    }

    /**
     * Bewerber-IDs (der sichtbaren Fälle) mit attended-Booking — EIN
     * Batch-Query; steuert die Sichtbarkeit des Sende-Bereichs.
     */
    #[Computed]
    public function attendedApplicantIds(): array
    {
        $ids = $this->cases->pluck('rec_applicant_id')->filter()->unique()->values();
        if ($ids->isEmpty()) {
            return [];
        }

        return RecInterviewBooking::whereIn('rec_applicant_id', $ids)
            ->where('status', 'attended')
            ->pluck('rec_applicant_id')
            ->flip()
            ->all();
    }

    /**
     * Zuschlag setzen — identische Validierung wie die Nachbereitung
     * (setApplicantZuschlag): Ziffern + optional Komma/Punkt, max 2
     * Nachkommastellen, DECIMAL(5,2).
     */
    public function setDeskZuschlag(int $applicantId, $value): void
    {
        $applicant = RecApplicant::forTeam((int) Auth::user()->currentTeam->id)->find($applicantId);
        if (!$applicant) {
            return;
        }

        $raw = trim((string) $value);
        if ($raw === '') {
            $applicant->zuschlag = null;
            $applicant->save();
            unset($this->cases);
            return;
        }

        if (!preg_match('/^\d{1,3}([.,]\d{1,2})?$/', $raw)) {
            session()->flash('message', 'Zuschlag muss eine Zahl sein (z.B. 0,60).');
            return;
        }

        $applicant->zuschlag = round((float) str_replace(',', '.', $raw), 2);
        $applicant->save();
        unset($this->cases);
    }

    /**
     * Vertragslaufzeit setzen — gleiche Auto-Calc-Vorbelegung wie die
     * Nachbereitung (setContractDate): Beginn gesetzt + Ende leer →
     * Ende via resolveContractDates (+1 Jahr, Anfang Monat, −1 Tag).
     */
    public function setDeskContractDate(int $applicantId, string $field, ?string $value): void
    {
        if (!in_array($field, ['vertragsbeginn', 'vertragsende'], true)) {
            return;
        }

        $value = $value !== '' ? $value : null;
        $current = $this->deskContractDates[$applicantId] ?? ['vertragsbeginn' => null, 'vertragsende' => null];
        $current[$field] = $value;

        if ($field === 'vertragsbeginn' && $value && empty($current['vertragsende'])) {
            $resolved = RecContract::resolveContractDates($value, null);
            $current['vertragsende'] = $resolved['vertragsende'];
        }

        $this->deskContractDates[$applicantId] = $current;
    }

    /**
     * "Portallink & Verträge versenden" vom HR-Schreibtisch: sendet über
     * den gemeinsamen ContractDispatchService (identische Sequenz wie der
     * Nachbereitungs-Bulk) und schließt bei Erfolg den Fall über den
     * bestehenden approveCase-Pfad (Desk-Entlassung, Auto-Pilot an,
     * Phase-Advance — Spec F1/F2). Selbstheilung: war schon gesendet
     * (skipped_already_sent), wird nur noch der Fall geschlossen.
     *
     * Portal-Probleme nach erfolgreichem Vertragsversand (status 'sent' +
     * portal_sent=false) verhindern den Fall-Abschluss NICHT — die Flash-
     * Meldung nennt das Problem aber explizit (Fehlertext, oder falls kein
     * RecEmployee gefunden wurde: message === null, F1-Edge), damit HR den
     * Portal-Link ggf. manuell nachsendet statt vollen Erfolg zu glauben.
     */
    public function sendContractsFromDesk(int $caseId): void
    {
        $teamId = (int) Auth::user()->currentTeam->id;
        $userId = (int) Auth::id();

        $case = RecHrDeskCase::forTeam($teamId)->with('applicant.legalStatus')->find($caseId);
        $applicant = $case?->applicant;
        if (!$case || !$case->isOpen() || $case->reason !== RecHrDeskCase::REASON_NON_EU_CITIZEN || !$applicant) {
            return;
        }

        // Gemeinsames Prädikat (Task 1) — identisch zum Bulk-Gate.
        $fields = $this->deskContractDates[$applicant->id] ?? null;
        $state = ContractSendEligibility::state(
            $applicant->hasAnyContractSent(),
            $applicant->isLegalStatusUnchecked(),
            !empty($fields['vertragsbeginn']),
            $applicant->zuschlag !== null,
        );

        if ($state === 'legal_blocked') {
            session()->flash('message', 'Rechtsstatus noch nicht geprüft — bitte zuerst als geprüft markieren.');
            return;
        }
        if ($state === 'missing_beginn') {
            session()->flash('message', 'Vertragsbeginn fehlt.');
            return;
        }
        if ($state === 'missing_zuschlag') {
            session()->flash('message', 'Zuschlag fehlt.');
            return;
        }

        $portalWarning = null;

        if ($state === 'ready') {
            $result = app(ContractDispatchService::class)
                ->sendForApplicant($applicant, $userId, $fields, $this->defaultContractTemplate);

            if ($result['status'] === 'error') {
                session()->flash('message', 'Versand fehlgeschlagen: ' . $result['message']);
                return; // Fall bleibt offen — kein halber Zustand.
            }

            if ($result['status'] === 'sent' && !$result['portal_sent']) {
                // Vertragsversand ok, Portal-Benachrichtigung fehlgeschlagen ODER
                // kein RecEmployee gefunden (message === null, F1-Edge) — Fall
                // trotzdem schliessen, aber Flash ehrlich halten statt vollen
                // Erfolg zu behaupten.
                $portalWarning = $result['message'] !== null
                    ? 'Verträge versendet, Portal-WA fehlgeschlagen: ' . $result['message'] . ' — Fall geschlossen; Portal-Link ggf. manuell senden.'
                    : 'Verträge versendet, Portal-WA nicht möglich (kein Mitarbeiter-Datensatz) — Fall geschlossen; Portal-Link ggf. manuell senden.';
            }
        }
        // state === 'already_sent' ODER erfolgreicher Versand: Fall schließen.

        try {
            app(HrDeskRoutingService::class)->approveCase($case, $userId, 'Verträge + Portallink vom HR-Schreibtisch versendet.');
            session()->flash('message', $portalWarning ?? 'Verträge + Portallink versendet — Fall geschlossen.');
        } catch (LegalStatusNotCheckedException) {
            session()->flash('message', 'Rechtsstatus noch nicht geprüft — bitte zuerst als geprüft markieren.');
        }

        unset($this->cases, $this->reasonCounts, $this->attendedApplicantIds);
    }

    public function render()
    {
        return view('recruiting::livewire.hr-desk.index')
            ->layout('platform::layouts.app');
    }
}
