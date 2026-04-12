<?php

namespace Platform\Recruiting\Console\Commands;

use Illuminate\Console\Command;
use Platform\Recruiting\Models\RecApplicant;
use Platform\Recruiting\Models\RecPhase;
use Platform\Recruiting\Models\RecPosition;

class SyncPhases extends Command
{
    protected $signature = 'recruiting:sync-phases
        {--dry-run : Nur anzeigen was passieren würde}';

    protected $description = 'Erstellt fehlende Default-Phasen für Positionen und weist Bewerber ohne Phase zu';

    public function handle(): int
    {
        $dryRun = $this->option('dry-run');

        if ($dryRun) {
            $this->info('[DRY-RUN] Keine Änderungen werden vorgenommen.');
        }

        // 1. Positionen ohne Phase → Default-Phase "Bewerbung" erstellen
        $positionsWithoutPhase = RecPosition::whereDoesntHave('phases')->get();

        if ($positionsWithoutPhase->isNotEmpty()) {
            $this->info("Positionen ohne Phase: {$positionsWithoutPhase->count()}");

            foreach ($positionsWithoutPhase as $position) {
                $this->line("  → {$position->title} (ID {$position->id}, Team {$position->team_id})");

                if (!$dryRun) {
                    $position->phases()->create([
                        'team_id' => $position->team_id,
                        'name' => 'Bewerbung',
                        'order' => 1,
                        'is_active' => true,
                        'auto_advance' => true,
                    ]);
                }
            }

            $this->info($dryRun ? 'Würde Phasen erstellen.' : 'Phasen erstellt.');
        } else {
            $this->info('Alle Positionen haben mindestens eine Phase.');
        }

        // 2. Aktive Bewerber ohne Phase → Phase 1 der primären Position zuweisen
        $applicantsWithoutPhase = RecApplicant::where('is_active', true)
            ->whereNull('rec_phase_id')
            ->with(['postings' => function ($q) {
                $q->orderBy('rec_applicant_posting.id');
            }])
            ->get();

        $assigned = 0;
        $skipped = 0;

        if ($applicantsWithoutPhase->isNotEmpty()) {
            $this->info("Aktive Bewerber ohne Phase: {$applicantsWithoutPhase->count()}");

            foreach ($applicantsWithoutPhase as $applicant) {
                $primaryPosting = $applicant->postings->first();

                if (!$primaryPosting) {
                    $skipped++;
                    continue;
                }

                $phase = RecPhase::where('rec_position_id', $primaryPosting->rec_position_id)
                    ->where('is_active', true)
                    ->orderBy('order')
                    ->first();

                if (!$phase) {
                    $this->warn("  ! Keine Phase für Position {$primaryPosting->rec_position_id} — Bewerber {$applicant->id} übersprungen");
                    $skipped++;
                    continue;
                }

                $contactName = $applicant->crmContactLinks->first()?->contact?->full_name ?? "ID {$applicant->id}";
                $this->line("  → {$contactName} → Phase \"{$phase->name}\" (Position {$phase->rec_position_id})");

                if (!$dryRun) {
                    $applicant->update(['rec_phase_id' => $phase->id]);
                }

                $assigned++;
            }

            $this->info($dryRun
                ? "Würde {$assigned} Bewerber zuweisen, {$skipped} übersprungen (kein Posting/keine Phase)."
                : "{$assigned} Bewerber zugewiesen, {$skipped} übersprungen.");
        } else {
            $this->info('Alle aktiven Bewerber haben bereits eine Phase.');
        }

        return self::SUCCESS;
    }
}
