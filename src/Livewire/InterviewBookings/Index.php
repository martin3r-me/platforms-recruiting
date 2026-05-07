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
use Platform\Recruiting\Models\RecInterview;
use Platform\Recruiting\Models\RecInterviewBooking;
use Platform\Recruiting\Services\SendContractsService;

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

    public function mount(int $interview)
    {
        $this->interviewId = $interview;
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

        RecInterviewBooking::updateOrCreate(
            [
                'rec_interview_id' => $this->interviewId,
                'rec_applicant_id' => $this->selectedApplicantId,
            ],
            [
                'status' => 'registered',
                'notes' => $this->bookingNotes ?: null,
                'booked_at' => now(),
                'team_id' => auth()->user()->currentTeam->id,
                'created_by_user_id' => auth()->id(),
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
        $validStatuses = ['registered', 'confirmed', 'attended', 'cancelled', 'no_show'];
        if (!in_array($status, $validStatuses)) {
            return;
        }

        $booking = RecInterviewBooking::findOrFail($bookingId);
        $booking->update(['status' => $status]);
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
        $booking = RecInterviewBooking::with('applicant')->findOrFail($bookingId);
        if (!$booking->applicant) {
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
        $eligible = $this->bookings->filter(function ($b) {
            return $b->status === 'attended'
                && $b->applicant?->contract_template_id
                && !$b->applicant->hasAnyContractSent();
        });

        if ($eligible->isEmpty()) {
            session()->flash('error', 'Keine anwesenden Bewerber mit Vertragsvorlage und noch nicht versendet.');
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

        if ($errors === 0) {
            session()->flash('success', "Verträge versendet für {$sent} Bewerber.");
        } else {
            session()->flash('error', "Versendet: {$sent}, Fehler: {$errors}. Details siehe Logs.");
        }
    }

    /**
     * Computed: returns one of:
     *  - 'no_attended'         → kein Bewerber als anwesend markiert
     *  - 'missing_templates'   → mind. 1 anwesender Bewerber ohne Vertragsvorlage
     *  - 'missing_dates'       → mind. 1 anwesender (noch nicht versendet) ohne Vertragsbeginn
     *  - 'all_already_sent'    → alle anwesenden haben schon Verträge versendet
     *  - 'ready'               → mind. 1 anwesender hat Vorlage + Datum, kein versendeter Vertrag
     */
    #[Computed]
    public function bulkSendState(): string
    {
        $attended = $this->bookings->filter(fn ($b) => $b->status === 'attended');
        if ($attended->isEmpty()) {
            return 'no_attended';
        }
        $missingTemplate = $attended->filter(fn ($b) => empty($b->applicant?->contract_template_id));
        if ($missingTemplate->isNotEmpty()) {
            return 'missing_templates';
        }
        $allAlreadySent = $attended->every(fn ($b) => $b->applicant?->hasAnyContractSent());
        if ($allAlreadySent) {
            return 'all_already_sent';
        }
        $pending = $attended->filter(fn ($b) => !$b->applicant?->hasAnyContractSent());
        $missingBeginn = $pending->filter(function ($b) {
            $applicantId = $b->applicant?->id;
            return $applicantId && empty($this->contractDates[$applicantId]['vertragsbeginn'] ?? null);
        });
        if ($missingBeginn->isNotEmpty()) {
            return 'missing_dates';
        }
        return 'ready';
    }

    #[Computed]
    public function availableContractTemplates()
    {
        return RecContractTemplate::where('team_id', auth()->user()->currentTeam->id)
            ->where('is_active', true)
            ->where(function ($q) {
                $q->where('code', 'like', 'AV-%')
                    ->orWhereNull('code')
                    ->orWhere('code', '');
            })
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get(['id', 'name', 'code']);
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
