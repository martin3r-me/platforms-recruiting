<?php

namespace Platform\Recruiting\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Platform\Recruiting\Models\RecApplicant;
use Platform\Recruiting\Services\CreateEmployeeFromApplicantService;

/**
 * Erstellt Mitarbeiter fuer alle Bewerber, die an einer Schulung
 * an einem bestimmten Datum teilgenommen haben (status=attended),
 * und setzt ggf. zas_initial_exported_at auf NULL fuer bereits
 * exportierte MA damit sie erneut auf dem Initial-Endpoint erscheinen.
 *
 * CreateEmployeeFromApplicantService ist idempotent — bereits
 * existierende MA werden uebersprungen.
 *
 * Aufruf:
 *   php artisan recruiting:zas-re-export-by-booking --dry-run
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

    protected $description = 'Erstellt MA aus attended-Schulungsteilnehmern und setzt Initial-Export-Marker zurueck';

    public function handle(CreateEmployeeFromApplicantService $service): int
    {
        $date = $this->option('date') ?? now()->subDay()->toDateString();
        $teamId = $this->option('team-id');
        $dryRun = (bool) $this->option('dry-run');

        $this->info(sprintf('Suche attended-Teilnehmer mit Schulung am %s ...', $date));

        // 1. Schulungen am Datum
        $interviews = DB::table('rec_interviews')
            ->whereDate('starts_at', $date)
            ->whereNull('deleted_at')
            ->get(['id', 'title', 'starts_at']);
        $this->line(sprintf('  Schulungen am %s: %d', $date, $interviews->count()));
        foreach ($interviews as $iv) {
            $this->line(sprintf('    - [%s] %s (%s)', $iv->id, $iv->title, $iv->starts_at));
        }

        if ($interviews->isEmpty()) {
            $this->warn('Keine Schulungen an diesem Datum.');
            return self::SUCCESS;
        }

        // 2. Attended-Buchungen fuer diese Schulungen
        $query = DB::table('rec_interview_bookings as ib')
            ->join('rec_interviews as i', 'i.id', '=', 'ib.rec_interview_id')
            ->whereDate('i.starts_at', $date)
            ->whereNull('ib.deleted_at')
            ->where('ib.status', 'attended');

        if ($teamId !== null) {
            $query->join('rec_applicants as a', 'a.id', '=', 'ib.rec_applicant_id')
                ->where('a.team_id', (int) $teamId);
        }

        $attendedApplicantIds = $query->pluck('ib.rec_applicant_id')->unique();

        $this->line(sprintf('  Teilnehmer (attended): %d', $attendedApplicantIds->count()));

        if ($attendedApplicantIds->isEmpty()) {
            $this->warn('Keine attended-Teilnehmer gefunden.');
            return self::SUCCESS;
        }

        // 3. Pruefen: welche haben schon einen MA, welche nicht?
        $existingEmployees = DB::table('rec_employees')
            ->whereIn('rec_applicant_id', $attendedApplicantIds)
            ->get(['id', 'rec_applicant_id', 'zas_initial_exported_at']);

        $existingMap = $existingEmployees->keyBy('rec_applicant_id');
        $missingIds = $attendedApplicantIds->diff($existingMap->keys());
        $alreadyExportedIds = $existingEmployees
            ->filter(fn ($e) => $e->zas_initial_exported_at !== null)
            ->pluck('id');

        $this->newLine();
        $this->line(sprintf('  Bereits MA vorhanden: %d', $existingMap->count()));
        $this->line(sprintf('    davon bereits exportiert (Reset noetig): %d', $alreadyExportedIds->count()));
        $this->line(sprintf('    davon noch nicht exportiert (OK): %d', $existingMap->count() - $alreadyExportedIds->count()));
        $this->line(sprintf('  Noch kein MA (wird angelegt): %d', $missingIds->count()));

        if ($missingIds->isEmpty() && $alreadyExportedIds->isEmpty()) {
            $this->info('Nichts zu tun — alle Teilnehmer haben bereits einen MA auf dem Initial-Endpoint.');
            return self::SUCCESS;
        }

        if ($dryRun) {
            if ($missingIds->isNotEmpty()) {
                $this->warn(sprintf('DRY-RUN: %d MA wuerden angelegt fuer Bewerber: %s', $missingIds->count(), $missingIds->implode(', ')));
            }
            if ($alreadyExportedIds->isNotEmpty()) {
                $this->warn(sprintf('DRY-RUN: %d MA wuerden zurueckgesetzt (zas_initial_exported_at → NULL)', $alreadyExportedIds->count()));
            }
            return self::SUCCESS;
        }

        if (!$this->option('force') && !$this->confirm('Fortfahren?', true)) {
            $this->warn('Abgebrochen.');
            return self::SUCCESS;
        }

        // 4. Fehlende MA anlegen
        $created = 0;
        $errors = 0;
        foreach ($missingIds as $applicantId) {
            $applicant = RecApplicant::find($applicantId);
            if (!$applicant) {
                $this->error(sprintf('  Bewerber %s nicht gefunden — uebersprungen.', $applicantId));
                $errors++;
                continue;
            }
            try {
                $employee = $service->createOrUpdate($applicant);
                $this->line(sprintf('  MA angelegt: emp=%s fuer applicant=%s (%s %s)',
                    $employee->id, $applicantId, $employee->first_name, $employee->last_name));
                $created++;
            } catch (\Throwable $e) {
                $this->error(sprintf('  Fehler bei applicant=%s: %s', $applicantId, $e->getMessage()));
                $errors++;
            }
        }

        // 5. Bereits exportierte MA zuruecksetzen
        $reset = 0;
        if ($alreadyExportedIds->isNotEmpty()) {
            $reset = DB::table('rec_employees')
                ->whereIn('id', $alreadyExportedIds)
                ->update(['zas_initial_exported_at' => null]);
        }

        $this->newLine();
        $this->info(sprintf('Fertig — %d MA angelegt, %d zurueckgesetzt, %d Fehler.', $created, $reset, $errors));
        $this->info('Alle erscheinen beim naechsten ZAS-Pull auf /employees/initial.csv.');

        return $errors > 0 ? self::FAILURE : self::SUCCESS;
    }
}
