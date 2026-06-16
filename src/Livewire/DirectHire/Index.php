<?php

namespace Platform\Recruiting\Livewire\DirectHire;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Platform\Recruiting\Models\RecApplicant;
use Platform\Recruiting\Models\RecContract;
use Platform\Recruiting\Models\RecContractTemplate;
use Platform\Recruiting\Models\RecPosition;
use Platform\Recruiting\Services\CreateEmployeeFromApplicantService;

/**
 * Direkteinstellungen-Uebersicht: listet aktive Direct-Hire-Stellen mit ihren
 * Bewerbern, gruppiert je Stelle. HR kann pro Bewerber die Datenerfassung
 * starten (Phase 1 -> Phase 2 + Portal-Link senden), parken, oeffnen oder
 * als Mitarbeiter anlegen (mit Vertragsauswahl).
 * Bewusst einfach gehalten — kein Phasen-Board.
 */
class Index extends Component
{
    public bool $onlyMine = false;

    public bool $showParked = false;

    // ── "Als Mitarbeiter anlegen"-Modal ──────────────────────────────
    public ?int $maApplicantId = null;

    public ?int $maContractTemplateId = null;

    public ?string $maZuschlag = null;

    // Ergebnis: kopierbarer MA-Portal-Login-Link nach erfolgreicher Anlage.
    public ?string $createdEmployeePortalLink = null;

    #[Computed]
    public function positions()
    {
        $q = RecPosition::forTeam((int) Auth::user()->currentTeam->id)
            ->directHire()
            ->where('is_active', true)
            ->with([
                'ownedByUser',
                'phases' => fn ($q) => $q->where('is_active', true)->orderBy('order'),
                'postings.externalRefs.sourcePlatform',
                'postings.commsChannels',
            ])
            ->orderBy('title');

        if ($this->onlyMine) {
            $q->where('owned_by_user_id', Auth::id());
        }

        return $q->get();
    }

    #[Computed]
    public function applicantsByPosition(): array
    {
        $positionIds = $this->positions->pluck('id')->all();

        if (empty($positionIds)) {
            return [];
        }

        return RecApplicant::query()
            ->forTeam((int) Auth::user()->currentTeam->id)
            ->where('is_active', true)
            ->where('is_parked', $this->showParked)
            ->whereHas('postings.position', fn ($q) => $q->whereIn('rec_positions.id', $positionIds))
            ->with([
                'crmContactLinks.contact.emailAddresses',
                'crmContactLinks.contact.phoneNumbers',
                'phase',
                'postings.position',
            ])
            ->orderByDesc('created_at')
            ->get()
            ->groupBy(fn (RecApplicant $a) => $a->postings->first()?->position?->id)
            ->all();
    }

    public function startDataCollection(int $applicantId): void
    {
        $applicant = RecApplicant::query()
            ->forTeam((int) Auth::user()->currentTeam->id)
            ->with('postings.position.phases')
            ->find($applicantId);
        if (!$applicant) {
            return;
        }

        $position = $applicant->postings->first()?->position;
        if (!$position || !$position->is_direct_hire) {
            return;
        }

        $phase2 = $position->phases()->where('order', 2)->where('is_active', true)->first();
        if (!$phase2) {
            session()->flash('message', 'Datenerfassung konnte nicht gestartet werden: keine Phase 2 auf der Stelle.');
            return;
        }

        $applicant->update([
            'rec_phase_id' => $phase2->id,
            'progress' => 0,
        ]);

        // Definition-Cache leeren, damit setExtraField/getExtraField die
        // Phase-2-Felder aufloest und nicht einen veralteten Phase-1-Cache
        // verwendet.
        $applicant->clearExtraFieldDefinitionsCache();

        // Phase-2-Datenfelder aus dem CRM-Kontakt vorbefuellen, damit der
        // Kandidat im Portal Name/E-Mail/Telefon angereichert sieht und
        // ueberschreiben kann. NUR wo das Extra-Field noch leer ist, damit
        // bereits erfasste Werte nicht ueberschrieben werden (idempotent).
        $this->prefillContactFields($applicant);

        session()->flash('message', 'Datenerfassung gestartet — der Bewerber ist jetzt in Phase „Datenerfassung". Kopiere den Portal-Link und schicke ihn dem Kandidaten.');

        unset($this->applicantsByPosition, $this->positions);
        $this->dispatch('sidebar-refresh');
    }

    /**
     * Befuellt die Phase-2-Extra-Fields vorname/nachname/email/telefonnummer
     * aus dem primaeren CRM-Kontakt des Bewerbers — aber nur dort, wo das
     * jeweilige Extra-Field aktuell leer ist. Der Kandidat sieht die Werte
     * im Portal vorbefuellt und kann sie ueberschreiben; sein Wert gewinnt
     * spaeter in der Fallback-Kette von CreateEmployeeFromApplicantService
     * (extra_field ?? contact).
     */
    private function prefillContactFields(RecApplicant $applicant): void
    {
        $applicant->loadMissing([
            'crmContactLinks.contact.emailAddresses',
            'crmContactLinks.contact.phoneNumbers',
        ]);

        $contact = $applicant->crmContactLinks->first()?->contact;
        if (!$contact) {
            return;
        }

        $phone = $contact->phoneNumbers->first(fn ($p) => $p->is_active)?->international
            ?: $contact->phoneNumbers->first(fn ($p) => $p->is_active)?->raw_input
            ?: $contact->phoneNumbers->first()?->international
            ?: $contact->phoneNumbers->first()?->raw_input;

        $candidates = [
            'vorname' => $contact->first_name,
            'nachname' => $contact->last_name,
            'email' => $contact->emailAddresses->first()?->email_address,
            'telefonnummer' => $phone !== null ? ['raw' => $phone, 'country' => 'DE'] : null,
        ];

        foreach ($candidates as $name => $value) {
            if ($value === null || $value === '') {
                continue;
            }
            $current = $applicant->getExtraField($name);
            if ($current === null || $current === '') {
                $applicant->setExtraField($name, $value);
            }
        }
    }

    public function parkApplicant(int $applicantId): void
    {
        $applicant = RecApplicant::query()
            ->forTeam((int) Auth::user()->currentTeam->id)
            ->find($applicantId);
        if (!$applicant) {
            return;
        }

        $applicant->update([
            'is_parked' => true,
            'parked_at' => now(),
            'auto_pilot' => false,
        ]);

        session()->flash('message', 'Bewerber geparkt.');

        unset($this->applicantsByPosition, $this->positions);
        $this->dispatch('sidebar-refresh');
    }

    public function unparkApplicant(int $applicantId): void
    {
        $applicant = RecApplicant::query()
            ->forTeam((int) Auth::user()->currentTeam->id)
            ->find($applicantId);
        if (!$applicant) {
            return;
        }

        $applicant->update([
            'is_parked' => false,
            'parked_at' => null,
        ]);

        session()->flash('message', 'Bewerber reaktiviert.');

        unset($this->applicantsByPosition, $this->positions);
        $this->dispatch('sidebar-refresh');
    }

    // ────────────────────────────────────────────────────────────────
    // Manuelle MA-Anlage mit Vertragsauswahl (statt Auto-Anlage bei
    // Datenerfassung-Abschluss). HR waehlt einen Arbeitsvertrag, EIN Klick:
    //  - Template am Bewerber zuweisen (+ optional Zuschlag)
    //  - personalisierten Vertrag (lean, ohne Zuschlag-Zwang) anlegen
    //  - MA anlegen (idempotent)
    //  - MA-Portal-Login-Link zum Teilen anzeigen
    // Bewusst KEIN SendContractsService (der erzwingt Zuschlag) und KEIN
    // WhatsApp-Versand — nur der Link wird angezeigt.
    // ────────────────────────────────────────────────────────────────

    /**
     * Aktive Vertrags-Vorlagen des aktuellen Teams fuer die Auswahl im Modal.
     */
    #[Computed]
    public function availableContractTemplates()
    {
        return RecContractTemplate::forTeam((int) Auth::user()->currentTeam->id)
            ->active()
            ->whereNull('deleted_at')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();
    }

    public function openCreateEmployee(int $applicantId): void
    {
        $this->maApplicantId = $applicantId;
        $this->maContractTemplateId = null;
        $this->maZuschlag = null;
        $this->resetErrorBag();
    }

    public function closeCreateEmployee(): void
    {
        $this->maApplicantId = null;
        $this->maContractTemplateId = null;
        $this->maZuschlag = null;
        $this->resetErrorBag();
    }

    public function dismissCreatedEmployeeLink(): void
    {
        $this->createdEmployeePortalLink = null;
    }

    public function createEmployeeWithContract(): void
    {
        if (!$this->maApplicantId) {
            return;
        }

        $teamId = (int) Auth::user()->currentTeam->id;

        $applicant = RecApplicant::query()
            ->forTeam($teamId)
            ->with(['phase', 'postings.position'])
            ->find($this->maApplicantId);

        // Guards: existiert, aktiv, Direkteinstellung, Datenerfassung komplett.
        $position = $applicant?->postings->first()?->position;
        if (!$applicant || !$applicant->is_active || !$position || !$position->is_direct_hire) {
            $this->addError('maContractTemplateId', 'Bewerber nicht gefunden oder keine Direkteinstellung.');
            return;
        }

        if (!$applicant->isPhaseComplete()) {
            $this->addError('maContractTemplateId', 'Die Datenerfassung ist noch nicht vollständig — bitte zuerst abschließen.');
            return;
        }

        $this->validate([
            'maContractTemplateId' => 'required|integer',
            'maZuschlag' => 'nullable|numeric',
        ]);

        $template = RecContractTemplate::forTeam($teamId)
            ->active()
            ->whereNull('deleted_at')
            ->find((int) $this->maContractTemplateId);
        if (!$template) {
            $this->addError('maContractTemplateId', 'Vertrags-Vorlage nicht gefunden oder inaktiv.');
            return;
        }

        $employee = DB::transaction(function () use ($applicant, $template, $teamId) {
            // 1) Template (+ optional Zuschlag) am Bewerber setzen.
            $applicant->contract_template_id = $template->id;
            if ($this->maZuschlag !== null && $this->maZuschlag !== '') {
                $applicant->zuschlag = $this->maZuschlag;
            }
            $applicant->save();

            // 2) Personalisierten Vertrag anlegen — lean, OHNE Zuschlag-Zwang.
            //    status='sent' + sent_at gesetzt = signierbar (MA-Portal listet
            //    Vertraege ueber employee->applicant->contracts; Signatur-Flow
            //    erwartet einen gesendeten Vertrag — analog SendContractsService).
            //    Guard: nur anlegen wenn noch nicht gesendet.
            if (!$applicant->hasAnyContractSent()) {
                RecContract::create([
                    'rec_applicant_id'         => $applicant->id,
                    'rec_contract_template_id' => $template->id,
                    'team_id'                  => $teamId,
                    'personalized_content'     => $template->personalizeContent($applicant),
                    'status'                   => 'sent',
                    'sent_at'                  => now(),
                    'created_by_user_id'       => Auth::id(),
                ]);
            }

            // 3) Mitarbeiter anlegen (idempotent). Vertrag existiert bereits am
            //    Bewerber → surfaced via employee->applicant->contracts im Portal.
            return app(CreateEmployeeFromApplicantService::class)
                ->createOrUpdate($applicant, Auth::id());
        });

        // 4) MA-Portal-Login-Link zum Teilen.
        $this->createdEmployeePortalLink = route('recruiting.public.employee-portal', ['token' => $employee->portal_token]);

        session()->flash('message', 'Mitarbeiter angelegt. Schicke ihm den Login-Link zum MA-Portal — dort signiert er den Vertrag.');

        $this->closeCreateEmployee();

        unset($this->applicantsByPosition, $this->positions);
        $this->dispatch('sidebar-refresh');
    }

    public function render()
    {
        return view('recruiting::livewire.direct-hire.index')
            ->layout('platform::layouts.app');
    }
}
