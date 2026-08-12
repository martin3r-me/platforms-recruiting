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
            'unmatched_pnrs' => [], 'ambiguous_pnrs' => [],
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

            // Eine Lieferung ohne {Dispo}-Block darf den Zukunfts-Bestand nicht
            // als verschwunden markieren — missing-Erkennung nur, wenn ueberhaupt
            // Einbuchungen mitgeliefert wurden.
            $existingFuture = $dispoRows === []
                ? []
                : RecDispoAssignment::query()
                    ->where('datum', '>=', now()->toDateString())
                    ->whereNull('missing_since')
                    ->pluck('ds_ref')
                    ->all();

            $plan = $this->planner->plan($dispoRows, $dispo2Rows, $existingFuture, now()->toDateString());
            $summary['stats'] = $plan['stats'];

            $matcher = new ZasDispoMatcher($this->directory->map());

            if ($dryRun) {
                foreach ($plan['assignments'] as $dsRef => $attrs) {
                    if ($attrs['datum'] === null) {
                        $summary['errors'][] = "Assignment {$dsRef}: Datum unparsebar, uebersprungen";
                        continue;
                    }

                    if ($attrs['einsatz_ref'] === '' || !array_key_exists($attrs['einsatz_ref'], $plan['events'])) {
                        $summary['errors'][] = "Assignment {$dsRef}: keine Einsatz-ID — uebersprungen";
                        continue;
                    }

                    $m = $matcher->match($attrs['pnr_raw']);
                    self::tallyMatch($summary, $m, $attrs['pnr_raw']);
                }
                $summary['missing_marked'] = count($plan['missing_ds_refs']);

                // Rematch-Parity: dieselbe Nachzuegler-Zaehlung wie im Live-Lauf,
                // aber rein lesend und ohne die in dieser Lieferung bereits
                // gezaehlten Assignments doppelt zu betrachten.
                RecDispoAssignment::query()
                    ->whereNull('rec_employee_id')
                    ->whereNotIn('ds_ref', array_keys($plan['assignments']))
                    ->orderBy('id')
                    ->chunkById(500, function ($open) use ($matcher, &$summary) {
                        foreach ($open as $assignment) {
                            $m = $matcher->match($assignment->pnr_raw);
                            if ($m['employee_id'] !== null) {
                                $summary['rematched_open']++;
                            }
                        }
                    });

                return $summary;
            }

            DB::transaction(function () use ($plan, $matcher, &$summary) {
                $eventIds = [];
                foreach ($plan['events'] as $ref => $attrs) {
                    $isPlaceholder = $attrs['is_placeholder'] ?? false;
                    unset($attrs['is_placeholder']);

                    if ($isPlaceholder) {
                        // Platzhalter (nur {Dispo}, kein {Dispo2}) duerfen NIE
                        // bereits importierte Stammdaten (name/venue/ort/...)
                        // mit Null ueberschreiben — nur anlegen, nie updaten.
                        $event = RecDispoEvent::firstOrCreate(['einsatz_ref' => $ref], $attrs);
                        if ($event->wasRecentlyCreated) {
                            $summary['events_created']++;
                        }
                    } else {
                        $event = RecDispoEvent::updateOrCreate(['einsatz_ref' => $ref], $attrs);
                        $event->wasRecentlyCreated ? $summary['events_created']++ : $summary['events_updated']++;
                    }

                    $eventIds[$ref] = $event->id;
                }

                foreach ($plan['assignments'] as $dsRef => $attrs) {
                    $einsatzRef = $attrs['einsatz_ref'];
                    unset($attrs['einsatz_ref']);

                    if ($attrs['datum'] === null) {
                        $summary['errors'][] = "Assignment {$dsRef}: Datum unparsebar, uebersprungen";
                        continue;
                    }

                    if ($einsatzRef === '' || !isset($eventIds[$einsatzRef])) {
                        $summary['errors'][] = "Assignment {$dsRef}: keine Einsatz-ID — uebersprungen";
                        continue;
                    }

                    $m = $matcher->match($attrs['pnr_raw']);
                    self::tallyMatch($summary, $m, $attrs['pnr_raw']);

                    RecDispoAssignment::updateOrCreate(
                        ['ds_ref' => $dsRef],
                        $attrs + [
                            'rec_dispo_event_id' => $eventIds[$einsatzRef],
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

            // Die Daten sind an dieser Stelle bereits committet (Transaktion
            // ist durch). Scheitert nur noch dieses Update, bleibt processed_at
            // null -> naechster recruiting:dispo-reprocess-Lauf holt es idempotent
            // nach (Upsert-Semantik). Nur loggen, HTTP-Antwort bleibt unberuehrt.
            try {
                $file->update([
                    'detected_format' => 'blocks',
                    'parse_status'    => 'processed',
                    'processed_at'    => now(),
                    'notes'           => json_encode($summary, JSON_UNESCAPED_UNICODE),
                ]);
            } catch (\Throwable $e) {
                Log::error('ZAS dispo import: Status-Update nach erfolgreichem Import fehlgeschlagen', [
                    'file_id' => $file->id, 'error' => $e->getMessage(),
                ]);
            }
        } catch (\Throwable $e) {
            $summary['errors'][] = $e->getMessage();
            Log::error('ZAS dispo import fehlgeschlagen', ['file_id' => $file->id, 'error' => $e->getMessage()]);
            if (!$dryRun) {
                // Die Zaehler oben liefen bereits mit, aber ein Fehler in der
                // DB::transaction() rollt alle Writes zurueck — die In-Memory-
                // Zaehler wuerden sonst erfolgreiche Schreibvorgaenge vorspiegeln.
                foreach (['events_created', 'events_updated', 'assignments_created', 'assignments_updated', 'missing_marked', 'rematched_open'] as $k) {
                    $summary[$k] = 0;
                }
                $summary['rolled_back'] = true;

                try {
                    $file->update([
                        'parse_status' => 'failed',
                        'processed_at' => now(),
                        'notes'        => json_encode($summary, JSON_UNESCAPED_UNICODE),
                    ]);
                } catch (\Throwable $e2) {
                    Log::error('ZAS dispo import: Status-Update nach fehlgeschlagenem Import fehlgeschlagen', [
                        'file_id' => $file->id, 'error' => $e2->getMessage(),
                    ]);
                }
            }
        }

        return $summary;
    }

    /** @param array{employee_id: ?int, reason: string} $match */
    private static function tallyMatch(array &$summary, array $match, ?string $pnrRaw): void
    {
        if ($match['employee_id'] !== null) {
            $summary['matched']++;
            return;
        }

        if ($match['reason'] === 'ambiguous') {
            $summary['ambiguous']++;
            self::recordPnr($summary['ambiguous_pnrs'], $pnrRaw);
            return;
        }

        $summary['unmatched']++;
        self::recordPnr($summary['unmatched_pnrs'], $pnrRaw);
    }

    /** @param list<string> $list */
    private static function recordPnr(array &$list, ?string $pnrRaw): void
    {
        $pnr = trim((string) $pnrRaw);
        if ($pnr === '') {
            return;
        }

        if (count($list) < 50 && !in_array($pnr, $list, true)) {
            $list[] = $pnr;
        }
    }
}
