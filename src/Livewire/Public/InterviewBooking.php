<?php

namespace Platform\Recruiting\Livewire\Public;

use Livewire\Attributes\Computed;
use Livewire\Component;
use Platform\Recruiting\Models\RecApplicant;
use Platform\Recruiting\Models\RecInterview;
use Platform\Recruiting\Models\RecInterviewBooking;

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

        $applicant = RecApplicant::find($this->applicantId);
        if (!$applicant) {
            return [];
        }

        $positionIds = $applicant->positions()->pluck('id')->all();

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

        RecInterviewBooking::create([
            'rec_interview_id' => $interviewId,
            'rec_applicant_id' => $this->applicantId,
            'status' => 'registered',
            'booked_at' => now(),
            'team_id' => $this->teamId,
        ]);

        unset($this->existingBooking, $this->availableInterviews);
        $this->state = 'booked';
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
