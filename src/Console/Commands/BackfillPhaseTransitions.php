<?php

namespace Platform\Recruiting\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Platform\Recruiting\Models\RecAutoPilotLog;
use Platform\Recruiting\Models\RecPhaseTransition;
use Platform\Recruiting\Services\Statistics\PhaseAdvancedSummaryParser;
use Platform\Recruiting\Support\PhaseTransitionTrigger;

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

        $stats = [
            'inserted' => 0, 'skipped_existing' => 0, 'skipped_live_window' => 0,
            'name_only' => 0, 'returned_name_only' => 0, 'unparseable' => 0,
        ];

        // Live-Cutoff gegen Doppelzaehlung: der Observer schreibt ab Deploy
        // Live-Transitions fuer dieselben vier Trigger-Stellen, die auch
        // phase_advanced/phase_returned loggen. source_log_id dedupliziert
        // nur backfill-gegen-backfill (Live-Zeilen haben kein source_log_id-
        // Gegenstueck) — ohne Cutoff wuerde jedes Ereignis nach dem Deploy
        // ZWEI Transitions bekommen (eine live, eine backfilled).
        // $cutoff = fruehester Live-Zeitpunkt dieses Teams: alles davor kann
        // der Observer unmoeglich geschrieben haben (er lief noch nicht),
        // ist also frei von Doppelzaehlung und wird normal backfilled.
        // Das schliesst die Duplizierung vollstaendig: jedes Log mit
        // created_at < cutoff liegt beweisbar vor dem ersten Live-Schreiben;
        // jedes Log mit created_at >= cutoff faellt in den Zeitraum, in dem
        // der Observer bereits aktiv live schreibt, und wird uebersprungen.
        // Als Nebeneffekt deckt das exakt das in Spec §8 akzeptierte Fenster
        // ab: zwischen Symlink-Switch und `migrate` faengt der Observer
        // seinen Schreibversuch per try/catch ab (Tabelle existiert noch
        // nicht) — diese allerersten Ereignisse haben KEINE Live-Zeile,
        // liegen also unter dem Cutoff und werden hier ganz normal
        // nachgezogen. Noch keine Live-Zeile fuer das Team vorhanden (frisch
        // deployt, noch kein Trigger gelaufen) → now() als Cutoff, dann ist
        // ohnehin alles im Log "vor Deploy".
        $cutoffRaw = RecPhaseTransition::query()
            ->where('team_id', $teamId)
            ->where('source', 'live')
            ->min('occurred_at');
        $cutoff = $cutoffRaw !== null ? Carbon::parse($cutoffRaw) : now();

        RecAutoPilotLog::query()
            ->whereIn('type', ['phase_advanced', 'phase_returned'])
            ->whereHas('applicant', fn ($q) => $q->where('team_id', $teamId))
            ->with('applicant:id,team_id')
            ->orderBy('id')
            ->chunkById(500, function ($logs) use ($dryRun, &$stats, $cutoff) {
                foreach ($logs as $log) {
                    if (RecPhaseTransition::where('source_log_id', $log->id)->exists()) {
                        $stats['skipped_existing']++;
                        continue;
                    }

                    if ($log->created_at >= $cutoff) {
                        $stats['skipped_live_window']++;
                        continue;
                    }

                    if ($log->type === 'phase_returned') {
                        $fromId = $log->details['from_phase_id'] ?? null;
                        $toId = $log->details['to_phase_id'] ?? null;
                        $names = $this->phaseNames([$fromId, $toId]);
                        // FK-sicher: nur uebernehmen, was phaseNames() auch
                        // aufloesen konnte. Eine inzwischen geloeschte Phase
                        // waere sonst eine tote FK und wuerde die
                        // Constraint-Verletzung den kompletten Command
                        // abbrechen lassen. Der Name-Snapshot bleibt nur
                        // erhalten, wenn die Phase noch existiert — fuer
                        // phase_returned gibt es (anders als bei
                        // phase_advanced ueber den Summary-Text) keine
                        // andere Quelle fuer den Namen.
                        $fromResolved = $fromId !== null && isset($names[$fromId]);
                        $toResolved = $toId !== null && isset($names[$toId]);
                        if (!$toResolved) {
                            $stats['returned_name_only']++; // Sichtbarkeit wie im phase_advanced-Zweig
                        }
                        $row = [
                            'from_phase_id' => $fromResolved ? $fromId : null,
                            'to_phase_id' => $toResolved ? $toId : null,
                            'from_phase_name' => $names[$fromId] ?? null,
                            'to_phase_name' => $names[$toId] ?? null,
                            'trigger' => PhaseTransitionTrigger::RETURNED,
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
                            'trigger' => str_starts_with((string) $log->summary, 'Manuell')
                                ? PhaseTransitionTrigger::MANUAL
                                : PhaseTransitionTrigger::AUTO_ADVANCE,
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
            . "uebersprungen (Live-Fenster): {$stats['skipped_live_window']}, "
            . "nur-Name (kein ID-Match): {$stats['name_only']}, "
            . "returned nur-Name (kein ID-Match): {$stats['returned_name_only']}, "
            . "unparsebar: {$stats['unparseable']}");

        return Command::SUCCESS;
    }

    /** @return array<int,string> id => name */
    private function phaseNames(array $ids): array
    {
        $ids = array_filter($ids);
        return $ids ? DB::table('rec_phases')->whereIn('id', $ids)->pluck('name', 'id')->all() : [];
    }

    /**
     * @return array<string,int> name => phase_id (Phasen der Stellen des Bewerbers)
     *
     * Bekannte, tolerierte Grenze: Phasen werden pro Stelle geklont, bei
     * Mehrfach-Bewerbung (mehrere Postings/Stellen desselben Bewerbers) sind
     * Namenskollisionen zwischen den geklonten Phasensaetzen der Regelfall.
     * pluck('ph.id', 'ph.name') behaelt bei Kollision die zuletzt gejointe
     * ID. Das Log selbst gibt nicht her, auf welche Stelle sich ein Eintrag
     * bezieht, und die Auswertung keyt ohnehin auf order/name statt auf die
     * exakte Phase-ID — daher hier bewusst nicht aufgeloest.
     */
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
