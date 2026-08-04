<?php

namespace Platform\Recruiting\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Platform\Recruiting\Models\RecAutoPilotLog;
use Platform\Recruiting\Models\RecPhaseTransition;
use Platform\Recruiting\Services\Statistics\PhaseAdvancedSummaryParser;

/**
 * Backfill der Phasen-Historie aus rec_auto_pilot_logs (Spec §5).
 * - from wird NIE abgeleitet (nur Format A liefert es woertlich)
 * - Nicht-Treffer landen als to_phase_name mit to_phase_id = NULL
 * - Idempotent via source_log_id UNIQUE
 */
class BackfillPhaseTransitions extends Command
{
    protected $signature = 'recruiting:backfill-phase-transitions {--team= : Team-ID (Pflicht)} {--dry-run : Nur zaehlen, nichts schreiben}';

    protected $description = 'Backfill rec_phase_transitions aus phase_advanced/phase_returned Logs.';

    public function handle(): int
    {
        $teamId = (int) $this->option('team');
        if (!$teamId) {
            $this->error('--team= ist Pflicht.');
            return Command::FAILURE;
        }
        $dryRun = (bool) $this->option('dry-run');

        $stats = ['inserted' => 0, 'skipped_existing' => 0, 'name_only' => 0, 'unparseable' => 0];

        RecAutoPilotLog::query()
            ->whereIn('type', ['phase_advanced', 'phase_returned'])
            ->whereHas('applicant', fn ($q) => $q->where('team_id', $teamId))
            ->with('applicant:id,team_id')
            ->orderBy('id')
            ->chunkById(500, function ($logs) use ($dryRun, &$stats) {
                foreach ($logs as $log) {
                    if (RecPhaseTransition::where('source_log_id', $log->id)->exists()) {
                        $stats['skipped_existing']++;
                        continue;
                    }

                    if ($log->type === 'phase_returned') {
                        $fromId = $log->details['from_phase_id'] ?? null;
                        $toId = $log->details['to_phase_id'] ?? null;
                        $names = $this->phaseNames([$fromId, $toId]);
                        $row = [
                            'from_phase_id' => $fromId, 'to_phase_id' => $toId,
                            'from_phase_name' => $names[$fromId] ?? null,
                            'to_phase_name' => $names[$toId] ?? null,
                            'trigger' => 'returned',
                        ];
                    } else {
                        $parsed = PhaseAdvancedSummaryParser::parse((string) $log->summary);
                        if ($parsed === null) {
                            $stats['unparseable']++;
                            continue;
                        }
                        // Match NUR gegen Phasen der Stellen dieses Bewerbers (Spec §5)
                        $candidates = $this->applicantPhaseIdsByName($log->rec_applicant_id);
                        $toId = $candidates[$parsed['to']] ?? null;
                        $fromId = $parsed['from'] !== null ? ($candidates[$parsed['from']] ?? null) : null;
                        if ($toId === null) {
                            $stats['name_only']++; // Nicht-Treffer NICHT wegwerfen (Spec §5)
                        }
                        $row = [
                            'from_phase_id' => $fromId, 'to_phase_id' => $toId,
                            'from_phase_name' => $parsed['from'], 'to_phase_name' => $parsed['to'],
                            'trigger' => str_starts_with((string) $log->summary, 'Manuell') ? 'manual' : 'auto_advance',
                        ];
                    }

                    if (!$dryRun) {
                        RecPhaseTransition::create($row + [
                            'team_id' => $log->applicant->team_id,
                            'rec_applicant_id' => $log->rec_applicant_id,
                            'rec_position_id' => $row['to_phase_id']
                                ? DB::table('rec_phases')->where('id', $row['to_phase_id'])->value('rec_position_id')
                                : null,
                            'source' => 'backfill',
                            'source_log_id' => $log->id,
                            'occurred_at' => $log->created_at,
                        ]);
                    }
                    $stats['inserted']++;
                }
            });

        $this->info(($dryRun ? '[DRY-RUN] ' : '')
            . "eingefuegt: {$stats['inserted']}, uebersprungen (existiert): {$stats['skipped_existing']}, "
            . "nur-Name (kein ID-Match): {$stats['name_only']}, unparsebar: {$stats['unparseable']}");

        return Command::SUCCESS;
    }

    /** @return array<int,string> id => name */
    private function phaseNames(array $ids): array
    {
        $ids = array_filter($ids);
        return $ids ? DB::table('rec_phases')->whereIn('id', $ids)->pluck('name', 'id')->all() : [];
    }

    /** @return array<string,int> name => phase_id (Phasen der Stellen des Bewerbers) */
    private function applicantPhaseIdsByName(int $applicantId): array
    {
        return DB::table('rec_applicant_posting as ap')
            ->join('rec_postings as po', 'po.id', '=', 'ap.rec_posting_id')
            ->join('rec_phases as ph', 'ph.rec_position_id', '=', 'po.rec_position_id')
            ->where('ap.rec_applicant_id', $applicantId)
            ->pluck('ph.id', 'ph.name')
            ->all();
    }
}
