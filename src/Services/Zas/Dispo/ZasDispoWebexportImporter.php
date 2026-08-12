<?php

namespace Platform\Recruiting\Services\Zas\Dispo;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Platform\Recruiting\Models\RecDispoAssignment;
use Platform\Recruiting\Models\RecDispoEvent;
use Platform\Recruiting\Models\RecZasDispoInboundFile;
use Platform\Recruiting\Support\CsvEncodingNormalizer;

/**
 * Fuehrt den Import-Plan einer Webexport-Datei aus (duenner DB-Rand —
 * alle Logik steckt in den puren Klassen Splitter/FieldParser/Matcher/
 * Planner). Transaktion pro Datei; Fehler setzen parse_status=failed und
 * lassen die Rohdatei unangetastet (Reprocess jederzeit moeglich).
 *
 * dry_run: kompletter Plan inkl. Matching-Zahlen, aber KEINE Writes.
 */
class ZasDispoWebexportImporter
{
    public function __construct(
        private ZasDispoBlockSplitter $splitter,
        private ZasDispoImportPlanner $planner,
        private DispoEmployeeDirectory $directory,
    ) {}

    /** @return array<string, mixed> Summary */
    public function import(RecZasDispoInboundFile $file, bool $dryRun = false): array
    {
        $summary = [
            'blocks_found' => false,
            'events_created' => 0, 'events_updated' => 0,
            'assignments_created' => 0, 'assignments_updated' => 0,
            'matched' => 0, 'unmatched' => 0, 'ambiguous' => 0,
            'missing_marked' => 0, 'rematched_open' => 0,
            'skipped' => [], 'errors' => [],
        ];

        try {
            $raw  = (string) Storage::disk($file->disk)->get($file->stored_path);
            $utf8 = CsvEncodingNormalizer::toUtf8($raw);

            $split = $this->splitter->split($utf8);
            $summary['skipped'] = $split['skipped'];

            $dispoRows  = $split['known']['Dispo'] ?? [];
            $dispo2Rows = $split['known']['Dispo2'] ?? [];

            if ($dispoRows === [] && $dispo2Rows === []) {
                // Kein bekannter Block — Datei gilt als verarbeitet (0-Zaehler).
                if (!$dryRun) {
                    $file->update([
                        'parse_status' => 'processed',
                        'processed_at' => now(),
                        'notes'        => json_encode($summary, JSON_UNESCAPED_UNICODE),
                    ]);
                }
                return $summary;
            }

            $summary['blocks_found'] = true;

            $existingFuture = RecDispoAssignment::query()
                ->whereDate('datum', '>=', now()->toDateString())
                ->whereNull('missing_since')
                ->pluck('ds_ref')
                ->all();

            $plan = $this->planner->plan($dispoRows, $dispo2Rows, $existingFuture, now()->toDateString());
            $summary['stats'] = $plan['stats'];

            $matcher = new ZasDispoMatcher($this->directory->map());

            if ($dryRun) {
                foreach ($plan['assignments'] as $attrs) {
                    $m = $matcher->match($attrs['pnr_raw']);
                    $summary[$m['employee_id'] !== null ? 'matched' : ($m['reason'] === 'ambiguous' ? 'ambiguous' : 'unmatched')]++;
                }
                $summary['missing_marked'] = count($plan['missing_ds_refs']);
                return $summary;
            }

            DB::transaction(function () use ($plan, $matcher, &$summary) {
                $eventIds = [];
                foreach ($plan['events'] as $ref => $attrs) {
                    $event = RecDispoEvent::updateOrCreate(['einsatz_ref' => $ref], $attrs);
                    $eventIds[$ref] = $event->id;
                    $event->wasRecentlyCreated ? $summary['events_created']++ : $summary['events_updated']++;
                }

                foreach ($plan['assignments'] as $dsRef => $attrs) {
                    $einsatzRef = $attrs['einsatz_ref'];
                    unset($attrs['einsatz_ref']);

                    if ($attrs['datum'] === null) {
                        $summary['errors'][] = "Assignment {$dsRef}: Datum unparsebar, uebersprungen";
                        continue;
                    }

                    $m = $matcher->match($attrs['pnr_raw']);
                    $summary[$m['employee_id'] !== null ? 'matched' : ($m['reason'] === 'ambiguous' ? 'ambiguous' : 'unmatched')]++;

                    RecDispoAssignment::updateOrCreate(
                        ['ds_ref' => $dsRef],
                        $attrs + [
                            'rec_dispo_event_id' => $eventIds[$einsatzRef] ?? null,
                            'rec_employee_id'    => $m['employee_id'],
                            'last_seen_at'       => now(),
                            'missing_since'      => null,
                        ]
                    )->wasRecentlyCreated ? $summary['assignments_created']++ : $summary['assignments_updated']++;
                }

                if ($plan['missing_ds_refs'] !== []) {
                    $summary['missing_marked'] = RecDispoAssignment::query()
                        ->whereIn('ds_ref', $plan['missing_ds_refs'])
                        ->whereNull('missing_since')
                        ->update(['missing_since' => now()]);
                }

                // Nachzuegler-Matching: ALLE offenen Zuordnungen erneut versuchen
                // (deckt Assignments ab, die nicht Teil dieser Lieferung waren).
                RecDispoAssignment::query()
                    ->whereNull('rec_employee_id')
                    ->orderBy('id')
                    ->chunkById(500, function ($open) use ($matcher, &$summary) {
                        foreach ($open as $assignment) {
                            $m = $matcher->match($assignment->pnr_raw);
                            if ($m['employee_id'] !== null) {
                                $assignment->update(['rec_employee_id' => $m['employee_id']]);
                                $summary['rematched_open']++;
                            }
                        }
                    });
            });

            $file->update([
                'detected_format' => 'blocks',
                'parse_status'    => 'processed',
                'processed_at'    => now(),
                'notes'           => json_encode($summary, JSON_UNESCAPED_UNICODE),
            ]);
        } catch (\Throwable $e) {
            $summary['errors'][] = $e->getMessage();
            Log::error('ZAS dispo import fehlgeschlagen', ['file_id' => $file->id, 'error' => $e->getMessage()]);
            if (!$dryRun) {
                $file->update([
                    'parse_status' => 'failed',
                    'processed_at' => now(),
                    'notes'        => json_encode($summary, JSON_UNESCAPED_UNICODE),
                ]);
            }
        }

        return $summary;
    }
}
