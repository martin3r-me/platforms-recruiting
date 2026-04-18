<?php

namespace Platform\Recruiting\Livewire\InterviewBookings;

use Livewire\Component;
use Livewire\Attributes\Computed;
use Platform\Crm\Models\CommsChannel;
use Platform\Crm\Models\CrmPhoneNumber;
use Platform\Crm\Services\Comms\WhatsAppMetaService;
use Platform\Recruiting\Models\RecApplicant;
use Platform\Recruiting\Models\RecInterview;
use Platform\Recruiting\Models\RecInterviewBooking;

class Index extends Component
{
    public $interviewId;
    public $search = '';
    public $filterStatus = 'all';

    public $showBookModal = false;
    public $selectedApplicantId = '';
    public $bookingNotes = '';

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
        return RecInterviewBooking::where('rec_interview_id', $this->interviewId)
            ->when($this->search, function ($q) {
                $q->whereHas('applicant.crmContactLinks.contact', function ($query) {
                    $query->where('first_name', 'like', '%' . $this->search . '%')
                        ->orWhere('last_name', 'like', '%' . $this->search . '%');
                });
            })
            ->when($this->filterStatus !== 'all', fn($q) => $q->where('status', $this->filterStatus))
            ->with(['applicant.crmContactLinks.contact', 'applicant.postings.position'])
            ->orderBy('booked_at', 'desc')
            ->get();
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
            $query->whereHas('postings', function ($q) {
                $q->whereHas('position', function ($pq) {
                    $pq->where('rec_positions.id', $this->interview->rec_position_id);
                });
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

        RecInterviewBooking::create([
            'rec_interview_id' => $this->interviewId,
            'rec_applicant_id' => $this->selectedApplicantId,
            'status' => 'registered',
            'notes' => $this->bookingNotes ?: null,
            'booked_at' => now(),
            'team_id' => auth()->user()->currentTeam->id,
            'created_by_user_id' => auth()->id(),
        ]);

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
