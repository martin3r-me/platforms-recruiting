<?php

namespace Platform\Recruiting\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Setzt zas_initial_exported_at auf NULL fuer Mitarbeiter, deren
 * zugehoeriger Bewerber eine Schulung (rec_interviews.starts_at)
 * an einem bestimmten Datum hatte.
 *
 * Damit erscheinen diese MA beim naechsten Pull wieder auf dem
 * Initial-Endpoint (/employees/initial.csv).
 *
 * Aufruf:
 *   php artisan recruiting:zas-re-export-by-booking --date=2026-05-26 --dry-run
 *   php artisan recruiting:zas-re-export-by-booking --date=2026-05-26
 *   php artisan recruiting:zas-re-export-by-booking                        (= gestern)
 */
class ZasReExportByBookingDate extends Command
{
    protected $signature = 'recruiting:zas-re-export-by-booking
        {--date= : Datum der Schulung (YYYY-MM-DD), default = gestern}
        {--team-id= : Optional auf ein Team beschraenken}
        {--dry-run : Nur anzeigen, nichts schreiben}
        {--force : Confirmation ueberspringen}';

    protected $description = 'Setzt zas_initial_exported_at zurueck fuer MA deren Schulung an einem Datum stattfand (Re-Export via Initial-Endpoint)';

    public function handle(): int
    {
        $date = $this->option('date') ?? now()->subDay()->toDateString();
        $teamId = $this->option('team-id');
        $dryRun = (bool) $this->option('dry-run');

        $query = DB::table('rec_employees as e')
            ->whereNotNull('e.zas_initial_exported_at')
            ->where('e.is_active', true)
            ->whereExists(function ($q) use ($date) {
                $q->select(DB::raw(1))
                    ->from('rec_interview_bookings as ib')
                    ->join('rec_interviews as i', 'i.id', '=', 'ib.rec_interview_id')
                    ->whereColumn('ib.rec_applicant_id', 'e.rec_applicant_id')
                    ->whereNull('ib.deleted_at')
                    ->whereDate('i.starts_at', $date);
            });

        if ($teamId !== null) {
            $query->where('e.team_id', (int) $teamId);
        }

        $candidates = $query->pluck('e.id');
        $count = $candidates->count();

        if ($count === 0) {
            $this->info(sprintf('Keine Mitarbeiter mit Schulung am %s gefunden (oder noch nicht initial exportiert).', $date));
            return self::SUCCESS;
        }

        $this->info(sprintf(
            '%d Mitarbeiter mit Schulung am %s gefunden%s.',
            $count,
            $date,
            $teamId !== null ? sprintf(' (team_id=%d)', $teamId) : ''
        ));

        if ($dryRun) {
            $this->warn('DRY-RUN: nichts geschrieben. Ohne --dry-run ausfuehren zum Anwenden.');
            return self::SUCCESS;
        }

        if (!$this->option('force') && !$this->confirm('zas_initial_exported_at jetzt auf NULL setzen?', true)) {
            $this->warn('Abgebrochen.');
            return self::SUCCESS;
        }

        $affected = DB::table('rec_employees')
            ->whereIn('id', $candidates)
            ->update(['zas_initial_exported_at' => null]);

        $this->info(sprintf('OK — %d Mitarbeiter zurueckgesetzt. Erscheinen beim naechsten Pull auf /employees/initial.csv.', $affected));

        return self::SUCCESS;
    }
}
