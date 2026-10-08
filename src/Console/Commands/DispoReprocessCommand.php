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

            if ($dryRun && ($summary['blocks_found'] ?? false)) {
                $this->line(sprintf(
                    '    geplant: %d Events, %d Einbuchungen',
                    $summary['stats']['events'] ?? 0,
                    $summary['stats']['assignments'] ?? 0
                ));
            }

            $q = $summary['qualifikationen'] ?? [];
            if ($q !== []) {
                $this->line(sprintf(
                    '    Qualifikationen: katalog %d, zeilen %d, pnr_gesamt %d, matched %d, unmatched %d, platzhalter %d, ohne_katalog %d, ohne_pnr %d, MA aktualisiert %d, unveraendert %d, Listenwerte neu %d',
                    $q['katalog'] ?? 0, $q['zeilen'] ?? 0, $q['pnr_gesamt'] ?? 0,
                    $q['matched'] ?? 0, $q['unmatched'] ?? 0, $q['platzhalter'] ?? 0,
                    $q['ohne_katalog'] ?? 0, $q['ohne_pnr'] ?? 0,
                    $q['employees_updated'] ?? 0, $q['employees_unchanged'] ?? 0,
                    $q['lookup_values_created'] ?? 0
                ));
                $fehler = $q['fehler'] ?? [];
                if ($fehler !== []) {
                    $this->error(sprintf(
                        '    QUALIFIKATIONEN-FEHLER (%d): %s',
                        count($fehler), implode(' | ', array_slice($fehler, 0, 5))
                    ));
                }
            }

            foreach (['unmatched_pnrs' => 'unbekannte PNr', 'ambiguous_pnrs' => 'mehrdeutige PNr'] as $key => $label) {
                $list = $summary[$key] ?? [];
                if ($list !== []) {
                    $this->line(sprintf(
                        '    %s (%d gesammelt, max 10 gezeigt): %s',
                        $label, count($list), implode(', ', array_slice($list, 0, 10))
                    ));
                }
            }
        }

        return self::SUCCESS;
    }
}
