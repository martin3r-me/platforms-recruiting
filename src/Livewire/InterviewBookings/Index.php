<?php

namespace Platform\Recruiting\Livewire\InterviewBookings;

use Livewire\Component;
use Livewire\Attributes\Computed;
use Platform\Crm\Models\CommsChannel;
use Platform\Crm\Models\CrmPhoneNumber;
use Platform\Crm\Services\Comms\WhatsAppMetaService;
use Platform\Recruiting\Models\RecApplicant;
use Platform\Recruiting\Models\RecContract;
use Platform\Recruiting\Models\RecContractTemplate;
use Platform\Recruiting\Models\RecEmployee;
use Platform\Recruiting\Models\RecInterview;
use Platform\Recruiting\Models\RecInterviewBooking;
use Platform\Recruiting\Services\SendContractsService;
use Platform\Core\Models\CoreLookup;

class Index extends Component
{
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

    /**
     * Schulungs-Bewertungs-Modal. Pro MA setzt der Schulungsleiter
     * Waeschepaket, Qualifikation und Sternebewertung. Daten landen
     * auf rec_employee_hr_data (linen_package_items, qualifications,
     * star_rating). Modal ist nur ansteuerbar wenn fuer den
     * applicant bereits ein RecEmployee existiert (= Phase 4 done).
     */
    public bool $showEvaluationModal = false;
    public ?int $evaluateBookingId = null;
    public array $evaluation = [
        'linen_package_items' => [],
        'qualifications'      => [],
        'star_rating'         => null,
    ];

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

    #[Computed]
    public function availableApplicants()
    {
        $teamId = auth()->user()->currentTeam->id;

        // Exclude applicants with any active booking (across all interviews)
        $bookedIds = RecInterviewBooking::whereNotIn('status', ['cancelled'])
            ->pluck('rec_applicant_id');

        $query = RecApplicant::where('team_id', $teamId)
            ->where('is_active', true)
            ->whereNotNull('auto_pilot_completed_at')
            ->whereNotIn('id', $bookedIds);

        if ($this->interview->rec_position_id) {
            // Stellen-Filter mit Bypass für Importierte: legacy CSV-Imports
            // haben keine Postings/Positions — sie sollen aber in jede
            // Schulung buchbar sein, unabhängig von der Termin-Stelle.
            $query->where(function ($q) {
                $q->whereHas('postings', function ($q) {
                    $q->whereHas('position', function ($pq) {
                        $pq->where('rec_positions.id', $this->interview->rec_position_id);
                    });
                })->orWhereNotNull('import_source');
            });
        }

        return $query->with(['crmContactLinks.contact'])
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

        $interview = $this->interview;

        if ($interview->max_participants) {
            $currentCount = RecInterviewBooking::where('rec_interview_id', $this->interviewId)
                ->whereNotIn('status', ['cancelled'])
                ->count();

            if ($currentCount >= $interview->max_participants) {
                session()->flash('error', 'Maximale Teilnehmerzahl erreicht!');
                return;
            }
        }

        // Check if applicant already has an active booking in ANY interview
        $existing = RecInterviewBooking::where('rec_applicant_id', $this->selectedApplicantId)
            ->whereNotIn('status', ['cancelled'])
            ->exists();

        if ($existing) {
            session()->flash('error', 'Dieser Kandidat ist bereits in einem Termin gebucht!');
            return;
        }

        // Status 'booked' (NEU mit Schritt 3): konsistent zum Public-Form-Pfad.
        // HR bucht hier manuell einen Kandidaten in eine Schulung — gleiche
        // Initial-Semantik wie wenn der Bewerber sich selbst gebucht haette.
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
            ],
        );

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

    public function updateStatus(int $bookingId, string $status): void
    {
        $validStatuses = ['booked', 'registered', 'confirmed', 'attended', 'cancelled', 'no_show'];
        if (!in_array($status, $validStatuses)) {
            return;
        }

        $booking = RecInterviewBooking::findOrFail($bookingId);

        // Storno-Metadaten setzen wenn HR manuell auf cancelled umstellt
        // (cancelled_by='hr' damit der HR-Schreibtisch zwischen
        // "Bewerber hat selbst abgesagt" und "HR hat abgesagt" unterscheiden
        // kann). Wenn der Status von cancelled WEG geht, Storno-Metadaten
        // wieder zuruecksetzen damit alte Storno-Info nicht haengen bleibt.
        $updates = ['status' => $status];
        if ($status === 'cancelled' && $booking->status !== 'cancelled') {
            $updates['cancelled_by'] = 'hr';
            $updates['cancelled_at'] = now();
        } elseif ($status !== 'cancelled' && $booking->status === 'cancelled') {
            $updates['cancelled_by'] = null;
            $updates['cancelled_at'] = null;
        }

        $booking->update($updates);

        // Ab Status "Teilgenommen" wird die Standard-Vertragsvorlage (AV-default)
        // automatisch zugewiesen — HR wählt nichts mehr aus.
        if ($status === 'attended') {
            $this->assignDefaultTemplateIfMissing($booking->fresh('applicant')?->applicant);
        }

        // Computed-Cache busten, damit Anzeige (Vorlage/Status) den frischen Stand zeigt.
        unset($this->bookings);

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
        unset($this->bookings);

        $blockedByLegalStatus = collect();
        $eligible = $this->bookings->filter(function ($b) use (&$blockedByLegalStatus) {
            if ($b->status !== 'attended') return false;
            if (!$b->applicant?->contract_template_id) return false;
            if ($b->applicant->hasAnyContractSent()) return false;
            if ($this->isLegalStatusUnchecked($b->applicant)) {
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

        unset($this->bookings);
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
        $blockedByLegalStatus = collect();
        $eligible = $this->bookings->filter(function ($b) use (&$blockedByLegalStatus) {
            if ($b->status !== 'attended') return false;
            if (!$b->applicant?->contract_template_id) return false;
            if ($b->applicant->hasAnyContractSent()) return false;
            if ($this->isLegalStatusUnchecked($b->applicant)) {
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

        $service = app(SendContractsService::class);
        $contractsSent = 0;
        $portalsSent = 0;
        $errors = 0;

        foreach ($eligible as $booking) {
            try {
                $applicantId = $booking->applicant->id;
                $fields = $this->contractDates[$applicantId] ?? null;
                // skipNotification=true: Vertrags-WA wird unterdrueckt — der
                // MA bekommt stattdessen nur die Portal-WA (das Portal listet
                // die Vertraege ohnehin auf).
                $service->send($booking->applicant, auth()->id(), $fields, true);
                $contractsSent++;

                // Phase-Hook hat den MA angelegt — jetzt Portal-Link nachschieben.
                $employee = RecEmployee::where('rec_applicant_id', $applicantId)->first();
                if ($employee) {
                    $employee->sendPortalNotification();
                    $portalsSent++;
                }
            } catch (\Throwable $e) {
                $errors++;
            }
        }

        unset($this->bookings);
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
    // Bewertungs-Modal (Waeschepaket, Qualifikation, Sternebewertung)
    // ------------------------------------------------------------------

    public function openEvaluationModal(int $bookingId): void
    {
        $booking = $this->bookings->firstWhere('id', $bookingId);
        $employee = $booking?->applicant?->employee;
        if (!$employee) {
            session()->flash('error', 'Mitarbeiter noch nicht angelegt — Vertraege zuerst versenden.');
            return;
        }

        $hr = $employee->hrData ?? $employee->ensureHrData()->fresh();
        $this->evaluateBookingId = $bookingId;
        $this->evaluation = [
            'linen_package_items' => is_array($hr->linen_package_items) ? $hr->linen_package_items : [],
            'qualifications'      => is_array($hr->qualifications) ? $hr->qualifications : [],
            'star_rating'         => $hr->star_rating !== null ? (string) $hr->star_rating : null,
        ];
        $this->showEvaluationModal = true;
    }

    public function closeEvaluationModal(): void
    {
        $this->showEvaluationModal = false;
        $this->evaluateBookingId = null;
        $this->evaluation = ['linen_package_items' => [], 'qualifications' => [], 'star_rating' => null];
    }

    public function saveEvaluation(): void
    {
        if (!$this->evaluateBookingId) {
            return;
        }
        $booking = $this->bookings->firstWhere('id', $this->evaluateBookingId);
        $employee = $booking?->applicant?->employee;
        if (!$employee) {
            session()->flash('error', 'Mitarbeiter nicht mehr vorhanden.');
            $this->closeEvaluationModal();
            return;
        }

        $hr = $employee->hrData ?? $employee->ensureHrData();
        $hr->linen_package_items = array_values(array_filter($this->evaluation['linen_package_items'] ?? [], fn ($v) => $v !== '' && $v !== null)) ?: null;
        $hr->qualifications      = array_values(array_filter($this->evaluation['qualifications'] ?? [], fn ($v) => $v !== '' && $v !== null)) ?: null;
        $hr->star_rating         = ($this->evaluation['star_rating'] !== null && $this->evaluation['star_rating'] !== '')
            ? (int) $this->evaluation['star_rating']
            : null;
        $hr->save();

        session()->flash('success', 'Bewertung gespeichert.');
        $this->closeEvaluationModal();
        unset($this->bookings);
    }

    public function lookupOptionsFor(string $lookupName): array
    {
        $lookup = CoreLookup::where('name', $lookupName)->first();
        return $lookup ? $lookup->getOptionsArray() : [];
    }

    /**
     * True wenn der Bewerber rechtsstatus-pruefung-pflichtig ist (nicht-EU
     * oder unbeantwortet) und der Pruefen-Toggle auf dem HR-Schreibtisch
     * NICHT gesetzt ist. EU-Buerger sind nie unchecked → kein Block.
     */
    private function isLegalStatusUnchecked($applicant): bool
    {
        $legal = $applicant?->legalStatus;
        if (!$legal) {
            // Kein legalStatus-Record vorhanden → eu_burger-Frage noch nie
            // beantwortet. Production-Bewerber im Bestand haben das oft nicht
            // — wir blockieren sie nicht (sonst Versand-Regression).
            return false;
        }
        if ($legal->is_eu_citizen === true) {
            return false; // EU-Buerger: keine Pruefung noetig
        }
        // is_eu_citizen=false ODER null → Pruefung relevant
        return $legal->legal_status_checked_at === null;
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
        $pendingAfterLegal = $pending->filter(fn ($b) => !$this->isLegalStatusUnchecked($b->applicant));
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
        if (!$applicant || $applicant->contract_template_id) {
            return;
        }
        $default = $this->defaultContractTemplate;
        if ($default) {
            $applicant->contract_template_id = $default->id;
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
