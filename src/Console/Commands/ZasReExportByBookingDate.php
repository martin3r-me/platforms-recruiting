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

        $this->info(sprintf('Suche Mitarbeiter mit Schulung am %s ...', $date));

        // Debug: Schulungen an diesem Datum
        $interviews = DB::table('rec_interviews')
            ->whereDate('starts_at', $date)
            ->whereNull('deleted_at')
            ->get(['id', 'title', 'starts_at']);
        $this->line(sprintf('  Schulungen am %s: %d', $date, $interviews->count()));
        foreach ($interviews as $iv) {
            $this->line(sprintf('    - [%s] %s (%s)', $iv->id, $iv->title, $iv->starts_at));
        }

        // Debug: Buchungen fuer diese Schulungen
        $bookings = DB::table('rec_interview_bookings as ib')
            ->join('rec_interviews as i', 'i.id', '=', 'ib.rec_interview_id')
            ->whereDate('i.starts_at', $date)
            ->whereNull('ib.deleted_at')
            ->get(['ib.rec_applicant_id', 'ib.rec_interview_id', 'ib.status']);
        $this->line(sprintf('  Buchungen dazu: %d', $bookings->count()));
        foreach ($bookings as $b) {
            $this->line(sprintf('    - applicant=%s status=%s', $b->rec_applicant_id, $b->status));
        }

        // Debug: Mitarbeiter zu diesen Bewerbern
        $applicantIds = $bookings->pluck('rec_applicant_id')->unique();
        $employees = DB::table('rec_employees')
            ->whereIn('rec_applicant_id', $applicantIds)
            ->get(['id', 'rec_applicant_id', 'is_active', 'zas_initial_exported_at']);
        $this->line(sprintf('  Mitarbeiter zu diesen Bewerbern: %d', $employees->count()));
        foreach ($employees as $emp) {
            $this->line(sprintf('    - emp=%s applicant=%s active=%s exported=%s',
                $emp->id, $emp->rec_applicant_id, $emp->is_active ? 'ja' : 'nein', $emp->zas_initial_exported_at ?? 'NULL'));
        }

        $this->newLine();

        // Eigentliche Query
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
            $this->warn('Keine Mitarbeiter matchen alle Filter (exported + active + Schulung am Datum).');
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
