<?php

namespace Platform\Recruiting\Console\Commands;

use Illuminate\Console\Command;
use Platform\Recruiting\Services\ImportApplicantsCsvService;

/**
 * Konsolen-Wrapper um ImportApplicantsCsvService. Identische Logik nutzt
 * auch die Bewerber-Liste-UI (Upload-Modal). Kommando ist primär für
 * Initial-/Backfill-Importe oder serverseitiges Scheduling.
 *
 * Aufruf:
 *   php artisan recruiting:import-csv /pfad/zur/datei.csv --team-id=3 --dry-run
 *   php artisan recruiting:import-csv /pfad/zur/datei.csv --team-id=3
 */
class ImportApplicantsCsv extends Command
{
    protected $signature = 'recruiting:import-csv
        {file : Pfad zur CSV-Datei}
        {--team-id= : Team-ID (Pflicht, da Modul team-scoped)}
        {--dry-run : Zeigt nur was passieren würde, ohne zu schreiben}
        {--limit=0 : Maximale Anzahl Datensätze (0 = alle)}';

    protected $description = 'Importiert Altbestand-Bewerber aus CSV. Setzt import_source=csv_legacy. Kein Posting, kein AutoPilot.';

    public function handle(ImportApplicantsCsvService $service): int
    {
        $file = $this->argument('file');
        $teamId = (int) $this->option('team-id');
        $dryRun = (bool) $this->option('dry-run');
        $limit = max(0, (int) $this->option('limit'));

        if ($teamId <= 0) {
            $this->error('--team-id ist Pflicht.');
            return self::FAILURE;
        }

        if ($dryRun) {
            $this->warn('DRY-RUN — keine Schreibvorgänge.');
        }

        $r = $service->importFromFile($file, $teamId, $dryRun, $limit);

        if ($r['fatal']) {
            $this->error($r['fatal']);
            return self::FAILURE;
        }

        $this->newLine();
        $this->info("Parsed:                    {$r['parsed']}");
        $this->info("Importiert:                {$r['imported']}" . ($dryRun ? ' (dry-run)' : ''));
        $this->info("Skipped (Dup im Run):      {$r['skipped_dup']}");
        $this->info("Skipped (existiert schon): {$r['skipped_existing']}");
        $this->info("Skipped (unvollständig):   {$r['skipped_incompl']}");

        if (!empty($r['errors'])) {
            $this->newLine();
            $this->warn('Fehler:');
            foreach ($r['errors'] as $err) {
                $this->line("  Zeile {$err['row']} ({$err['name']}): {$err['message']}");
            }
            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
