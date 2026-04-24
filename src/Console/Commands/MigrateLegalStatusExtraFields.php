<?php

namespace Platform\Recruiting\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class MigrateLegalStatusExtraFields extends Command
{
    protected $signature = 'recruiting:migrate-legal-status
        {--dry-run : Nur anzeigen was passieren würde}';

    protected $description = 'Migriert eu_burger + Dokument-Uploads von Extra-Fields nach rec_applicant_legal_statuses';

    private const DOCUMENT_FIELD_MAP = [
        'nationalpass' => 'nationalpass_file_id',
        'aufenthaltstitel_vorderseite' => 'aufenthaltstitel_front_file_id',
        'aufenthaltstitel_ruckseite' => 'aufenthaltstitel_back_file_id',
        'visumsblatt' => 'visumsblatt_file_id',
        'zusatzblatt' => 'zusatzblatt_file_id',
        'immatrikulationsbescheinigung_schulbescheinigung' => 'immatrikulation_file_id',
    ];

    public function handle(): int
    {
        $dryRun = $this->option('dry-run');

        $this->components->info('Migrate Legal Status Extra Fields → Native Columns');

        if ($dryRun) {
            $this->warn('[DRY-RUN] Keine Änderungen werden vorgenommen.');
        }

        $this->newLine();

        // 1. EU-Bürger
        $this->migrateEuBurger($dryRun);

        $this->newLine();

        // 2. Dokument-Uploads
        $this->migrateDocuments($dryRun);

        $this->newLine();

        // 3. Summary: Definitions die danach per MCP gelöscht werden können
        $this->showDefinitionsSummary();

        return self::SUCCESS;
    }

    private function migrateEuBurger(bool $dryRun): void
    {
        $this->components->info('1. EU-Bürger Extra-Field → is_eu_citizen');

        $euDefinitions = DB::table('core_extra_field_definitions')
            ->where('name', 'eu_burger')
            ->get();

        if ($euDefinitions->isEmpty()) {
            $this->warn('   Keine eu_burger Definitions gefunden.');
            return;
        }

        $this->line("   {$euDefinitions->count()} Definition(s) gefunden: IDs " . $euDefinitions->pluck('id')->implode(', '));

        $euValues = DB::table('core_extra_field_values')
            ->whereIn('definition_id', $euDefinitions->pluck('id'))
            ->where('fieldable_type', 'Platform\\Recruiting\\Models\\RecApplicant')
            ->whereNotNull('value')
            ->where('value', '!=', '')
            ->get();

        $this->line("   {$euValues->count()} ausgefüllte Values gefunden.");

        $created = 0;
        $updated = 0;
        $skipped = 0;

        foreach ($euValues as $euValue) {
            $applicant = DB::table('rec_applicants')->where('id', $euValue->fieldable_id)->first();
            if (!$applicant) {
                $this->warn("   ⚠ Applicant #{$euValue->fieldable_id} nicht gefunden — übersprungen.");
                $skipped++;
                continue;
            }

            $isEuCitizen = in_array($euValue->value, ['1', 'true'], true);
            $label = $isEuCitizen ? 'EU' : 'Nicht-EU';

            $existing = DB::table('rec_applicant_legal_statuses')
                ->where('rec_applicant_id', $applicant->id)
                ->first();

            if ($existing) {
                if ($existing->is_eu_citizen !== null) {
                    $this->line("   ⏭ Applicant #{$applicant->id} — bereits gesetzt, übersprungen.");
                    $skipped++;
                } else {
                    $this->line("   ✏ Applicant #{$applicant->id} — update is_eu_citizen={$label}");
                    if (!$dryRun) {
                        DB::table('rec_applicant_legal_statuses')
                            ->where('id', $existing->id)
                            ->update(['is_eu_citizen' => $isEuCitizen, 'updated_at' => now()]);
                    }
                    $updated++;
                }
            } else {
                $this->line("   ✚ Applicant #{$applicant->id} — create LegalStatus is_eu_citizen={$label}");
                if (!$dryRun) {
                    DB::table('rec_applicant_legal_statuses')->insert([
                        'rec_applicant_id' => $applicant->id,
                        'team_id' => $applicant->team_id,
                        'is_eu_citizen' => $isEuCitizen,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
                $created++;
            }
        }

        $this->newLine();
        $this->components->bulletList([
            "Erstellt: {$created}",
            "Aktualisiert: {$updated}",
            "Übersprungen: {$skipped}",
        ]);
    }

    private function migrateDocuments(bool $dryRun): void
    {
        $this->components->info('2. Dokument-Uploads → native Spalten');

        $totalMigrated = 0;
        $totalSkipped = 0;

        foreach (self::DOCUMENT_FIELD_MAP as $extraFieldName => $column) {
            $defIds = DB::table('core_extra_field_definitions')
                ->where('name', $extraFieldName)
                ->pluck('id')
                ->all();

            if (empty($defIds)) {
                continue;
            }

            $fileValues = DB::table('core_extra_field_values')
                ->whereIn('definition_id', $defIds)
                ->where('fieldable_type', 'Platform\\Recruiting\\Models\\RecApplicant')
                ->whereNotNull('value')
                ->where('value', '!=', '')
                ->get();

            if ($fileValues->isEmpty()) {
                continue;
            }

            $this->line("   {$extraFieldName} → {$column}: {$fileValues->count()} Values");

            foreach ($fileValues as $fileValue) {
                $rawValue = $fileValue->value;
                $fileId = null;

                if (str_starts_with($rawValue, '[')) {
                    $decoded = json_decode($rawValue, true);
                    if (is_array($decoded) && !empty($decoded)) {
                        $fileId = (int) $decoded[0];
                    }
                } else {
                    $fileId = (int) $rawValue;
                }

                if (!$fileId) {
                    $totalSkipped++;
                    continue;
                }

                $legalStatus = DB::table('rec_applicant_legal_statuses')
                    ->where('rec_applicant_id', $fileValue->fieldable_id)
                    ->first();

                if (!$legalStatus) {
                    $this->warn("     ⚠ Applicant #{$fileValue->fieldable_id} hat keinen LegalStatus — übersprungen.");
                    $totalSkipped++;
                    continue;
                }

                if ($legalStatus->$column !== null) {
                    $this->line("     ⏭ Applicant #{$fileValue->fieldable_id} {$column} bereits gesetzt.");
                    $totalSkipped++;
                    continue;
                }

                $this->line("     ✏ Applicant #{$fileValue->fieldable_id} {$column} = {$fileId}");
                if (!$dryRun) {
                    DB::table('rec_applicant_legal_statuses')
                        ->where('id', $legalStatus->id)
                        ->update([$column => $fileId, 'updated_at' => now()]);
                }
                $totalMigrated++;
            }
        }

        $this->newLine();
        $this->components->bulletList([
            "Dokumente migriert: {$totalMigrated}",
            "Übersprungen: {$totalSkipped}",
        ]);
    }

    private function showDefinitionsSummary(): void
    {
        $allNames = array_merge(['eu_burger'], array_keys(self::DOCUMENT_FIELD_MAP));

        $definitions = DB::table('core_extra_field_definitions')
            ->whereIn('name', $allNames)
            ->get(['id', 'name', 'context_type', 'context_id']);

        $this->components->info('3. Definitions zum Löschen (per MCP):');

        if ($definitions->isEmpty()) {
            $this->line('   Keine Definitions gefunden — bereits gelöscht?');
            return;
        }

        $headers = ['ID', 'Name', 'Context Type', 'Context ID'];
        $rows = $definitions->map(fn($d) => [$d->id, $d->name, $d->context_type, $d->context_id])->all();
        $this->table($headers, $rows);

        $this->line("   → {$definitions->count()} Definitions können nach Verifizierung gelöscht werden.");
    }
}
