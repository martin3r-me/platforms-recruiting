<?php

namespace Platform\Recruiting\Console\Commands;

use Illuminate\Console\Command;
use Platform\Recruiting\Models\RecInterviewWaitlist;

/**
 * Schließt offene Termin-Warteliste-Einträge, deren Termin nicht mehr
 * stattfinden kann (abgesagt, deaktiviert oder in der Vergangenheit).
 *
 * Wichtig, weil offene Einträge den Auto-Pilot des Bewerbers pausieren
 * (ProcessAutoPilotApplicants) — ohne Cleanup würde ein Eintrag auf einen
 * abgesagten Termin den Bewerber dauerhaft stummschalten.
 *
 * Ort-Einträge (rec_interview_id NULL) werden NIE angefasst — die haben
 * keinen Termin-Bezug, der ablaufen könnte.
 */
class CleanupInterviewWaitlist extends Command
{
    protected $signature = 'recruiting:cleanup-interview-waitlist';

    protected $description = 'Schließt offene Termin-Warteliste-Einträge zu abgesagten/vergangenen Terminen';

    public function handle(): int
    {
        $closed = RecInterviewWaitlist::query()
            ->open()
            ->whereNotNull('rec_interview_id')
            ->whereHas('interview', function ($query) {
                $query->where(function ($query) {
                    $query->where('is_active', false)
                        ->orWhereNotIn('status', ['planned', 'confirmed'])
                        ->orWhere('starts_at', '<=', now());
                });
            })
            ->update(['cancelled_at' => now()]);

        $this->info("{$closed} Termin-Warteliste-Einträge geschlossen.");

        return self::SUCCESS;
    }
}
