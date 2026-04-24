<?php

namespace Platform\Recruiting\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Uid\UuidV7;

class CopyHcmContractTemplates extends Command
{
    protected $signature = 'recruiting:copy-hcm-templates
        {--dry-run : Nur anzeigen was passieren würde, keine Writes}';

    protected $description = 'Kopiert hcm_contract_templates 1:1 nach rec_contract_templates (idempotent; Dedup per team_id+name; field_mappings-Prefix onboarding.→applicant.)';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $this->components->info('Copy HCM → Recruiting Contract Templates');

        if ($dryRun) {
            $this->warn('[DRY-RUN] Keine Änderungen werden vorgenommen.');
        }
        $this->newLine();

        if (!Schema::hasTable('hcm_contract_templates')) {
            $this->error('Tabelle hcm_contract_templates existiert nicht — HCM-Modul nicht migriert?');
            return self::FAILURE;
        }

        if (!Schema::hasTable('rec_contract_templates')) {
            $this->error('Tabelle rec_contract_templates existiert nicht — Recruiting-Migration nicht ausgeführt?');
            return self::FAILURE;
        }

        $sources = DB::table('hcm_contract_templates')
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->get();

        $this->line("Gefundene HCM-Templates (ohne soft-deleted): {$sources->count()}");
        $this->newLine();

        $copied = 0;
        $skipped = 0;
        $errors = 0;

        foreach ($sources as $src) {
            $existing = DB::table('rec_contract_templates')
                ->where('team_id', $src->team_id)
                ->where('name', $src->name)
                ->whereNull('deleted_at')
                ->first();

            if ($existing) {
                $this->line("  ⏭ [team={$src->team_id}] \"{$src->name}\" — existiert bereits als rec_contract_template #{$existing->id}, übersprungen.");
                $skipped++;
                continue;
            }

            $remappedFieldMappings = $this->remapFieldMappings($src->field_mappings);

            $this->line("  ✚ [team={$src->team_id}] \"{$src->name}\" — neu anlegen (HCM-Quelle #{$src->id}).");

            if ($dryRun) {
                $copied++;
                continue;
            }

            try {
                DB::table('rec_contract_templates')->insert([
                    'uuid'               => (string) UuidV7::generate(),
                    'name'               => $src->name,
                    'code'               => $src->code,
                    'description'        => $src->description,
                    'content'            => $src->content,
                    'field_mappings'     => $remappedFieldMappings,
                    'requires_signature' => (bool) $src->requires_signature,
                    'is_active'          => (bool) $src->is_active,
                    'sort_order'         => (int) $src->sort_order,
                    'team_id'            => $src->team_id,
                    'created_by_user_id' => $src->created_by_user_id,
                    'created_at'         => $src->created_at,
                    'updated_at'         => now(),
                    'deleted_at'         => null,
                ]);
                $copied++;
            } catch (\Throwable $e) {
                $this->error("     ✗ Fehler bei HCM-#{$src->id}: {$e->getMessage()}");
                $errors++;
            }
        }

        $this->newLine();
        $this->components->bulletList([
            "Kopiert:        {$copied}",
            "Übersprungen:   {$skipped}",
            "Fehler:         {$errors}",
        ]);

        if ($errors > 0) {
            return self::FAILURE;
        }

        if ($dryRun) {
            $this->newLine();
            $this->warn('[DRY-RUN] Keine Writes ausgeführt. Ohne --dry-run erneut laufen lassen um tatsächlich zu kopieren.');
        }

        return self::SUCCESS;
    }

    private function remapFieldMappings(?string $rawJson): ?string
    {
        if ($rawJson === null || $rawJson === '') {
            return null;
        }

        $decoded = json_decode($rawJson, true);
        if (!is_array($decoded)) {
            return $rawJson;
        }

        foreach ($decoded as $placeholder => $source) {
            if (is_string($source) && str_starts_with($source, 'onboarding.')) {
                $decoded[$placeholder] = 'applicant.' . substr($source, strlen('onboarding.'));
            }
        }

        return json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
