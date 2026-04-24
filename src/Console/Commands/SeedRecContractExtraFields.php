<?php

namespace Platform\Recruiting\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class SeedRecContractExtraFields extends Command
{
    protected $signature = 'recruiting:seed-rec-contract-extra-fields
        {--dry-run : Nur anzeigen was passieren würde, keine Writes}';

    protected $description = 'Legt die Extra-Field-Definitions vertragsbeginn + vertragsende auf rec_contract-Kontext an (für jedes Team das bereits rec_contract_templates hat). Idempotent via Unique-Key (team_id, context_type, context_id, name).';

    private const CONTEXT_TYPE = 'Platform\\Recruiting\\Models\\RecContract';

    private const DEFINITIONS = [
        [
            'name'        => 'vertragsbeginn',
            'label'       => 'Vertragsbeginn',
            'type'        => 'date',
            'is_required' => true,
            'order'       => 10,
        ],
        [
            'name'        => 'vertragsende',
            'label'       => 'Vertragsende',
            'type'        => 'date',
            'is_required' => true,
            'order'       => 20,
        ],
    ];

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $this->components->info('Seed rec_contract Extra-Field Definitions');

        if ($dryRun) {
            $this->warn('[DRY-RUN] Keine Änderungen werden vorgenommen.');
        }
        $this->newLine();

        if (!Schema::hasTable('core_extra_field_definitions') || !Schema::hasTable('rec_contract_templates')) {
            $this->error('Benötigte Tabellen existieren nicht.');
            return self::FAILURE;
        }

        $teamIds = DB::table('rec_contract_templates')
            ->whereNull('deleted_at')
            ->distinct()
            ->pluck('team_id');

        if ($teamIds->isEmpty()) {
            $this->warn('Keine Teams mit rec_contract_templates gefunden — nichts zu seeden.');
            return self::SUCCESS;
        }

        $this->line("Teams mit rec_contract_templates: " . $teamIds->implode(', '));
        $this->newLine();

        $created = 0;
        $skipped = 0;
        $errors = 0;

        foreach ($teamIds as $teamId) {
            foreach (self::DEFINITIONS as $def) {
                $existing = DB::table('core_extra_field_definitions')
                    ->where('team_id', $teamId)
                    ->whereIn('context_type', [self::CONTEXT_TYPE, 'rec_contract'])
                    ->whereNull('context_id')
                    ->where('name', $def['name'])
                    ->first();

                if ($existing) {
                    $this->line("  ⏭ [team={$teamId}] \"{$def['name']}\" — existiert bereits (#{$existing->id}, context_type='{$existing->context_type}').");
                    $skipped++;
                    continue;
                }

                $this->line("  ✚ [team={$teamId}] \"{$def['name']}\" ({$def['label']}, type={$def['type']}, required=" . ($def['is_required'] ? 'yes' : 'no') . ") — neu anlegen.");

                if ($dryRun) {
                    $created++;
                    continue;
                }

                try {
                    DB::table('core_extra_field_definitions')->insert([
                        'team_id'            => $teamId,
                        'created_by_user_id' => null,
                        'context_type'       => self::CONTEXT_TYPE,
                        'context_id'         => null,
                        'name'               => $def['name'],
                        'label'              => $def['label'],
                        'type'               => $def['type'],
                        'is_required'        => (bool) $def['is_required'],
                        'is_encrypted'       => false,
                        'order'              => (int) $def['order'],
                        'options'            => null,
                        'created_at'         => now(),
                        'updated_at'         => now(),
                    ]);
                    $created++;
                } catch (\Throwable $e) {
                    $this->error("     ✗ Fehler beim Anlegen von \"{$def['name']}\" für team={$teamId}: {$e->getMessage()}");
                    $errors++;
                }
            }
        }

        $this->newLine();
        $this->components->bulletList([
            "Neu angelegt:     {$created}",
            "Bereits da:       {$skipped}",
            "Fehler:           {$errors}",
        ]);

        if ($errors > 0) {
            return self::FAILURE;
        }

        if ($dryRun) {
            $this->newLine();
            $this->warn('[DRY-RUN] Keine Writes ausgeführt. Ohne --dry-run erneut laufen lassen um tatsächlich zu seeden.');
        }

        return self::SUCCESS;
    }
}
