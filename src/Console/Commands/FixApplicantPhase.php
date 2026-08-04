<?php

namespace Platform\Recruiting\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Platform\Recruiting\Models\RecApplicant;

/**
 * Backfill: heilt rec_phase_id für Bewerber, bei denen das Feld vom alten
 * UpdateApplicantTool versehentlich auf null gesetzt wurde.
 *
 * Hintergrund: vor dem Fix interpretierte das Tool `rec_phase_id: 0`
 * (OpenAI-Default) als "loeschen", wodurch jeder LLM-Enrichment-Run der
 * `recruiting.applicants.PUT` aufrief, die Phase nullte. Dadurch fielen
 * Bewerber aus dem Dashboard, hatten keine Phase-Inheritance und damit
 * leere Extra-Felder im UI.
 *
 * Logik:
 *   - Findet Bewerber mit rec_phase_id IS NULL UND mind. 1 Posting
 *   - Setzt rec_phase_id auf firstPhase() der primaeren Position
 *     (= erste verlinkte Posting via $primaryPosting->position->firstPhase())
 *   - Bewerber ohne Postings (Initiativ, Imports) bleiben unangefasst
 *
 * Aufruf:
 *   php artisan recruiting:fix-applicant-phase --dry-run
 *   php artisan recruiting:fix-applicant-phase
 *   php artisan recruiting:fix-applicant-phase --team-id=3
 *   php artisan recruiting:fix-applicant-phase --limit=10
 */
class FixApplicantPhase extends Command
{
    protected $signature = 'recruiting:fix-applicant-phase
        {--team-id= : Optional auf ein Team beschränken}
        {--dry-run : Nur anzeigen was geändert würde, nichts schreiben}
        {--limit=0 : Maximale Anzahl Bewerber pro Run (0 = alle)}';

    protected $description = 'Heilt rec_phase_id fuer Bewerber bei denen das LLM-Tool versehentlich Phase auf null gesetzt hat (alter Bug).';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $teamId = $this->option('team-id');
        $limit = max(0, (int) $this->option('limit'));

        if ($dryRun) {
            $this->warn('DRY-RUN — es wird nichts geschrieben.');
        }

        // Bewerber mit rec_phase_id IS NULL UND mind. 1 Posting
        $query = RecApplicant::query()
            ->whereNull('rec_phase_id')
            ->whereHas('postings')
            ->with(['postings.position.phases']);

        if ($teamId) {
            $query->where('team_id', (int) $teamId);
        }

        if ($limit > 0) {
            $query->limit($limit);
        }

        $checked = 0;
        $changed = 0;
        $skippedNoPosting = 0;
        $skippedNoFirstPhase = 0;
        $errors = 0;

        foreach ($query->cursor() as $applicant) {
            $checked++;

            // Primaere Position = erstes verlinktes Posting (gleiche
            // Heuristik wie IncomingApplicationService::handleInboundMessage)
            $primaryPosting = $applicant->postings->first();
            if (!$primaryPosting || !$primaryPosting->position) {
                $skippedNoPosting++;
                continue;
            }

            $firstPhase = $primaryPosting->position->firstPhase();
            if (!$firstPhase) {
                $this->line(sprintf(
                    ' #%-5d %-30s : Position "%s" hat KEINE active firstPhase → skip',
                    $applicant->id,
                    $this->displayName($applicant),
                    $primaryPosting->position->title,
                ));
                $skippedNoFirstPhase++;
                continue;
            }

            $this->line(sprintf(
                ' #%-5d %-30s : Phase NULL → %d (%s, Position "%s")',
                $applicant->id,
                $this->displayName($applicant),
                $firstPhase->id,
                $firstPhase->name,
                $primaryPosting->position->title,
            ));

            if (!$dryRun) {
                try {
                    DB::table('rec_applicants')
                        ->where('id', $applicant->id)
                        ->update(['rec_phase_id' => $firstPhase->id]);

                    try {
                        \Platform\Recruiting\Models\RecPhaseTransition::create([
                            'team_id'          => $applicant->team_id,
                            'rec_applicant_id' => $applicant->id,
                            'rec_position_id'  => $firstPhase->rec_position_id,
                            'from_phase_id'    => null, // Command heilt nur rec_phase_id IS NULL
                            'to_phase_id'      => $firstPhase->id,
                            'from_phase_name'  => null,
                            'to_phase_name'    => $firstPhase->name,
                            // Korrektur, kein Phasenwechsel — aus allen Verweildauer-Medianen
                            // ausgeschlossen (Spec §5)
                            'trigger'          => \Platform\Recruiting\Support\PhaseTransitionTrigger::FIX,
                            'source'           => 'live',
                            'occurred_at'      => now(),
                        ]);
                    } catch (\Throwable) {
                        // Transition-Fehler bricht die Heilung nicht ab
                    }

                    $changed++;
                } catch (\Throwable $e) {
                    $errors++;
                    $this->error(" Fehler bei #{$applicant->id}: {$e->getMessage()}");
                }
            } else {
                $changed++;
            }
        }

        $this->info('');
        $this->info("Geprüft:                       {$checked}");
        $this->info("Geändert (Phase gesetzt):      {$changed}" . ($dryRun ? ' (dry-run)' : ''));
        $this->info("Skipped (kein Posting):        {$skippedNoPosting}");
        $this->info("Skipped (keine firstPhase):    {$skippedNoFirstPhase}");
        if ($errors > 0) {
            $this->warn("Fehler:                        {$errors}");
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    private function displayName(RecApplicant $applicant): string
    {
        $contact = $applicant->crmContactLinks?->first()?->contact;
        return $contact?->full_name ?? "(Bewerber #{$applicant->id})";
    }
}
