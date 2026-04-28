<?php

namespace Platform\Recruiting\Livewire\Public;

use Livewire\Attributes\Computed;
use Livewire\Component;
use Platform\Recruiting\Models\RecApplicant;
use Platform\Recruiting\Models\RecInterview;
use Platform\Recruiting\Models\RecInterviewBooking;
use Platform\Recruiting\Models\RecPosition;

class InterviewBooking extends Component
{
    public string $publicToken = '';
    public string $state = 'loading';
    public ?int $applicantId = null;
    public string $applicantName = '';
    public ?int $teamId = null;

    public function mount(string $publicToken): void
    {
        $this->publicToken = $publicToken;

        $applicant = RecApplicant::where('public_token', $publicToken)->first();

        if (!$applicant) {
            $this->state = 'notFound';
            return;
        }

        if (!$applicant->is_active) {
            $this->state = 'notActive';
            return;
        }

        $contact = $applicant->getContact();
        $this->applicantName = $contact->full_name ?? 'Bewerber';
        $this->applicantId = $applicant->id;
        $this->teamId = $applicant->team_id;

        if ($this->existingBooking) {
            $this->state = 'booked';
        } else {
            $this->state = 'selection';
        }
    }

    #[Computed]
    public function existingBooking(): ?RecInterviewBooking
    {
        if (!$this->applicantId) {
            return null;
        }

        return RecInterviewBooking::where('rec_applicant_id', $this->applicantId)
            ->whereNotIn('status', ['cancelled'])
            ->with('interview')
            ->first();
    }

    #[Computed]
    public function availableInterviews(): array
    {
        if (!$this->applicantId) {
            return [];
        }

        $applicant = RecApplicant::with('postings.position', 'phase')->find($this->applicantId);
        if (!$applicant) {
            return [];
        }

        $positionIds = $this->resolvePositionIdsForApplicant($applicant);

        if (empty($positionIds)) {
            return [];
        }

        return RecInterview::forTeam($this->teamId)
            ->active()
            ->where('starts_at', '>', now())
            ->whereIn('status', ['planned', 'confirmed'])
            ->whereIn('rec_position_id', $positionIds)
            ->withCount(['bookings' => function ($query) {
                $query->whereNotIn('status', ['cancelled']);
            }])
            ->get()
            ->filter(function ($interview) {
                if (!$interview->max_participants) {
                    return true;
                }
                return $interview->bookings_count < $interview->max_participants;
            })
            ->sortBy('starts_at')
            ->values()
            ->all();
    }

    /**
     * Resolves the list of position IDs whose interviews the applicant is
     * allowed to see.
     *
     * Multi-Standort-Logik:
     *  - Committed (in Phase >=3 oder hat aktives Booking): nur primary-Stelle
     *  - Sonst: Wunsch-Mapping (`beschaftigungsort` → Stelle via Mapping-Spalte)
     *           plus primary als Fallback
     *
     * Falls Mapping nirgends gepflegt ist, fällt der Filter auf den heutigen
     * Effekt zurück (primary-Stelle = ihre Termine).
     */
    private function resolvePositionIdsForApplicant(RecApplicant $applicant): array
    {
        $primaryId = $applicant->postings->first()?->rec_position_id;

        $isCommitted = ($applicant->phase?->order ?? 0) >= 3
            || RecInterviewBooking::where('rec_applicant_id', $applicant->id)
                ->whereNotIn('status', ['cancelled'])
                ->exists();

        if ($isCommitted) {
            return $primaryId ? [$primaryId] : [];
        }

        $wunschOrte = $applicant->getExtraField('beschaftigungsort') ?? [];
        if (!is_array($wunschOrte)) {
            $wunschOrte = [$wunschOrte];
        }
        $wunschOrte = array_filter($wunschOrte, fn ($v) => $v !== null && $v !== '');

        $wunschPositionIds = collect();
        if (!empty($wunschOrte)) {
            $wunschPositionIds = RecPosition::forTeam($applicant->team_id)
                ->whereIn('beschaftigungsort_lookup_value', $wunschOrte)
                ->where('is_active', true)
                ->pluck('id');
        }

        return $wunschPositionIds
            ->push($primaryId)
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    public function bookInterview(int $interviewId): void
    {
        $applicant = RecApplicant::find($this->applicantId);
        if (!$applicant) {
            $this->state = 'notFound';
            return;
        }

        // Check no active booking exists
        $hasActive = RecInterviewBooking::where('rec_applicant_id', $this->applicantId)
            ->whereNotIn('status', ['cancelled'])
            ->exists();

        if ($hasActive) {
            return;
        }

        $interview = RecInterview::forTeam($this->teamId)
            ->active()
            ->where('starts_at', '>', now())
            ->whereIn('status', ['planned', 'confirmed'])
            ->find($interviewId);

        if (!$interview) {
            return;
        }

        // Capacity check
        if ($interview->max_participants) {
            $currentCount = RecInterviewBooking::where('rec_interview_id', $interviewId)
                ->whereNotIn('status', ['cancelled'])
                ->count();

            if ($currentCount >= $interview->max_participants) {
                unset($this->availableInterviews);
                return;
            }
        }

        RecInterviewBooking::updateOrCreate(
            [
                'rec_interview_id' => $interviewId,
                'rec_applicant_id' => $this->applicantId,
            ],
            [
                'status' => 'registered',
                'booked_at' => now(),
                'team_id' => $this->teamId,
            ],
        );

        // Optional: Stellen-Wechsel falls die aktuelle Phase es erlaubt
        $this->maybeSwitchPosition($applicant, $interview);

        unset($this->existingBooking, $this->availableInterviews);
        $this->state = 'booked';
    }

    /**
     * Wechselt den Bewerber zur Buchungs-Stelle, wenn:
     *  - die aktuelle Phase `completion_config.switch_position_on_booking = true` hat
     *  - der Bewerber noch in Phase order <= 2 ist (Schutz vor Datenverlust)
     *  - die Buchungs-Stelle und seine aktuelle Stelle beide gemappt sind
     *  - die Buchungs-Stelle != aktuelle primary
     */
    private function maybeSwitchPosition(RecApplicant $applicant, RecInterview $interview): void
    {
        $applicant->loadMissing('phase', 'postings.position');

        $config = $applicant->phase?->completion_config ?? [];
        $switchEnabled = ($config['switch_position_on_booking'] ?? false) === true;
        if (!$switchEnabled) {
            return;
        }

        $currentOrder = $applicant->phase?->order ?? 99;
        if ($currentOrder > 2) {
            return; // Phase 3+ → Schutz vor Datenverlust
        }

        $bookedPosition = $interview->position;
        if (!$bookedPosition) {
            return;
        }

        $primaryPosition = $applicant->primaryPosition();
        if (!$primaryPosition || $primaryPosition->id === $bookedPosition->id) {
            return; // Schon in der richtigen Stelle
        }

        // Mapping-Schutz: beide Stellen müssen einen Lookup-Wert haben
        if (empty($bookedPosition->beschaftigungsort_lookup_value)
            || empty($primaryPosition->beschaftigungsort_lookup_value)) {
            return;
        }

        $applicant->switchToPosition($bookedPosition);
    }

    public function cancelAndRebook(): void
    {
        // Cancel ALL non-cancelled bookings for this applicant (not just the first one)
        RecInterviewBooking::where('rec_applicant_id', $this->applicantId)
            ->whereNotIn('status', ['cancelled'])
            ->update(['status' => 'cancelled']);

        // Force fresh computed values on next access
        unset($this->existingBooking, $this->availableInterviews);
        $this->state = 'selection';
    }

    public function render()
    {
        return view('recruiting::livewire.public.interview-booking')
            ->layout('platform::layouts.guest');
    }
}
