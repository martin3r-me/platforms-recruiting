<?php

namespace Platform\Recruiting\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CopyHcmContractExtraFields extends Command
{
    protected $signature = 'recruiting:copy-hcm-contract-extra-fields
        {--dry-run : Nur anzeigen was passieren würde, keine Writes}
        {--detail : Pro Definition alle Scalar-Felder ausgeben}';

    protected $description = 'Kopiert core_extra_field_definitions mit context_type=hcm_onboarding_contract nach context_type=rec_contract. Idempotent (Dedup per Unique-Key team_id+context_type+context_id+name). Skippt Definitions mit context_id != NULL — die referenzieren HCM-spezifische Entities und können nicht blind auf rec_contract übertragen werden.';

    // HasExtraFields-Trait in platforms-core nutzt get_class($this) für den
    // Definition-Lookup — die echten Definitions stehen also unter dem FQCN.
    // Der Morph-Alias wird defensiv mitgesucht falls etwas historisch anders
    // angelegt wurde.
    private const HCM_CONTEXT_TYPES_SEARCH = [
        'Platform\\Hcm\\Models\\HcmOnboardingContract', // primary (FQCN)
        'hcm_onboarding_contract',                      // fallback (morph alias)
    ];
    private const REC_CONTEXT_TYPE_WRITE = 'Platform\\Recruiting\\Models\\RecContract';
    private const REC_CONTEXT_TYPES_DEDUP = [
        'Platform\\Recruiting\\Models\\RecContract',
        'rec_contract',
    ];

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $detail = (bool) $this->option('detail');

        $this->components->info('Copy HCM Contract Extra-Field Definitions → Recruiting');

        if ($dryRun) {
            $this->warn('[DRY-RUN] Keine Änderungen werden vorgenommen.');
        }
        $this->newLine();

        if (!Schema::hasTable('core_extra_field_definitions')) {
            $this->error('Tabelle core_extra_field_definitions existiert nicht.');
            return self::FAILURE;
        }

        $sources = DB::table('core_extra_field_definitions')
            ->whereIn('context_type', self::HCM_CONTEXT_TYPES_SEARCH)
            ->orderBy('team_id')
            ->orderBy('order')
            ->orderBy('id')
            ->get();

        $searchList = implode(' | ', array_map(fn($t) => "'{$t}'", self::HCM_CONTEXT_TYPES_SEARCH));
        $this->line("Gefundene HCM-Definitions (context_type IN [{$searchList}]): {$sources->count()}");

        if ($sources->isEmpty()) {
            $this->newLine();
            $this->warn('Keine HCM-Definitions gefunden. Kurze Diagnose — alle context_types aktuell in core_extra_field_definitions:');
            $diag = DB::table('core_extra_field_definitions')
                ->select('context_type', DB::raw('COUNT(*) as cnt'))
                ->groupBy('context_type')
                ->orderByDesc('cnt')
                ->get();
            foreach ($diag as $row) {
                $this->line("    {$row->context_type}  ({$row->cnt})");
            }
            if ($diag->isEmpty()) {
                $this->line('    (Tabelle ist komplett leer)');
            }
        }
        $this->newLine();

        $copied = 0;
        $skipped = 0;
        $skippedContextId = 0;
        $errors = 0;

        foreach ($sources as $src) {
            if ($src->context_id !== null) {
                $this->line("  ⚠ [team={$src->team_id}] \"{$src->name}\" — context_id={$src->context_id} (HCM-spezifisch), übersprungen.");
                if ($detail) {
                    $this->printDetail($src, null);
                }
                $skippedContextId++;
                continue;
            }

            $existing = DB::table('core_extra_field_definitions')
                ->where('team_id', $src->team_id)
                ->whereIn('context_type', self::REC_CONTEXT_TYPES_DEDUP)
                ->whereNull('context_id')
                ->where('name', $src->name)
                ->first();

            if ($existing) {
                $this->line("  ⏭ [team={$src->team_id}] \"{$src->name}\" — existiert bereits (#{$existing->id}, context_type='{$existing->context_type}'), übersprungen.");
                $skipped++;
                continue;
            }

            $this->line("  ✚ [team={$src->team_id}] \"{$src->name}\" (type={$src->type}, src_context_type='{$src->context_type}') — neu anlegen (HCM-Quelle #{$src->id}).");

            if ($detail) {
                $this->printDetail($src, self::REC_CONTEXT_TYPE_WRITE);
            }

            if ($dryRun) {
                $copied++;
                continue;
            }

            try {
                DB::table('core_extra_field_definitions')->insert([
                    'team_id'            => $src->team_id,
                    'created_by_user_id' => $src->created_by_user_id,
                    'context_type'       => self::REC_CONTEXT_TYPE_WRITE,
                    'context_id'         => null,
                    'name'               => $src->name,
                    'label'              => $src->label,
                    'type'               => $src->type,
                    'is_required'        => (bool) $src->is_required,
                    'is_encrypted'       => (bool) $src->is_encrypted,
                    'order'              => (int) $src->order,
                    'options'            => $src->options,
                    'created_at'         => $src->created_at,
                    'updated_at'         => now(),
                ]);
                $copied++;
            } catch (\Throwable $e) {
                $this->error("     ✗ Fehler bei HCM-#{$src->id}: {$e->getMessage()}");
                $errors++;
            }
        }

        $this->newLine();
        $this->components->bulletList([
            "Kopiert:                       {$copied}",
            "Übersprungen (existiert):      {$skipped}",
            "Übersprungen (hat context_id): {$skippedContextId}",
            "Fehler:                        {$errors}",
        ]);

        if ($skippedContextId > 0) {
            $this->newLine();
            $this->warn("⚠ {$skippedContextId} Definition(s) mit gesetzter context_id wurden übersprungen. Die referenzieren HCM-spezifische Entities (z.B. eine konkrete hcm_onboarding_id) und lassen sich nicht automatisch auf rec_contract übertragen. Bei Bedarf manuell behandeln.");
        }

        if ($errors > 0) {
            return self::FAILURE;
        }

        if ($dryRun) {
            $this->newLine();
            $this->warn('[DRY-RUN] Keine Writes ausgeführt. Ohne --dry-run erneut laufen lassen um tatsächlich zu kopieren.');
        }

        return self::SUCCESS;
    }

    private function printDetail(object $src, ?string $targetContextType): void
    {
        $this->line("      ├─ hcm_def_id:         #{$src->id}");
        $this->line("      ├─ team_id:            {$src->team_id}");
        $this->line("      ├─ context_type (src): {$src->context_type}");
        $this->line("      ├─ context_id  (src):  " . ($src->context_id ?? 'NULL'));
        if ($targetContextType !== null) {
            $this->line("      ├─ context_type (dst): {$targetContextType}");
            $this->line("      ├─ context_id  (dst):  NULL");
        }
        $this->line("      ├─ name:               {$src->name}");
        $this->line("      ├─ label:              {$src->label}");
        $this->line("      ├─ type:               {$src->type}");
        $this->line("      ├─ is_required:        " . ($src->is_required ? 'true' : 'false'));
        $this->line("      ├─ is_encrypted:       " . ($src->is_encrypted ? 'true' : 'false'));
        $this->line("      ├─ order:              {$src->order}");
        $this->line("      ├─ options:            " . ($src->options ?? 'NULL'));
        $this->line("      ├─ created_by_user_id: " . ($src->created_by_user_id ?? 'NULL'));
        $this->line("      └─ created_at (kept):  {$src->created_at}");
        $this->newLine();
    }

}
