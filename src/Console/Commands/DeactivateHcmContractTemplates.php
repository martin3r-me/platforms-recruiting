<?php

namespace Platform\Recruiting\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class DeactivateHcmContractTemplates extends Command
{
    protected $signature = 'recruiting:deactivate-hcm-templates
        {--dry-run : Nur anzeigen was passieren würde, keine Writes}';

    protected $description = 'Setzt is_active=false auf allen nicht-soft-deleted hcm_contract_templates. Idempotent. Löscht nichts — signierte hcm_onboarding_contracts bleiben über FK referenzierbar und rendern aus ihrem personalized_content-Snapshot weiter.';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $this->components->info('Deactivate HCM Contract Templates');

        if ($dryRun) {
            $this->warn('[DRY-RUN] Keine Änderungen werden vorgenommen.');
        }
        $this->newLine();

        if (!Schema::hasTable('hcm_contract_templates')) {
            $this->error('Tabelle hcm_contract_templates existiert nicht — HCM-Modul nicht migriert?');
            return self::FAILURE;
        }

        $templates = DB::table('hcm_contract_templates')
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->get(['id', 'name', 'team_id', 'is_active']);

        $this->line("Gefundene HCM-Templates (ohne soft-deleted): {$templates->count()}");
        $this->newLine();

        $deactivated = 0;
        $alreadyInactive = 0;

        foreach ($templates as $t) {
            if (!$t->is_active) {
                $this->line("  ⏭ [team={$t->team_id}] #{$t->id} \"{$t->name}\" — bereits is_active=false, übersprungen.");
                $alreadyInactive++;
                continue;
            }

            $this->line("  ✏ [team={$t->team_id}] #{$t->id} \"{$t->name}\" — setze is_active=false.");

            if (!$dryRun) {
                DB::table('hcm_contract_templates')
                    ->where('id', $t->id)
                    ->update([
                        'is_active'  => false,
                        'updated_at' => now(),
                    ]);
            }
            $deactivated++;
        }

        $this->newLine();
        $this->components->bulletList([
            "Deaktiviert:      {$deactivated}",
            "Bereits inaktiv:  {$alreadyInactive}",
        ]);

        if ($dryRun) {
            $this->newLine();
            $this->warn('[DRY-RUN] Keine Writes ausgeführt. Ohne --dry-run erneut laufen lassen um tatsächlich zu deaktivieren.');
        }

        return self::SUCCESS;
    }
}
