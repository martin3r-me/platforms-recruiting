<?php

namespace Platform\Recruiting\Console\Commands;

use Illuminate\Console\Command;
use Platform\Recruiting\Models\RecZasDispoInboundFile;
use Platform\Recruiting\Services\Zas\Dispo\ZasDispoWebexportImporter;

/**
 * Schickt gespeicherte Dispo-Rohdateien (erneut) durch die Import-Pipeline.
 *
 * Ohne fileId: alle noch nicht verarbeiteten Echt-Dateien, aelteste zuerst.
 * Mit fileId: genau diese eine — auch is_test oder bereits verarbeitet
 * (Re-Run ist durch die Upsert-Semantik idempotent).
 * --dry-run: nur Plan-Zaehler ausgeben, nichts schreiben.
 */
class DispoReprocessCommand extends Command
{
    protected $signature = 'recruiting:dispo-reprocess {fileId?} {--dry-run}';
    protected $description = 'ZAS-Dispo-Rohdateien (erneut) verarbeiten';

    public function handle(ZasDispoWebexportImporter $importer): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $files = $this->argument('fileId') !== null
            ? RecZasDispoInboundFile::query()->whereKey((int) $this->argument('fileId'))->get()
            : RecZasDispoInboundFile::query()
                ->where('is_test', false)
                ->whereNull('processed_at')
                ->orderBy('id')
                ->get();

        if ($files->isEmpty()) {
            $this->info('Keine passenden Dateien.');
            return self::SUCCESS;
        }

        foreach ($files as $file) {
            $summary = $importer->import($file, $dryRun);
            $this->line(sprintf(
                '#%d %s %s: events %d/%d, assignments %d/%d, matched %d, offen %d, mehrdeutig %d, missing %d%s',
                $file->id,
                $file->original_filename ?: $file->uuid,
                $dryRun ? '[DRY-RUN]' : '',
                $summary['events_created'], $summary['events_updated'],
                $summary['assignments_created'], $summary['assignments_updated'],
                $summary['matched'], $summary['unmatched'], $summary['ambiguous'],
                $summary['missing_marked'],
                $summary['errors'] !== [] ? ' FEHLER: ' . implode(' | ', $summary['errors']) : ''
            ));
        }

        return self::SUCCESS;
    }
}
