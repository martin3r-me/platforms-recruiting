<?php

namespace Platform\Recruiting\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Uid\UuidV7;

class CreateArbeitsvertragVariants extends Command
{
    protected $signature = 'recruiting:create-arbeitsvertrag-variants
        {--dry-run : Nur anzeigen was passieren würde, keine Writes}
        {--base-code=AV : Code der Basis-Vorlage aus der kopiert wird}
        {--keep-base : Basis-Template nach erfolgreicher Klonung aktiv lassen (default: auf is_active=false setzen)}
        {--prune-obsolete : AV-NNN-Varianten die nicht in der aktuellen VARIANTS-Liste stehen werden soft-gelöscht (betrifft nur Templates, signierte Verträge bleiben via personalized_content-Snapshot referenzierbar)}';

    protected $description = 'Klont die Basis-Vertragsvorlage mit Code AV in 6 Varianten mit Zuschlag 0,10 / 0,60 / 1,10 / 1,60 / 2,10 / 2,60 € (Zuschlag im Body via {{zuschlag}}-Placeholder literal ersetzt). Anpasst field_mappings: entfernt zuschlag-Key, mappt stundenlohn auf settings.minimum_wage_hourly. Idempotent via (team_id, code)-Dedup. Optional --prune-obsolete für alte AV-NNN-Varianten die nicht mehr in der Liste sind.';

    private const VARIANTS = [
        ['suffix' => '010', 'value' => '0,10', 'label' => '0,10€'],
        ['suffix' => '060', 'value' => '0,60', 'label' => '0,60€'],
        ['suffix' => '110', 'value' => '1,10', 'label' => '1,10€'],
        ['suffix' => '160', 'value' => '1,60', 'label' => '1,60€'],
        ['suffix' => '210', 'value' => '2,10', 'label' => '2,10€'],
        ['suffix' => '260', 'value' => '2,60', 'label' => '2,60€'],
    ];

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $baseCode = (string) $this->option('base-code');
        $keepBase = (bool) $this->option('keep-base');
        $pruneObsolete = (bool) $this->option('prune-obsolete');

        $targetSuffixes = array_map(fn ($v) => $v['suffix'], self::VARIANTS);
        $targetLabels = implode(' / ', array_map(fn ($v) => $v['label'], self::VARIANTS));
        $this->components->info("Create Arbeitsvertrag-Varianten (Zuschlag {$targetLabels})");

        if ($dryRun) {
            $this->warn('[DRY-RUN] Keine Änderungen werden vorgenommen.');
        }
        $this->newLine();

        if (!Schema::hasTable('rec_contract_templates')) {
            $this->error('Tabelle rec_contract_templates existiert nicht.');
            return self::FAILURE;
        }

        $bases = DB::table('rec_contract_templates')
            ->where('code', $baseCode)
            ->whereNull('deleted_at')
            ->get();

        if ($bases->isEmpty()) {
            $this->error("Keine Basis-Vorlage mit code='{$baseCode}' gefunden (aktive rec_contract_templates).");
            return self::FAILURE;
        }

        $created = 0;
        $skipped = 0;
        $errors = 0;
        $basesProcessed = 0;

        foreach ($bases as $base) {
            $this->line("Basis: [team={$base->team_id}] #{$base->id} \"{$base->name}\" (code={$base->code})");

            if (!$base->content) {
                $this->warn("  ⚠ Basis-Template hat leeren content — übersprungen.");
                continue;
            }

            if (!str_contains($base->content, '{{zuschlag}}')) {
                $this->warn("  ⚠ Basis-Template enthält keinen {{zuschlag}}-Placeholder — übersprungen (nichts zu ersetzen).");
                continue;
            }

            $baseFieldMappings = $this->decodeMappings($base->field_mappings);
            unset($baseFieldMappings['zuschlag']);
            if (isset($baseFieldMappings['stundenlohn'])) {
                $baseFieldMappings['stundenlohn'] = 'settings.minimum_wage_hourly';
            }

            foreach (self::VARIANTS as $variant) {
                $newCode = "{$baseCode}-{$variant['suffix']}";
                $newName = "{$base->name} (Zuschlag {$variant['label']})";

                $existing = DB::table('rec_contract_templates')
                    ->where('team_id', $base->team_id)
                    ->where('code', $newCode)
                    ->whereNull('deleted_at')
                    ->first();

                if ($existing) {
                    $this->line("  ⏭ {$newCode} \"{$newName}\" — existiert bereits (#{$existing->id}), übersprungen.");
                    $skipped++;
                    continue;
                }

                $newContent = str_replace('{{zuschlag}}', $variant['value'], $base->content);

                $this->line("  ✚ {$newCode} \"{$newName}\" — neu anlegen (content_len=" . strlen($newContent) . " bytes).");

                if ($dryRun) {
                    $created++;
                    continue;
                }

                try {
                    DB::table('rec_contract_templates')->insert([
                        'uuid'               => (string) UuidV7::generate(),
                        'name'               => $newName,
                        'code'               => $newCode,
                        'description'        => $base->description,
                        'content'            => $newContent,
                        'field_mappings'     => json_encode($baseFieldMappings, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                        'requires_signature' => (bool) $base->requires_signature,
                        'is_active'          => true,
                        'sort_order'         => ((int) $base->sort_order) * 10 + (int) $variant['suffix'],
                        'team_id'            => $base->team_id,
                        'created_by_user_id' => $base->created_by_user_id,
                        'created_at'         => now(),
                        'updated_at'         => now(),
                        'deleted_at'         => null,
                    ]);
                    $created++;
                } catch (\Throwable $e) {
                    $this->error("     ✗ Fehler beim Anlegen von {$newCode}: {$e->getMessage()}");
                    $errors++;
                }
            }

            $basesProcessed++;

            if (!$keepBase && $errors === 0) {
                $this->line("  ⏸ Basis-Template #{$base->id} wird deaktiviert (is_active=false).");
                if (!$dryRun) {
                    DB::table('rec_contract_templates')
                        ->where('id', $base->id)
                        ->update([
                            'is_active'  => false,
                            'updated_at' => now(),
                        ]);
                }
            }
        }

        $pruned = 0;
        if ($pruneObsolete && $errors === 0) {
            $this->newLine();
            $this->components->info('Prune: alte AV-NNN-Varianten deaktivieren die nicht in der aktuellen Liste stehen');

            $targetCodes = array_map(fn ($s) => "{$baseCode}-{$s}", $targetSuffixes);

            $obsolete = DB::table('rec_contract_templates')
                ->where('code', 'LIKE', "{$baseCode}-%")
                ->whereNotIn('code', $targetCodes)
                ->whereNull('deleted_at')
                ->get();

            foreach ($obsolete as $old) {
                $contractCount = DB::table('rec_contracts')
                    ->where('rec_contract_template_id', $old->id)
                    ->count();

                $suffix = $contractCount > 0 ? " (hat {$contractCount} referenzierende Verträge, Snapshot bleibt erhalten)" : '';
                $this->line("  🗑 {$old->code} \"{$old->name}\" (#{$old->id}) soft-löschen{$suffix}.");

                if (!$dryRun) {
                    DB::table('rec_contract_templates')
                        ->where('id', $old->id)
                        ->update([
                            'is_active'  => false,
                            'deleted_at' => now(),
                            'updated_at' => now(),
                        ]);
                }
                $pruned++;
            }

            if ($pruned === 0) {
                $this->line('  Nichts zu prunen.');
            }
        }

        $this->newLine();
        $this->components->bulletList([
            "Basis-Templates verarbeitet: {$basesProcessed}",
            "Varianten neu angelegt:      {$created}",
            "Bereits da, übersprungen:    {$skipped}",
            "Fehler:                      {$errors}",
            "Obsolete gepruned:           {$pruned}",
        ]);

        if ($errors > 0) {
            return self::FAILURE;
        }

        if ($dryRun) {
            $this->newLine();
            $this->warn('[DRY-RUN] Keine Writes ausgeführt. Ohne --dry-run erneut laufen lassen um tatsächlich zu klonen.');
        }

        return self::SUCCESS;
    }

    private function decodeMappings(?string $raw): array
    {
        if ($raw === null || $raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }
}
