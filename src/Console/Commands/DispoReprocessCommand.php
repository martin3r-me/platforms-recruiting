<?php

namespace Platform\Recruiting\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Platform\Recruiting\Jobs\ProcessZasDispoInboundFile;
use Platform\Recruiting\Models\RecZasDispoInboundFile;
use Platform\Recruiting\Services\Zas\Dispo\ZasDispoWebexportImporter;

/**
 * Schickt gespeicherte Dispo-Rohdateien (erneut) durch die Import-Pipeline.
 *
 * Ohne fileId: die anstehenden Echt-Dateien nach derselben Regel wie der
 * Hintergrund-Job (neuer als die letzte verarbeitete, aelteste zuerst) und
 * unter derselben Sperre — nie parallel zum Job. Liegengebliebene unterhalb
 * der letzten verarbeiteten Datei werden nur genannt, nie mitgenommen: sie
 * sind aelter als der Bestand und wuerden neuere Einbuchungen als verschwunden
 * markieren (Review 04.11.).
 * Mit fileId: genau diese eine — auch is_test, bereits verarbeitet oder
 * liegengeblieben (bewusste Entscheidung; eine aeltere Datei tauscht dabei
 * keine Person zurueck, siehe DispoPersonenwechsel, alles andere schon).
 * --dry-run: nur Plan-Zaehler ausgeben, nichts schreiben.
 */
class DispoReprocessCommand extends Command
{
    protected $signature = 'recruiting:dispo-reprocess {fileId?} {--dry-run}';
    protected $description = 'ZAS-Dispo-Rohdateien (erneut) verarbeiten';

    public function handle(ZasDispoWebexportImporter $importer): int
    {
        $dryRun = (bool) $this->option('dry-run');

        if ($this->argument('fileId') !== null) {
            $file = RecZasDispoInboundFile::query()->find((int) $this->argument('fileId'));
            if ($file === null) {
                $this->info('Keine passenden Dateien.');
                return self::SUCCESS;
            }
            $this->ausgabe($file, $importer->import($file, $dryRun), $dryRun);
            return self::SUCCESS;
        }

        $liegen = ProcessZasDispoInboundFile::liegengebliebene()->pluck('id')->all();
        if ($liegen !== []) {
            $this->warn(sprintf(
                'Liegengeblieben unterhalb der letzten verarbeiteten Datei (#%d): #%s — aelter als der Bestand, nur bewusst per ID nachholen.',
                ProcessZasDispoInboundFile::letzteVerarbeitete(),
                implode(', #', $liegen)
            ));
        }

        if ($dryRun) {
            $files = ProcessZasDispoInboundFile::anstehende()->get();
            if ($files->isEmpty()) {
                $this->info('Keine anstehenden Dateien.');
                return self::SUCCESS;
            }
            foreach ($files as $file) {
                $this->ausgabe($file, $importer->import($file, true), true);
            }
            return self::SUCCESS;
        }

        $lock = Cache::lock(ProcessZasDispoInboundFile::sperrSchluessel(), 1800);
        if (!$lock->get()) {
            $this->error('Der Hintergrund-Import laeuft gerade — spaeter erneut versuchen.');
            return self::FAILURE;
        }
        try {
            $anzahl = 0;
            ProcessZasDispoInboundFile::verarbeiten($importer, PHP_INT_MAX, null, function ($file, $summary) use (&$anzahl) {
                $anzahl++;
                $this->ausgabe($file, $summary, false);
            });
            if ($anzahl === 0) {
                $this->info('Keine anstehenden Dateien.');
            }
        } finally {
            $lock->release();
        }

        return self::SUCCESS;
    }

    private function ausgabe(RecZasDispoInboundFile $file, array $summary, bool $dryRun): void
    {
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

        if (($summary['personenwechsel_veraltet'] ?? 0) > 0) {
            $this->line(sprintf('    Personenwechsel uebersprungen (neuere Lieferung vorhanden): %d', $summary['personenwechsel_veraltet']));
        }
        if (($summary['personenwechsel'] ?? 0) > 0) {
            $this->line(sprintf(
                '    Personenwechsel (andere PNr in derselben Zeile): %d — %s',
                $summary['personenwechsel'],
                implode(' | ', array_slice($summary['personenwechsel_liste'] ?? [], 0, 10))
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
}
