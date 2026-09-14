<?php

namespace Platform\Recruiting\Livewire\InterviewBookings;

use Livewire\Component;
use Livewire\Attributes\Computed;
use Illuminate\Support\Facades\DB;
use Platform\Crm\Models\CommsChannel;
use Platform\Crm\Models\CrmPhoneNumber;
use Platform\Crm\Services\Comms\WhatsAppMetaService;
use Platform\Recruiting\Models\RecApplicant;
use Platform\Recruiting\Models\RecAutoPilotLog;
use Platform\Recruiting\Models\RecContract;
use Platform\Recruiting\Models\RecContractTemplate;
use Platform\Recruiting\Models\RecEmployee;
use Platform\Recruiting\Models\RecHrDeskCase;
use Platform\Recruiting\Models\RecInterview;
use Platform\Recruiting\Models\RecInterviewBooking;
use Platform\Recruiting\Services\ContractDispatchService;
use Platform\Recruiting\Services\ContractProposalService;
use Platform\Recruiting\Services\SendContractsService;
use Platform\Recruiting\Support\ManualBookingCandidates;
use Platform\Core\Models\CoreLookup;

class Index extends Component
{
    use \Platform\Recruiting\Livewire\Concerns\HandlesEvaluationModal;
    use \Platform\Recruiting\Livewire\Concerns\LoadsApplicantSelfies;

    public $interviewId;
    public $search = '';
    public $filterStatus = 'all';

    public $showBookModal = false;
    public $selectedApplicantId = '';
    public $bookingNotes = '';

    /** Modes: 'overview' (default) | 'nachbereitung' (post-Schulung HR/SL flow) */
    public string $mode = 'overview';

    /**
     * Pro-Bewerber Vertragslaufzeit-Eingaben im Nachbereitungs-Modus.
     * Shape: [applicantId => ['vertragsbeginn' => 'YYYY-MM-DD', 'vertragsende' => 'YYYY-MM-DD']]
     * Werden bei sendContractsBulk() an SendContractsService übergeben und auf
     * die neu erstellten AV+IFSG-Verträge als Extra-Fields geschrieben.
     */
    public array $contractDates = [];


    public function mount(int $interview)
    {
        $this->interviewId = $interview;
        $this->hydrateContractDatesFromExistingContracts();
    }

    /**
     * Liest aus bestehenden AV-Vertraegen die vertragsbeginn/-ende-extra_fields
     * und befuellt damit das contractDates-Array. So zeigt das UI nach
     * Vertragsversand und nach Refresh die korrekten Daten an statt leere
     * Felder (= reiner Display-Bug, die Werte sind im Vertrag persistent).
     *
     * Idempotent, ueberschreibt schon-gesetzte Werte nicht (User-Input
     * vor Send hat Vorrang).
     */
    protected function hydrateContractDatesFromExistingContracts(): void
    {
        $bookings = RecInterviewBooking::where('rec_interview_id', $this->interviewId)
            ->whereNotIn('status', ['cancelled'])
            ->with([
                'applicant.contracts' => function ($q) {
                    $q->whereNotIn('status', ['cancelled'])
                        ->with('contractTemplate', 'extraFieldValues.definition');
                },
            ])
            ->get();

        foreach ($bookings as $booking) {
            $applicantId = $booking->applicant?->id;
            if (!$applicantId) continue;

            $avContract = $booking->applicant->contracts
                ->filter(fn ($c) => $c->contractTemplate && str_starts_with($c->contractTemplate->code ?? '', 'AV'))
                ->sortByDesc('id')
                ->first();
            if (!$avContract) continue;

            $beginn = $avContract->getExtraField('vertragsbeginn');
            $ende   = $avContract->getExtraField('vertragsende');
            if (!$beginn && !$ende) continue;

            $current = $this->contractDates[$applicantId] ?? ['vertragsbeginn' => null, 'vertragsende' => null];
            if (empty($current['vertragsbeginn']) && $beginn) {
                $current['vertragsbeginn'] = $beginn;
            }
            if (empty($current['vertragsende']) && $ende) {
                $current['vertragsende'] = $ende;
            }
            $this->contractDates[$applicantId] = $current;
        }
    }

    public function render()
    {
        return view('recruiting::livewire.interview-bookings.index')
            ->layout('platform::layouts.app');
    }

    #[Computed]
    public function interview()
    {
        return RecInterview::with(['interviewType', 'position', 'interviewers'])
            ->findOrFail($this->interviewId);
    }

    #[Computed]
    public function bookings()
    {
        $query = RecInterviewBooking::where('rec_interview_id', $this->interviewId)
            ->when($this->search, function ($q) {
                $q->whereHas('applicant.crmContactLinks.contact', function ($query) {
                    $query->where('first_name', 'like', '%' . $this->search . '%')
                        ->orWhere('last_name', 'like', '%' . $this->search . '%');
                });
            })
            ->with([
                'applicant.crmContactLinks.contact',
                'applicant.legalStatus',
                'applicant.postings.position',
                'applicant.contractTemplate',
                'applicant.contracts:id,rec_applicant_id,rec_contract_template_id,status,sent_at',
                'applicant.employee:id,rec_applicant_id',
                'applicant.employee.hrData',
            ])
            ->orderBy('booked_at', 'desc');

        // Filter-Logik:
        //  - 'cancelled' = echte Stornierung (keine spaetere aktive Buchung beim Bewerber)
        //  - 'rebooked'  = umgebucht (cancelled + spaetere aktive Buchung)
        //  - sonst       = direkter status-Match
        if ($this->filterStatus === 'cancelled') {
            $query->where('status', 'cancelled')
                ->whereNotExists(function ($sub) {
                    $sub->select(\Illuminate\Support\Facades\DB::raw(1))
                        ->from('rec_interview_bookings as later')
                        ->whereColumn('later.rec_applicant_id', 'rec_interview_bookings.rec_applicant_id')
                        ->whereColumn('later.id', '>', 'rec_interview_bookings.id')
                        ->whereNotIn('later.status', ['cancelled']);
                });
        } elseif ($this->filterStatus === 'rebooked') {
            $query->where('status', 'cancelled')
                ->whereExists(function ($sub) {
                    $sub->select(\Illuminate\Support\Facades\DB::raw(1))
                        ->from('rec_interview_bookings as later')
                        ->whereColumn('later.rec_applicant_id', 'rec_interview_bookings.rec_applicant_id')
                        ->whereColumn('later.id', '>', 'rec_interview_bookings.id')
                        ->whereNotIn('later.status', ['cancelled']);
                });
        } elseif ($this->filterStatus !== 'all') {
            $query->where('status', $this->filterStatus);
        }

        return $query->get();
    }

    /**
     * Nachbereitungs-Modus: A-Z nach dem angezeigten Namen (Spec §3).
     *
     * Sortiert wird die geladene Collection, nicht per Join: die Liste
     * paginiert nicht (Spec F11), und ein Join ueber crm_contact_links wuerde
     * bei mehrfach verlinkten Bewerbern Zeilen vervielfachen.
     *
     * ACHTUNG-Kopplung: wird die Liste spaeter paginiert, sortiert das hier nur
     * die aktuelle Seite. Dann muss auf DB-Sortierung mit expliziter
     * Link-Priorisierung umgestellt werden.
     *
     * BEWUSST eine normale Methode und KEINE #[Computed]-Property: sie liest
     * $this->bookings (bereits gecacht, also keine zusaetzliche Query, nur eine
     * Sortierung pro Aufruf). Eine gecachte Sortierung muesste in
     * saveEvaluation() mit-invalidiert werden — dort wird $this->bookings
     * verworfen —, und wer das vergisst, bekommt nach dem Speichern veraltete
     * Werte in der Tabelle. Als Methode kann sie per Konstruktion nicht
     * veralten. Nicht "optimieren".
     */
    public function bookingsSortedByName()
    {
        return $this->bookings
            ->sortBy(fn ($booking) => \Platform\Recruiting\Support\ApplicantContactName::sortKey(
                $this->contactCandidatesFor($booking->applicant),
            ), SORT_STRING)
            ->values();
    }

    /**
     * Offene Nicht-EU-Fälle der sichtbaren Bewerber — EIN Batch-Query,
     * keyed by applicant_id (Blade: Lock-Badge "Liegt beim HR-Schreibtisch").
     *
     * BEWUSSTE GRENZE: Die Nachbereitung pollt nicht (kein wire:poll/Token —
     * anders als das Dashboard); wird attended EXTERN gesetzt (MCP-Tool,
     * andere Session), erscheint der Badge erst bei der naechsten
     * Interaktion/Reload — view-weite, vorbestehende Eigenschaft dieser
     * Ansicht, gilt fuer alle externen Aenderungen. Das Dashboard-Poll-Token
     * ist abgedeckt: Routing bumpt rec_applicants.updated_at [Flags], eine
     * Token-Quelle.
     */
    #[Computed]
    public function openNonEuCaseApplicantIds(): array
    {
        $ids = $this->bookings->pluck('applicant.id')->filter()->values();
        if ($ids->isEmpty()) {
            return [];
        }

        // Welche offenen Faelle blocken, sagt EINE Liste am Model
        // (CONTRACT_BLOCKING_REASONS): Non-EU, Jugendschutz und seit 07.09.2026
        // die Klaerung aus der Schulung. Der Name dieser Computed ist damit
        // historisch zu eng — er bleibt, weil Blade und fuenf Cache-Busts an
        // ihm haengen; der Badge-Text der View sagt neutral „Liegt beim
        // HR-Schreibtisch".
        return RecHrDeskCase::query()
            ->open()
            ->whereIn('reason', RecHrDeskCase::CONTRACT_BLOCKING_REASONS)
            ->whereIn('rec_applicant_id', $ids)
            ->pluck('rec_applicant_id')
            ->flip()
            ->all();
    }

    /**
     * Kandidaten für die manuelle Buchung. Die Regel selbst steht in
     * ManualBookingCandidates — dort ist sie ohne Livewire-Runtime gegen eine
     * echte DB testbar (tests/Integration/ManualBookingCandidatesTest).
     *
     * Bis 08/2026 stand hier ein Filter auf auto_pilot_completed_at. Das war
     * die alte 2-Phasen-Logik (Phase 2 = letzte Phase = fertig für die
     * Schulung); bei den heutigen 4-Phasen-Stellen bedeutet derselbe Wert
     * "Verträge sind raus" — also genau die Bewerber, die man NICHT mehr
     * einbuchen will. Jetzt entscheidet der Phasen-Schalter
     * allow_manual_booking.
     */
    #[Computed]
    public function availableApplicants()
    {
        return ManualBookingCandidates::query(
            auth()->user()->currentTeam->id,
            // ?: statt ?? — der alte Filter hing an einer Truthiness-Prüfung,
            // eine 0 in rec_position_id darf also weiterhin "keine Stelle"
            // heißen und nicht "Stelle 0" (die es nie gibt → leere Liste).
            $this->interview->rec_position_id ?: null,
        )
            ->with(['crmContactLinks.contact'])
            ->get();
    }

    public function openBookModal(): void
    {
        $this->selectedApplicantId = '';
        $this->bookingNotes = '';
        $this->showBookModal = true;
    }

    public function book(): void
    {
        $this->validate([
            'selectedApplicantId' => 'required|integer|exists:rec_applicants,id',
            'bookingNotes' => 'nullable|string',
        ]);

        // Status 'booked': konsistent zum Public-Form-Pfad. HR bucht hier
        // manuell einen Kandidaten in eine Schulung — gleiche Initial-Semantik
        // wie wenn der Bewerber sich selbst gebucht haette.
        //
        // Zeilensperre auf dem Termin serialisiert ALLE Buchungs-Erzeugungen
        // gegeneinander und gegen den Standby-Re-Claim (Phantom-Insert-sicher —
        // Row-Locks auf Buchungszeilen wuerden neue Inserts nicht stoppen).
        $error = DB::transaction(function () {
            $locked = RecInterview::query()->lockForUpdate()->find($this->interviewId);
            if (!$locked) {
                return 'Termin nicht gefunden.';
            }

            if (!$locked->hasFreeSeat()) {
                return 'Maximale Teilnehmerzahl erreicht!';
            }

            $existing = RecInterviewBooking::where('rec_applicant_id', $this->selectedApplicantId)
                ->whereNotIn('status', ['cancelled'])
                ->exists();

            if ($existing) {
                return 'Dieser Kandidat ist bereits in einem Termin gebucht!';
            }

            // Dieselbe Regel wie die Kandidatenliste, hier noch einmal im Lock
            // geprueft. Die Validierung oben kennt nur exists:rec_applicants —
            // ohne diesen Riegel bucht ein offen gebliebenes Modal auch dann,
            // wenn der Kollege in der Zwischenzeit die Vertraege versendet oder
            // den Bewerber geparkt hat; und eine manipulierte Livewire-Payload
            // koennte einen Bewerber aus einem FREMDEN Team hereinreichen, der
            // dann mit unserer team_id gestempelt wuerde.
            $eligible = ManualBookingCandidates::query(
                auth()->user()->currentTeam->id,
                $locked->rec_position_id ?: null,
            )->whereKey($this->selectedApplicantId)->exists();

            if (!$eligible) {
                return 'Dieser Bewerber ist nicht (mehr) manuell buchbar — bitte die Liste neu laden.';
            }

            RecInterviewBooking::updateOrCreate(
                [
                    'rec_interview_id' => $this->interviewId,
                    'rec_applicant_id' => $this->selectedApplicantId,
                ],
                [
                    'status'             => 'booked',
                    'notes'              => $this->bookingNotes ?: null,
                    'booked_at'          => now(),
                    'team_id'            => auth()->user()->currentTeam->id,
                    'created_by_user_id' => auth()->id(),
                    'cancelled_by'       => null,
                    'cancelled_at'       => null,
                    'seat_released_at'   => null,
                ],
            );

            return null;
        });

        if ($error !== null) {
            session()->flash('error', $error);
            return;
        }

        session()->flash('success', 'Kandidat erfolgreich gebucht!');
        $this->showBookModal = false;
        $this->selectedApplicantId = '';
        $this->bookingNotes = '';
    }

    public function updateNotes(int $bookingId, ?string $notes): void
    {
        $booking = RecInterviewBooking::findOrFail($bookingId);
        $booking->update(['notes' => $notes ?: null]);
    }

    /** Klaerung an HR: Modal-Zustand (Buchung + Pflicht-Notiz). */
    public bool $showClarifyModal = false;
    public ?int $clarifyBookingId = null;
    public string $clarifyNotes = '';

    /** Storno nach Terminende: Modal-Zustand (Buchung + Pflicht-Begruendung). */
    public bool $showLateCancelModal = false;
    public ?int $lateCancelBookingId = null;
    public string $lateCancelReason = '';

    public function openClarifyModal(int $bookingId): void
    {
        $this->clarifyBookingId = $bookingId;
        $this->clarifyNotes = '';
        $this->showClarifyModal = true;
    }

    /**
     * Schulungsleiter-Markierung (Kundenwunsch 01.09.2026): legt einen
     * HR-Schreibtisch-Fall „Klaerung aus der Schulung" mit der Notiz an.
     * Solange er offen ist, blockt er den Vertragsversand fuer diese Person
     * (CONTRACT_BLOCKING_REASONS) — genau der Zweck der Markierung.
     */
    public function submitClarification(): void
    {
        $this->validate(
            ['clarifyNotes' => 'required|string|min:3'],
            ['clarifyNotes.required' => 'Bitte kurz notieren, was HR klären soll.',
             'clarifyNotes.min' => 'Bitte kurz notieren, was HR klären soll.'],
        );

        $booking = RecInterviewBooking::with('applicant')->findOrFail((int) $this->clarifyBookingId);
        if (!$booking->applicant) {
            return;
        }

        // Idempotent: ein zweiter Haken auf dieselbe Person legt keinen
        // Doppel-Fall an (routeIfNotAlreadyOpen prueft reason + open).
        app(\Platform\Recruiting\Services\HrDeskRoutingService::class)->routeIfNotAlreadyOpen(
            $booking->applicant,
            RecHrDeskCase::REASON_TRAINING_CLARIFICATION,
            auth()->id(),
            $this->clarifyNotes,
        );

        $this->showClarifyModal = false;
        $this->clarifyBookingId = null;
        $this->clarifyNotes = '';
        unset($this->bookings, $this->openNonEuCaseApplicantIds);
        session()->flash('message', 'Zur Klärung an den HR-Schreibtisch übergeben.');
    }

    /**
     * Storno nach Terminende — nur mit Begruendung (BookingAftercare):
     * updateStatus oeffnet stattdessen dieses Modal. Fuer „war nicht da"
     * sind „Nicht erschienen"/„Vor Ort aussortiert" die richtigen Status;
     * dieser Weg ist fuer Testbuchungen und Fehlbuchungen da.
     */
    public function submitLateCancel(): void
    {
        $this->validate(
            ['lateCancelReason' => 'required|string|min:3'],
            ['lateCancelReason.required' => 'Bitte begründen — für Nichterscheinen bitte den passenden Status wählen.',
             'lateCancelReason.min' => 'Bitte begründen — für Nichterscheinen bitte den passenden Status wählen.'],
        );

        $booking = RecInterviewBooking::findOrFail((int) $this->lateCancelBookingId);
        $updates = ['status' => 'cancelled']
            + \Platform\Recruiting\Support\BookingCancellationMeta::updatesFor($booking->status, 'cancelled', (string) now());
        $updates['notes'] = trim(($booking->notes ? $booking->notes . "\n" : '')
            . 'Storniert nach Terminende: ' . $this->lateCancelReason);
        $booking->update($updates);

        $this->showLateCancelModal = false;
        $this->lateCancelBookingId = null;
        $this->lateCancelReason = '';
        unset($this->bookings, $this->openNonEuCaseApplicantIds);
    }

    public function updateStatus(int $bookingId, string $status): void
    {
        $validStatuses = ['booked', 'registered', 'confirmed', 'attended', 'cancelled', 'no_show', 'rejected_on_site'];
        if (!in_array($status, $validStatuses)) {
            return;
        }

        $booking = RecInterviewBooking::findOrFail($bookingId);

        // Storno-Bremse: nach Terminende kein beilaeufiges „Abgesagt" mehr —
        // stattdessen oeffnet sich das Begruendungs-Modal (siehe
        // submitLateCancel). Der Wechsel WEG von cancelled bleibt frei.
        $terminVorbei = ($this->interview->ends_at ?? $this->interview->starts_at)?->isPast() ?? false;
        if ($status === 'cancelled' && $booking->status !== 'cancelled'
            && !\Platform\Recruiting\Support\BookingAftercare::allowsPlainCancellation($terminVorbei)) {
            $this->lateCancelBookingId = $bookingId;
            $this->lateCancelReason = '';
            $this->showLateCancelModal = true;

            return;
        }

        // Storno-Metadaten (cancelled_by='hr', damit der HR-Schreibtisch
        // zwischen "Bewerber hat selbst abgesagt" und "HR hat abgesagt"
        // unterscheiden kann): EINE Regel mit dem MCP-Tool, siehe
        // BookingCancellationMeta — die Zweige standen hier und dort wortgleich.
        $updates = ['status' => $status]
            + \Platform\Recruiting\Support\BookingCancellationMeta::updatesFor($booking->status, $status, (string) now());

        // Standby-Buchung wird manuell hochgestuft = bewusste HR-Uebersteuerung
        // (kein Kapazitaetsblock, aber nachvollziehbar im AutoPilot-Log).
        if ($booking->is_standby && !in_array($status, ['booked', 'cancelled'], true)) {
            try {
                RecAutoPilotLog::create([
                    'rec_applicant_id' => $booking->rec_applicant_id,
                    'type' => 'seat_reclaimed_override',
                    'summary' => "Standby-Buchung #{$booking->id} manuell auf '{$status}' gesetzt — Platz bewusst konsumiert (HR).",
                    'details' => ['booking_id' => $booking->id, 'interview_id' => $booking->rec_interview_id, 'status' => $status],
                ]);
            } catch (\Throwable) {}
        }

        $booking->update($updates);

        // Ab Status "Teilgenommen" wird die Standard-Vertragsvorlage (AV-default)
        // automatisch zugewiesen — HR wählt nichts mehr aus.
        if ($status === 'attended') {
            $this->assignDefaultTemplateIfMissing($booking->fresh('applicant')?->applicant);
        }

        // Computed-Cache busten, damit Anzeige (Vorlage/Status) den frischen Stand zeigt.
        unset($this->bookings, $this->openNonEuCaseApplicantIds);

        session()->flash('success', 'Status aktualisiert!');
    }

    public function deleteBooking(int $bookingId): void
    {
        $booking = RecInterviewBooking::findOrFail($bookingId);
        $booking->delete();
        session()->flash('success', 'Buchung erfolgreich gelöscht!');
    }

    public function setApplicantContractTemplate(int $bookingId, $templateId): void
    {
        $booking = RecInterviewBooking::with('applicant.legalStatus')->findOrFail($bookingId);
        if (!$booking->applicant) {
            return;
        }

        // Server-seitiger Block (Schritt 5/6 Doppel-Schutz): bei ungepruefte
        // Nicht-EU darf keine Vertragsvorlage gesetzt werden — UI-Disable ist
        // primaerer Schutz, dieser Check faengt manipulierte POSTs ab.
        if ($this->isLegalStatusUnchecked($booking->applicant)) {
            session()->flash('error', 'Rechtsstatus offen — Vertragsvorlage kann erst nach HR-Schreibtisch-Pruefung zugewiesen werden.');
            return;
        }

        $tplId = is_numeric($templateId) && (int) $templateId > 0 ? (int) $templateId : null;

        if ($tplId !== null) {
            $tpl = RecContractTemplate::where('team_id', $booking->applicant->team_id)
                ->where('id', $tplId)
                ->where('is_active', true)
                ->first();
            if (!$tpl) {
                return;
            }
        }

        $booking->applicant->contract_template_id = $tplId;
        $booking->applicant->save();
    }

    /**
     * Setzt den Zuschlag (€/Std) für einen Bewerber. Akzeptiert deutsches
     * (0,60) oder Punkt-Dezimal (0.60). Leere Eingabe → null.
     */
    public function setApplicantZuschlag(int $bookingId, $value): void
    {
        $booking = RecInterviewBooking::with('applicant')->findOrFail($bookingId);
        if (!$booking->applicant) {
            return;
        }

        $raw = trim((string) $value);
        if ($raw === '') {
            $booking->applicant->zuschlag = null;
            $booking->applicant->save();
            return;
        }

        // Strikte Validierung: nur Ziffern + optional Komma/Punkt mit max 2
        // Nachkommastellen (Spalte ist DECIMAL(5,2) → max 999,99). Keine
        // wissenschaftliche Notation (is_numeric("1e2") wäre true → 100),
        // keine Tausenderzeichen, kein Vorzeichen.
        if (!preg_match('/^\d{1,3}([.,]\d{1,2})?$/', $raw)) {
            session()->flash('error', 'Zuschlag muss eine Zahl sein (z.B. 0,60).');
            return;
        }

        $num = round((float) str_replace(',', '.', $raw), 2);

        $booking->applicant->zuschlag = $num;
        $booking->applicant->save();
    }

    /**
     * Lohn-/Laufzeit-VORSCHLAG des Schulungsleiters (Kundenwunsch 14.09.2026).
     *
     * Greift genau dort, wo setApplicantZuschlag und setContractDate gesperrt
     * sind: Liegt die Person am HR-Schreibtisch, darf der Schulungsleiter den
     * scharfen Wert nicht mehr anfassen — aber er war gerade mit ihr im
     * Gespraech und weiss, was sie verdienen soll und ob sie nur befristet
     * arbeiten will. Er legt eine Empfehlung daneben; HR uebernimmt sie per
     * Klick oder tippt etwas anderes.
     *
     * Ein Vorschlag macht KEINEN Versand moeglich — ContractSendEligibility
     * zaehlt weiterhin nur den scharfen Zuschlag.
     *
     * Die drei Felder kommen einzeln per wire:change herein; propose() will
     * aber alle drei. Die jeweils anderen werden deshalb aus dem Bestand
     * uebernommen.
     */
    public function setProposal(int $bookingId, string $field, $value): void
    {
        if (!in_array($field, ['zuschlag', 'vertragsbeginn', 'vertragsende'], true)) {
            return;
        }

        $booking = RecInterviewBooking::with('applicant')->findOrFail($bookingId);
        $applicant = $booking->applicant;
        if (!$applicant) {
            return;
        }

        // Nur anbieten, wo auch gesperrt ist — sonst gehoert der Wert direkt
        // ins scharfe Feld und nicht in einen Vorschlag.
        if (!isset($this->openNonEuCaseApplicantIds[$applicant->id])) {
            return;
        }

        $raw = trim((string) $value);

        if ($field === 'zuschlag' && $raw !== '' && !preg_match('/^\d{1,3}([.,]\d{1,2})?$/', $raw)) {
            session()->flash('error', 'Empfehlung muss eine Zahl sein (z.B. 0,60).');
            return;
        }

        $werte = [
            'zuschlag' => $applicant->zuschlag_vorschlag !== null
                ? (string) $applicant->zuschlag_vorschlag
                : null,
            'vertragsbeginn' => $applicant->vertragsbeginn_vorschlag,
            'vertragsende'   => $applicant->vertragsende_vorschlag,
        ];
        $werte[$field] = $raw;

        ContractProposalService::propose(
            $applicant,
            zuschlag: $werte['zuschlag'],
            vertragsbeginn: $werte['vertragsbeginn'],
            vertragsende: $werte['vertragsende'],
            userId: auth()->id(),
            now: now(),
        );
        $applicant->save();

        unset($this->bookings);
    }

    /**
     * Setzt Vertragsbeginn oder -ende für einen Bewerber. Wenn `vertragsbeginn`
     * gesetzt wird und `vertragsende` leer ist, wird das Ende live mit der
     * Auto-Calc-Logik vorbelegt (+1y, Anfang Monat, −1d).
     */
    public function setContractDate(int $applicantId, string $field, ?string $value): void
    {
        if (!in_array($field, ['vertragsbeginn', 'vertragsende'], true)) {
            return;
        }

        $value = $value !== '' ? $value : null;
        $current = $this->contractDates[$applicantId] ?? ['vertragsbeginn' => null, 'vertragsende' => null];
        $current[$field] = $value;

        if ($field === 'vertragsbeginn' && $value && empty($current['vertragsende'])) {
            $resolved = RecContract::resolveContractDates($value, null);
            $current['vertragsende'] = $resolved['vertragsende'];
        }

        $this->contractDates[$applicantId] = $current;
    }

    public function sendContractsBulk(): void
    {
        // Eligible = anwesend + Template zugewiesen + noch KEIN Vertrag versendet.
        // Schon-versendete (hasAnyContractSent) werden geskippt — verhindert
        // dass leere Vertragsbeginn-Felder den ganzen Run blockieren UND dass
        // bestehende Vertragsdaten ungewollt ueberschrieben werden. Service-
        // Layer hat zusaetzlich Idempotenz-Schutz fuer Notification-Versand.
        //
        // ZUSAETZLICH (Block B): Nicht-EU-Bewerber muessen rechtsstatus-
        // gepruefte sein. Ungepruefte werden vom Bulk-Send ausgenommen damit
        // HR sie zuerst auf dem HR-Schreibtisch durchgehen muss.

        // AV-default ist die Pflicht-Quelle. Fehlt sie (inaktiv/nicht angelegt),
        // kann nichts versendet werden.
        $default = $this->defaultContractTemplate;
        if (!$default) {
            session()->flash('error', 'AV-default-Vorlage fehlt oder ist inaktiv — bitte zuerst aktivieren.');
            return;
        }

        // Defensiv: anwesenden Bewerbern ohne Vorlage den Default zuweisen
        // (falls sie vor diesem Feature schon auf "Teilgenommen" standen).
        foreach ($this->bookings as $b) {
            if ($b->status === 'attended') {
                $this->assignDefaultTemplateIfMissing($b->applicant);
            }
        }
        unset($this->bookings, $this->openNonEuCaseApplicantIds);

        // Verteidigung in beide Richtungen: ungeprueft ODER offener Nicht-EU-
        // Fall blockt. Ein offener Fall heisst bewusst "liegt bei HR" — auch
        // wenn HR den Pruefen-Toggle schon gesetzt, den Fall aber noch nicht
        // geschlossen/gesendet hat, darf der Schulungsleiter nicht parallel
        // per Bulk-Versand senden.
        $blockedByLegalStatus = collect();
        $eligible = $this->bookings->filter(function ($b) use (&$blockedByLegalStatus) {
            if ($b->status !== 'attended') return false;
            if (!$b->applicant?->contract_template_id) return false;
            if ($b->applicant->hasAnyContractSent()) return false;
            if ($this->isLegalStatusUnchecked($b->applicant)
                || isset($this->openNonEuCaseApplicantIds[$b->applicant->id])) {
                $blockedByLegalStatus->push($b);
                return false;
            }
            return true;
        });

        if ($eligible->isEmpty()) {
            $msg = 'Keine anwesenden Bewerber mit Vertragsvorlage und noch nicht versendet.';
            if ($blockedByLegalStatus->isNotEmpty()) {
                $msg .= sprintf(
                    ' (%d Bewerber wegen offener Rechtsstatus-Pruefung uebersprungen — bitte zuerst auf HR-Schreibtisch pruefen.)',
                    $blockedByLegalStatus->count(),
                );
            }
            session()->flash('error', $msg);
            return;
        }

        // Vertragsbeginn ist Pflicht — verhindern dass jemand ohne Datum versendet
        $missingBeginn = $eligible->filter(function ($b) {
            $applicantId = $b->applicant->id;
            return empty($this->contractDates[$applicantId]['vertragsbeginn'] ?? null);
        });
        if ($missingBeginn->isNotEmpty()) {
            session()->flash('error', 'Bei mind. einem zu versendenden Bewerber fehlt der Vertragsbeginn.');
            return;
        }

        // Zuschlag ist Pflicht (universeller Cut) — verhindern dass jemand ohne Zuschlag versendet.
        $missingZuschlag = $eligible->filter(fn ($b) => $b->applicant->zuschlag === null);
        if ($missingZuschlag->isNotEmpty()) {
            session()->flash('error', 'Bei mind. einem zu versendenden Bewerber fehlt der Zuschlag.');
            return;
        }

        $service = app(SendContractsService::class);
        $sent = 0;
        $errors = 0;

        foreach ($eligible as $booking) {
            try {
                $applicantId = $booking->applicant->id;
                $fields = $this->contractDates[$applicantId] ?? null;
                $service->send($booking->applicant, auth()->id(), $fields);
                $sent++;
            } catch (\Throwable $e) {
                $errors++;
            }
        }

        unset($this->bookings, $this->openNonEuCaseApplicantIds);
        $this->hydrateContractDatesFromExistingContracts();

        if ($errors === 0) {
            $msg = "Verträge versendet für {$sent} Bewerber.";
            if ($blockedByLegalStatus->isNotEmpty()) {
                $msg .= sprintf(
                    ' %d Bewerber wegen offener Rechtsstatus-Pruefung uebersprungen — bitte auf HR-Schreibtisch pruefen.',
                    $blockedByLegalStatus->count(),
                );
            }
            session()->flash('success', $msg);
        } else {
            session()->flash('error', "Versendet: {$sent}, Fehler: {$errors}. Details siehe Logs.");
        }
    }

    /**
     * Kombinierter Versand: Verträge + Portal-Link in einem Schritt.
     * Identische Eligibility-Logik wie sendContractsBulk() — anwesend +
     * Vertragsvorlage + noch nicht versendet + Rechtsstatus-OK +
     * Vertragsbeginn gesetzt. Pro Booking: erst SendContractsService
     * (legt MA an via creates_employee_on_completion-Hook), danach
     * RecEmployee::sendPortalNotification() fuer die MA-Portal-WA.
     *
     * Aktuell als "NICHT NUTZEN"-Variante in der UI markiert — finaler
     * Workflow soll diesen Button zum Default-Button machen und den
     * reinen "Vertraege versenden" abloesen. WA-Template-Konsolidierung
     * (Portal-WA statt doppelter Vertrag-WA + Portal-WA) als eigene
     * Iteration.
     */
    public function sendPortalLinkBulk(): void
    {
        // Verteidigung in beide Richtungen: ungeprueft ODER offener Nicht-EU-
        // Fall blockt. Ein offener Fall heisst bewusst "liegt bei HR" — auch
        // wenn HR den Pruefen-Toggle schon gesetzt, den Fall aber noch nicht
        // geschlossen/gesendet hat, darf der Schulungsleiter nicht parallel
        // per Bulk-Versand senden.
        $blockedByLegalStatus = collect();
        $eligible = $this->bookings->filter(function ($b) use (&$blockedByLegalStatus) {
            if ($b->status !== 'attended') return false;
            if (!$b->applicant?->contract_template_id) return false;
            if ($b->applicant->hasAnyContractSent()) return false;
            if ($this->isLegalStatusUnchecked($b->applicant)
                || isset($this->openNonEuCaseApplicantIds[$b->applicant->id])) {
                $blockedByLegalStatus->push($b);
                return false;
            }
            return true;
        });

        if ($eligible->isEmpty()) {
            $msg = 'Keine anwesenden Bewerber mit Vertragsvorlage und noch nicht versendet.';
            if ($blockedByLegalStatus->isNotEmpty()) {
                $msg .= sprintf(
                    ' (%d Bewerber wegen offener Rechtsstatus-Pruefung uebersprungen — bitte zuerst auf HR-Schreibtisch pruefen.)',
                    $blockedByLegalStatus->count(),
                );
            }
            session()->flash('error', $msg);
            return;
        }

        $missingBeginn = $eligible->filter(function ($b) {
            $applicantId = $b->applicant->id;
            return empty($this->contractDates[$applicantId]['vertragsbeginn'] ?? null);
        });
        if ($missingBeginn->isNotEmpty()) {
            session()->flash('error', 'Bei mind. einem zu versendenden Bewerber fehlt der Vertragsbeginn.');
            return;
        }

        $dispatch = app(ContractDispatchService::class);
        $contractsSent = 0;
        $portalsSent = 0;
        $errors = 0;

        foreach ($eligible as $booking) {
            $applicantId = $booking->applicant->id;
            $fields = $this->contractDates[$applicantId] ?? null;
            $result = $dispatch->sendForApplicant($booking->applicant, auth()->id(), $fields, $this->defaultContractTemplate);

            if ($result['status'] === 'sent') {
                $contractsSent++;
                if ($result['portal_sent']) {
                    $portalsSent++;
                } elseif (ContractDispatchService::isPortalFailure($result)) {
                    // Portal-Fehler NACH erfolgreichem Vertragsversand —
                    // contractsSent zählt, errors auch. Die Auswertung liegt
                    // im Service, damit sie hier nicht wieder von der des
                    // HR-Schreibtischs abweicht: bis 08/2026 stand hier
                    // `message !== null`, was den Fall "kein Mitarbeiter-
                    // Datensatz" (message war null) als Erfolg durchgehen
                    // liess — grüner Flash, während der Bewerber gar keine
                    // Nachricht bekam.
                    $errors++;
                }
            } elseif ($result['status'] === 'error') {
                $errors++;
            }
            // 'skipped_already_sent' kann hier nicht auftreten — der
            // Eligibility-Filter oben schließt hasAnyContractSent() aus.
        }

        unset($this->bookings, $this->openNonEuCaseApplicantIds);
        $this->hydrateContractDatesFromExistingContracts();

        if ($errors === 0) {
            $msg = "Verträge + Portal-Link versendet: {$contractsSent} Verträge, {$portalsSent} Portal-WA.";
            if ($blockedByLegalStatus->isNotEmpty()) {
                $msg .= sprintf(
                    ' %d Bewerber wegen offener Rechtsstatus-Pruefung uebersprungen — bitte auf HR-Schreibtisch pruefen.',
                    $blockedByLegalStatus->count(),
                );
            }
            session()->flash('success', $msg);
        } else {
            session()->flash('error', "Verträge: {$contractsSent}, Portal: {$portalsSent}, Fehler: {$errors}. Details siehe Logs.");
        }
    }

    // ------------------------------------------------------------------
    // Bewertungs-Modal: fuenf Kriterien à 1-5 Sterne, Waeschepaket,
    // Qualifikation, Bewertungstext — alle acht Felder in einem Vorgang.
    // ------------------------------------------------------------------

    /**
     * Mappt die CRM-Links eines Bewerbers in die Kandidaten-Form, die
     * ApplicantContactName erwartet. Die Relation ist bereits eager geladen
     * (Spec F12) — kein zusaetzlicher Query.
     *
     * @return array<int, array<string, mixed>>
     */





    /**
     * True wenn der Bewerber rechtsstatus-pruefung-pflichtig ist (nicht-EU
     * oder unbeantwortet) und der Pruefen-Toggle auf dem HR-Schreibtisch
     * NICHT gesetzt ist. EU-Buerger sind nie unchecked → kein Block.
     */
    private function isLegalStatusUnchecked($applicant): bool
    {
        // Zentrale Regel im RecApplicant-Adapter / LegalStatusGate. Hier nur
        // noch Null-Guard fuer den Applicant selbst.
        return (bool) $applicant?->isLegalStatusUnchecked();
    }

    /**
     * Computed: returns one of:
     *  - 'no_attended'           → kein Bewerber als anwesend markiert
     *  - 'no_default_template'   → kein aktives AV-default vorhanden
     *  - 'missing_dates'         → mind. 1 anwesender (noch nicht versendet) ohne Vertragsbeginn
     *  - 'missing_zuschlag'      → mind. 1 anwesender (noch nicht versendet) ohne Zuschlag
     *  - 'all_already_sent'      → alle anwesenden haben schon Verträge versendet
     *  - 'pending_legal_check'   → die nicht-versendeten warten alle auf HR-Schreibtisch-Pruefung
     *  - 'ready'                 → mind. 1 anwesender hat Zuschlag + Datum + Rechtsstatus-pruefung-ok
     */
    #[Computed]
    public function bulkSendState(): string
    {
        $attended = $this->bookings->filter(fn ($b) => $b->status === 'attended');
        if ($attended->isEmpty()) {
            return 'no_attended';
        }
        // Vorlage ist fix AV-default → kein Auswahl-Gate mehr. Einziger Block:
        // wenn kein aktives AV-default existiert.
        if (!$this->defaultContractTemplate) {
            return 'no_default_template';
        }
        $allAlreadySent = $attended->every(fn ($b) => $b->applicant?->hasAnyContractSent());
        if ($allAlreadySent) {
            return 'all_already_sent';
        }
        $pending = $attended->filter(fn ($b) => !$b->applicant?->hasAnyContractSent());

        // Block B Filter: pending muss durch die Rechtsstatus-Pruefung —
        // wenn alle pending ungepruefte Nicht-EU sind, bleibt nichts zum
        // Senden ueber → eigener State der HR direkt zum HR-Schreibtisch
        // verweist.
        $pendingAfterLegal = $pending->filter(fn ($b) => !$this->isLegalStatusUnchecked($b->applicant)
            && !isset($this->openNonEuCaseApplicantIds[$b->applicant?->id]));
        if ($pendingAfterLegal->isEmpty()) {
            return 'pending_legal_check';
        }
        $pending = $pendingAfterLegal;
        $missingBeginn = $pending->filter(function ($b) {
            $applicantId = $b->applicant?->id;
            return $applicantId && empty($this->contractDates[$applicantId]['vertragsbeginn'] ?? null);
        });
        if ($missingBeginn->isNotEmpty()) {
            return 'missing_dates';
        }
        $missingZuschlag = $pending->filter(fn ($b) => $b->applicant?->zuschlag === null);
        if ($missingZuschlag->isNotEmpty()) {
            return 'missing_zuschlag';
        }
        return 'ready';
    }

    #[Computed]
    public function defaultContractTemplate()
    {
        return RecContractTemplate::where('team_id', auth()->user()->currentTeam->id)
            ->where('code', 'AV-default')
            ->where('is_active', true)
            ->first();
    }

    /**
     * Weist dem Bewerber die AV-default-Vorlage zu, falls noch keine gesetzt
     * ist und ein aktives AV-default existiert. Idempotent.
     */
    private function assignDefaultTemplateIfMissing(?RecApplicant $applicant): void
    {
        if (!$applicant) {
            return;
        }

        $neu = \Platform\Recruiting\Support\DefaultContractTemplateAssignment::resolve(
            $applicant->contract_template_id ? (int) $applicant->contract_template_id : null,
            $this->defaultContractTemplate?->id,
        );

        if ($neu !== null) {
            $applicant->contract_template_id = $neu;
            $applicant->save();
        }
    }

    public function sendReminder(int $bookingId): void
    {
        $booking = RecInterviewBooking::with(['applicant.crmContactLinks.contact.phoneNumbers'])
            ->findOrFail($bookingId);

        $interview = $this->interview;

        if (!$interview->reminder_wa_template_id) {
            session()->flash('error', 'Kein WhatsApp-Template am Termin konfiguriert.');
            return;
        }

        if (!class_exists(\Platform\Integrations\Models\IntegrationsWhatsAppTemplate::class)) {
            session()->flash('error', 'WhatsApp-Integrations-Modul nicht verfügbar.');
            return;
        }

        $template = \Platform\Integrations\Models\IntegrationsWhatsAppTemplate::find($interview->reminder_wa_template_id);
        if (!$template || $template->status !== 'APPROVED') {
            session()->flash('error', 'Template nicht gefunden oder nicht freigegeben.');
            return;
        }

        $channel = $this->resolveWhatsAppChannel($template);
        if (!$channel) {
            session()->flash('error', 'Kein aktiver WhatsApp-Kanal gefunden.');
            return;
        }

        $phoneNumber = $this->findPhoneNumber($booking);
        if (!$phoneNumber) {
            session()->flash('error', 'Keine Telefonnummer für diesen Kandidaten gefunden.');
            return;
        }

        try {
            $components = $interview->resolveTemplateComponents(
                $template->components ?? [],
                $booking,
            );

            $service = app(WhatsAppMetaService::class);
            $message = $service->sendTemplate(
                channel: $channel,
                to: $phoneNumber->international,
                templateName: $template->name,
                components: $components,
                languageCode: $template->language ?? 'de',
                sender: auth()->user(),
            );

            // Link thread to applicant context
            if ($message->thread && $booking->applicant) {
                $message->thread->addContext(
                    get_class($booking->applicant),
                    $booking->applicant->id,
                    'interview_reminder',
                );
            }

            $booking->update(['reminder_sent_at' => now()]);
            session()->flash('success', 'Erinnerung gesendet an ' . $phoneNumber->international);
        } catch (\Throwable $e) {
            session()->flash('error', 'Versand fehlgeschlagen: ' . $e->getMessage());
        }
    }

    private function resolveWhatsAppChannel($template): ?CommsChannel
    {
        $account = $template->whatsappAccount;
        if (!$account || !$account->active) {
            return null;
        }

        return CommsChannel::where('type', 'whatsapp')
            ->where('is_active', true)
            ->where('sender_identifier', $account->phone_number)
            ->first();
    }

    private function findPhoneNumber(RecInterviewBooking $booking): ?CrmPhoneNumber
    {
        $applicant = $booking->applicant;
        if (!$applicant) {
            return null;
        }

        foreach ($applicant->crmContactLinks as $link) {
            $contact = $link->contact;
            if (!$contact) {
                continue;
            }

            $primary = $contact->phoneNumbers
                ->where('is_active', true)
                ->where('is_primary', true)
                ->whereNotNull('international')
                ->first();

            if ($primary) {
                return $primary;
            }

            $fallback = $contact->phoneNumbers
                ->where('is_active', true)
                ->whereNotNull('international')
                ->first();

            if ($fallback) {
                return $fallback;
            }
        }

        return null;
    }
}
