<?php

namespace Platform\Recruiting\Console\Commands;

use Illuminate\Console\Command;
use Platform\Recruiting\Jobs\NotifyWaitlistForInterview;
use Platform\Recruiting\Models\RecAutoPilotLog;
use Platform\Recruiting\Models\RecAutoPilotState;
use Platform\Recruiting\Models\RecInterviewBooking;
use Platform\Recruiting\Support\SeatStandbyPolicy;

/**
 * Backfill + Heal fuer das Standby-Modell: Der Live-Trigger (ProcessAutoPilot-
 * Applicants, max_reminders_reached) feuert nur beim UEBERGANG — Bewerber, die
 * schon vor dem Deploy auf review_needed standen, werden von der Auto-Pilot-
 * Query ausgeschlossen und durchlaufen ihn nie. Dieser Command gibt deren
 * Plaetze nachtraeglich frei. Idempotent (seat_released_at-Guard), beliebig
 * wiederholbar — deckt auch per MCP-Tool direkt gesetzte States ab.
 */
class ReleaseStaleSeats extends Command
{
    protected $signature = 'recruiting:release-stale-seats {--dry-run : Nur zaehlen, nichts schreiben}';

    protected $description = 'Gibt Schulungsplaetze aufgegebener Bewerber (review_needed, Buchung booked) als Standby frei.';

    public function handle(): int
    {
        $reviewNeededId = RecAutoPilotState::where('code', 'review_needed')->whereNull('team_id')->value('id');
        if (!$reviewNeededId) {
            $this->error('AutoPilot-State review_needed nicht gefunden.');
            return Command::FAILURE;
        }

        $bookings = RecInterviewBooking::query()
            ->where('status', 'booked')
            ->whereNull('seat_released_at')
            ->whereHas('applicant', fn ($q) => $q
                ->where('auto_pilot', true)
                ->where('auto_pilot_state_id', $reviewNeededId))
            ->whereHas('interview', fn ($q) => $q->where('starts_at', '>', now()))
            ->get();

        if ($this->option('dry-run')) {
            $this->info("Dry-Run: {$bookings->count()} Platz/Plaetze wuerden freigegeben.");
            return Command::SUCCESS;
        }

        $released = 0;
        foreach ($bookings as $booking) {
            if (!SeatStandbyPolicy::shouldRelease($booking->status, $booking->seat_released_at !== null)) {
                continue;
            }
            $booking->seat_released_at = now();
            $booking->save();
            $released++;

            try {
                RecAutoPilotLog::create([
                    'rec_applicant_id' => $booking->rec_applicant_id,
                    'type' => 'seat_released',
                    'summary' => "Schulungsplatz freigegeben (Heal-Command) — Buchung #{$booking->id}.",
                    'details' => ['booking_id' => $booking->id, 'interview_id' => $booking->rec_interview_id, 'source' => 'heal'],
                ]);
            } catch (\Throwable) {}

            NotifyWaitlistForInterview::dispatch($booking->rec_interview_id);
        }

        $this->info("{$released} Platz/Plaetze freigegeben.");
        return Command::SUCCESS;
    }
}
