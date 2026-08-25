<?php

namespace Platform\Recruiting\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Platform\Recruiting\Models\RecZasInboundFile;
use Platform\Recruiting\Services\Zas\ZasInboundCsvParser;
use Platform\Recruiting\Services\Zas\ZasInboundEmployeeImporter;

/**
 * Schickt gespeicherte ZAS-Mitarbeiter-Lieferungen (erneut) durch den Import.
 *
 * Warum es das braucht (Befund Massenimport 2026-08-25): die Rohdateien liegen
 * bei uns, aber es gab keinen Weg, sie noch einmal zu verarbeiten. Folgen:
 * keine Generalprobe ohne Tinker, kein zweiter Versuch aus eigener Kraft (ZAS
 * musste erneut senden), und kein Weg, nach einer Code-Aenderung die schon
 * gelieferten Zeilen nachzuziehen.
 *
 * Ein Nachlauf ist gefahrlos wiederholbar: die Match-Kaskade des Importers
 * erkennt bestehende MA ueber UUID oder Personalnummer und legt sie nicht
 * erneut an.
 *
 * Ohne fileId: alle noch nicht verarbeiteten Echt-Lieferungen, aelteste zuerst
 * (deckt insbesondere Lieferungen ab, die der Groessen-Waechter abgewiesen hat).
 * Mit fileId: genau diese eine, auch is_test oder bereits verarbeitet.
 *
 * Aufruf:
 *   php artisan recruiting:zas-inbound-reprocess 17 --dry-run
 *   php artisan recruiting:zas-inbound-reprocess 17
 *   php artisan recruiting:zas-inbound-reprocess --chunk=50
 */
class ZasInboundReprocess extends Command
{
    protected $signature = 'recruiting:zas-inbound-reprocess
                            {fileId? : ID aus rec_zas_inbound_files; ohne Angabe alle unverarbeiteten}
                            {--dry-run : nur rechnen und berichten, nichts schreiben}
                            {--chunk=100 : Zeilen pro Portion}';

    protected $description = 'Gespeicherte ZAS-Mitarbeiter-Lieferungen (erneut) verarbeiten';

    public function __construct(
        private ZasInboundEmployeeImporter $importer,
        private ZasInboundCsvParser $parser,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $chunk  = max(1, (int) $this->option('chunk'));

        $files = $this->argument('fileId') !== null
            ? RecZasInboundFile::query()->whereKey((int) $this->argument('fileId'))->get()
            : RecZasInboundFile::query()
                ->where('is_test', false)
                ->whereNull('processed_at')
                ->orderBy('id')
                ->get();

        if ($files->isEmpty()) {
            $this->info('Keine passenden Lieferungen.');

            return self::SUCCESS;
        }

        foreach ($files as $file) {
            try {
                $content = (string) Storage::disk((string) $file->disk)->get((string) $file->stored_path);
            } catch (\Throwable $e) {
                $this->error("#{$file->id}: Rohdatei nicht lesbar ({$file->stored_path}): {$e->getMessage()}");
                continue;
            }

            $summary = $this->reprocess($file, $content, $dryRun, $chunk);

            $this->line(sprintf(
                '#%d %s%s: %d Zeilen — angelegt %d, aktualisiert %d (davon PersNr nachgetragen %d),'
                . ' uebersprungen %d, fehlgeschlagen %d, Warnungen %d, Dublettenverdacht %d',
                $file->id,
                $file->original_filename ?: (string) $file->uuid,
                $dryRun ? ' [TROCKENLAUF]' : '',
                $summary['rows'],
                $summary['created'],
                $summary['updated'],
                $summary['pnr_filled'],
                $summary['skipped'],
                $summary['failed'],
                $summary['warnings'],
                $summary['suspected'],
            ));

            foreach ($summary['failed_details'] as $f) {
                $this->warn('    FEHLER ' . ($f['personnel_number'] ?? '-') . ': ' . $f['reason']);
            }
            foreach ($summary['suspected_details'] as $s) {
                $treffer = array_map(
                    fn ($m) => $m['field'] . '=MA#' . $m['employee_id'] . ' (' . $m['confidence'] . ')',
                    $s['matches']
                );
                $this->warn('    VERDACHT ' . ($s['personnel_number'] ?? '-') . ' ' . $s['name']
                    . ' → ' . implode(', ', $treffer));
            }
        }

        return self::SUCCESS;
    }

    /**
     * Der testbare Kern: Inhalt rein, Bilanz raus.
     *
     * Der Storage-Zugriff bleibt bewusst in handle() — so laesst sich das
     * Verhalten ohne Dateisystem pruefen (Muster: DispoEscalateCommand).
     *
     * @return array{rows:int, created:int, updated:int, pnr_filled:int, skipped:int,
     *               failed:int, warnings:int, suspected:int,
     *               failed_details:list<array>, suspected_details:list<array>}
     */
    public function reprocess(RecZasInboundFile $file, string $content, bool $dryRun, int $chunk): array
    {
        $parsed = $this->parser->parse($content);

        $summary = [
            'rows' => 0, 'created' => 0, 'updated' => 0, 'pnr_filled' => 0,
            'skipped' => 0, 'failed' => 0, 'warnings' => 0, 'suspected' => 0,
            'failed_details' => [], 'suspected_details' => [],
        ];

        // In Portionen, damit auch eine grosse (z.B. vom Waechter abgewiesene)
        // Lieferung durchlaeuft, ohne alles gleichzeitig im Speicher zu halten.
        foreach (array_chunk($parsed['rows'], max(1, $chunk)) as $portion) {
            $report = $this->importer->import($portion, $file, $dryRun);

            $summary['rows']      += count($portion);
            $summary['created']   += count($report['created']);
            $summary['updated']   += count($report['updated']);
            $summary['skipped']   += count($report['skipped']);
            $summary['failed']    += count($report['failed']);
            $summary['warnings']  += count($report['warnings']);
            $summary['suspected'] += count($report['suspected']);

            foreach ($report['updated'] as $u) {
                if (in_array('personnel_number', $u['changed'] ?? [], true)) {
                    $summary['pnr_filled']++;
                }
            }

            $summary['failed_details']    = array_merge($summary['failed_details'], $report['failed']);
            $summary['suspected_details'] = array_merge($summary['suspected_details'], $report['suspected']);
        }

        Log::info('ZAS-Inbound Reprocess', [
            'inbound_file_id' => $file->id,
            'dry_run'         => $dryRun,
            'summary'         => array_diff_key($summary, ['failed_details' => 1, 'suspected_details' => 1]),
        ]);

        // Den Bericht einer bereits verarbeiteten Lieferung NICHT ueberschreiben:
        // er belegt, was damals passiert ist. Nur eine Lieferung, die noch nie
        // durchgelaufen ist (z.B. vom Groessen-Waechter abgewiesen), wird hier
        // erstmals als verarbeitet gestempelt.
        if (!$dryRun && $file->processed_at === null) {
            $file->update([
                'status'       => $summary['failed'] > 0
                    ? ($summary['created'] + $summary['updated'] + $summary['skipped'] > 0 ? 'partial' : 'failed')
                    : 'processed',
                'processed_at' => now(),
                'notes'        => json_encode([
                    'reprocessed' => true,
                    'created'     => $summary['created'],
                    'updated'     => $summary['updated'],
                    'pnr_filled'  => $summary['pnr_filled'],
                    'skipped'     => $summary['skipped'],
                    'failed'      => $summary['failed_details'],
                    'suspected'   => $summary['suspected_details'],
                ], JSON_UNESCAPED_UNICODE),
            ]);
        }

        return $summary;
    }
}
