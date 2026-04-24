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
        {--keep-base : Basis-Template nach erfolgreicher Klonung aktiv lassen (default: auf is_active=false setzen)}';

    protected $description = 'Klont die Basis-Vertragsvorlage mit Code AV in 4 Varianten mit Zuschlag 0,50 / 1,00 / 1,50 / 2,00 € (Zuschlag im Body via {{zuschlag}}-Placeholder literal ersetzt). Anpasst field_mappings: entfernt zuschlag-Key, mappt stundenlohn auf settings.minimum_wage_hourly. Idempotent via (team_id, code)-Dedup.';

    private const VARIANTS = [
        ['suffix' => '050', 'value' => '0,50', 'label' => '0,50€'],
        ['suffix' => '100', 'value' => '1,00', 'label' => '1,00€'],
        ['suffix' => '150', 'value' => '1,50', 'label' => '1,50€'],
        ['suffix' => '200', 'value' => '2,00', 'label' => '2,00€'],
    ];

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $baseCode = (string) $this->option('base-code');
        $keepBase = (bool) $this->option('keep-base');

        $this->components->info('Create Arbeitsvertrag-Varianten (Zuschlag 0,50 / 1,00 / 1,50 / 2,00 €)');

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

        $this->newLine();
        $this->components->bulletList([
            "Basis-Templates verarbeitet: {$basesProcessed}",
            "Varianten neu angelegt:      {$created}",
            "Bereits da, übersprungen:    {$skipped}",
            "Fehler:                      {$errors}",
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
