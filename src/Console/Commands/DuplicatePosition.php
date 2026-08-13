<?php

namespace Platform\Recruiting\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Platform\Core\Models\CoreExtraFieldDefinition;
use Platform\Recruiting\Models\RecPhase;
use Platform\Recruiting\Models\RecPosition;

/**
 * Dupliziert eine Stelle inkl. aller Phasen, Extra-Felder und Konfigurationen
 * in eine neue Stelle. Postings, Channels, Schulungstermine und Bewerber
 * werden bewusst NICHT mitkopiert — die hängen am Live-Setup und müssen
 * pro neuer Stelle separat eingerichtet werden.
 *
 * Nutzt unsere klon-stabile Architektur:
 *  - Phasen-Order bleibt identisch (1..6)
 *  - Field-Overrides via options.required_in_phase_orders sind ID-frei
 *  - visibility_config referenziert Felder per name, nicht per ID
 *  → keine ID-Remapping-Logik nötig, options/visibility 1:1 mitkopiert.
 *
 * Aufruf:
 *   php artisan recruiting:duplicate-position --from-id=6 --to-title="Teststelle_Sandbox2"
 *   php artisan recruiting:duplicate-position --from-id=6 --to-title="Düsseldorf_v2" --team-id=3
 *   php artisan recruiting:duplicate-position --from-id=6 --to-title="x" --dry-run
 */
class DuplicatePosition extends Command
{
    protected $signature = 'recruiting:duplicate-position
        {--from-id= : ID der Quell-Position (ERFORDERLICH)}
        {--to-title= : Titel der neuen Position (ERFORDERLICH)}
        {--team-id= : Optional: Team-ID. Default: team der Quell-Position}
        {--dry-run : Nur anzeigen was passieren würde, keine DB-Writes}';

    protected $description = 'Dupliziert eine Stelle inkl. aller Phasen + Extra-Felder + Konfigurationen.';

    public function handle(): int
    {
        $fromId = (int) $this->option('from-id');
        $toTitle = trim((string) $this->option('to-title'));
        $teamIdOpt = $this->option('team-id');
        $dryRun = (bool) $this->option('dry-run');

        if ($fromId <= 0) {
            $this->error('--from-id ist erforderlich.');
            return Command::FAILURE;
        }
        if ($toTitle === '') {
            $this->error('--to-title ist erforderlich.');
            return Command::FAILURE;
        }

        $source = RecPosition::with('phases')->find($fromId);
        if (!$source) {
            $this->error("Quell-Position #{$fromId} nicht gefunden.");
            return Command::FAILURE;
        }

        $teamId = $teamIdOpt !== null ? (int) $teamIdOpt : (int) $source->team_id;

        $this->info("Quell-Position: #{$source->id} \"{$source->title}\" (team={$source->team_id})");
        $this->info("Ziel-Position:  \"{$toTitle}\" (team={$teamId})");
        $this->info("Phasen:         {$source->phases->count()}");

        $totalFields = 0;
        foreach ($source->phases as $phase) {
            $count = CoreExtraFieldDefinition::query()
                ->where('context_type', RecPhase::class)
                ->where('context_id', $phase->id)
                ->count();
            $totalFields += $count;
            $this->line(sprintf('  • Phase %d "%s" — %d Felder', $phase->order, $phase->name, $count));
        }
        $this->info("Felder gesamt:  {$totalFields}");

        if ($dryRun) {
            $this->warn('');
            $this->warn('DRY-RUN — keine DB-Writes. Abbruch.');
            return Command::SUCCESS;
        }

        try {
            $newPositionId = DB::transaction(function () use ($source, $toTitle, $teamId) {
                return $this->cloneIntoTransaction($source, $toTitle, $teamId);
            });
        } catch (\Throwable $e) {
            $this->error("Fehler beim Klonen: {$e->getMessage()}");
            return Command::FAILURE;
        }

        $this->info('');
        $this->info("✓ Neue Position angelegt: #{$newPositionId}");
        $this->info("  → recruiting/positions/{$newPositionId} im UI prüfen");

        return Command::SUCCESS;
    }

    private function cloneIntoTransaction(RecPosition $source, string $toTitle, int $teamId): int
    {
        // 1. Neue Position anlegen — Felder 1:1 ausser title/team/uuid
        $newPosition = RecPosition::create([
            'team_id' => $teamId,
            'title' => $toTitle,
            'description' => $source->description,
            'department' => $source->department,
            'location' => $source->location,
            // beschaftigungsort_lookup_value bewusst NICHT mitkopiert —
            // muss pro Ziel-Stelle individuell gesetzt werden, sonst greift
            // der Mapping-Schutz in maybeSwitchPosition nicht.
            'beschaftigungsort_lookup_value' => null,
            'hcm_job_title_id' => $source->hcm_job_title_id,
            'is_active' => $source->is_active,
            'auto_pilot_settings' => $source->auto_pilot_settings,
            'created_by_user_id' => $source->created_by_user_id,
            'owned_by_user_id' => $source->owned_by_user_id,
        ]);

        // RecPosition::booted() legt automatisch eine "Bewerbung"-Phase
        // an. Die löschen bevor wir die echten Phasen klonen, sonst
        // hätten wir Phase-Order-Konflikte.
        $newPosition->phases()->delete();

        // 2. Phasen klonen, Mapping aufbauen für Extra-Field-Klon
        $phaseIdMap = []; // [old_phase_id => new_phase_id]
        foreach ($source->phases as $sourcePhase) {
            $newPhase = RecPhase::create([
                'team_id' => $teamId,
                'rec_position_id' => $newPosition->id,
                'name' => $sourcePhase->name,
                'order' => $sourcePhase->order,
                'auto_pilot_settings' => $sourcePhase->auto_pilot_settings,
                'auto_advance' => $sourcePhase->auto_advance,
                'is_active' => $sourcePhase->is_active,
                'completion_type' => $sourcePhase->completion_type,
                'completion_config' => $sourcePhase->completion_config,
                'show_in_dashboard' => $sourcePhase->show_in_dashboard,
                // Ohne das haette die geklonte Stelle einen leeren
                // Buchungs-Dialog, ohne Fehlermeldung: der Schalter fiele auf
                // seinen Default false zurueck.
                'allow_manual_booking' => $sourcePhase->allow_manual_booking,
            ]);
            $phaseIdMap[$sourcePhase->id] = $newPhase->id;
        }

        // 3. Extra-Felder klonen — pro Phase. options bleiben 1:1 weil
        // required_in_phase_orders Order-basiert ist (klon-stabil).
        $totalCloned = 0;
        foreach ($phaseIdMap as $oldPhaseId => $newPhaseId) {
            $sourceFields = CoreExtraFieldDefinition::query()
                ->where('context_type', RecPhase::class)
                ->where('context_id', $oldPhaseId)
                ->orderBy('order')
                ->get();

            foreach ($sourceFields as $sourceField) {
                CoreExtraFieldDefinition::create([
                    'team_id' => $teamId,
                    'created_by_user_id' => $sourceField->created_by_user_id,
                    'context_type' => RecPhase::class,
                    'context_id' => $newPhaseId,
                    'name' => $sourceField->name,
                    'label' => $sourceField->label,
                    'description' => $sourceField->description,
                    'type' => $sourceField->type,
                    'is_required' => $sourceField->is_required,
                    'is_mandatory' => $sourceField->is_mandatory,
                    'is_encrypted' => $sourceField->is_encrypted,
                    'order' => $sourceField->order,
                    'options' => $sourceField->options,
                    'visibility_config' => $sourceField->visibility_config,
                    'verify_by_llm' => $sourceField->verify_by_llm,
                    'verify_instructions' => $sourceField->verify_instructions,
                    'auto_fill_source' => $sourceField->auto_fill_source,
                    'auto_fill_prompt' => $sourceField->auto_fill_prompt,
                ]);
                $totalCloned++;
            }
        }

        $this->info('');
        $this->info("Phasen geklont:    " . count($phaseIdMap));
        $this->info("Felder geklont:    {$totalCloned}");

        return $newPosition->id;
    }
}
