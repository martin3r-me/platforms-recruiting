<?php

namespace Platform\Recruiting\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class DiagnoseContractExtraFields extends Command
{
    protected $signature = 'recruiting:diagnose-contract-extra-fields';

    protected $description = 'Zeigt den aktuellen Zustand von rec_contracts und passenden core_extra_field_definitions zur Diagnose warum das Felder-Modal leer bleibt.';

    public function handle(): int
    {
        $this->components->info('1. Alle rec_contracts (max 10)');
        $contracts = DB::table('rec_contracts')
            ->select('id', 'uuid', 'rec_applicant_id', 'rec_contract_template_id', 'team_id', 'status', 'personalized_content')
            ->orderByDesc('id')
            ->limit(10)
            ->get();

        foreach ($contracts as $c) {
            $contentLen = strlen($c->personalized_content ?? '');
            $this->line("  #{$c->id} team={$c->team_id} status={$c->status} applicant={$c->rec_applicant_id} template={$c->rec_contract_template_id} content_len={$contentLen}");
        }
        $this->newLine();

        $this->components->info('2. Extra-Field-Definitions gruppiert nach context_type (alle, alle Teams)');
        $defsByContext = DB::table('core_extra_field_definitions')
            ->select('context_type', 'team_id', DB::raw('COUNT(*) as cnt'))
            ->groupBy('context_type', 'team_id')
            ->orderBy('context_type')
            ->orderBy('team_id')
            ->get();

        foreach ($defsByContext as $row) {
            $this->line("  team={$row->team_id}  context_type='{$row->context_type}'  count={$row->cnt}");
        }
        $this->newLine();

        $this->components->info('3. Alle Definitionen mit context_type contract/onboarding (details)');
        $defs = DB::table('core_extra_field_definitions')
            ->where(function ($q) {
                $q->where('context_type', 'LIKE', '%contract%')
                  ->orWhere('context_type', 'LIKE', '%Contract%')
                  ->orWhere('context_type', 'LIKE', '%onboarding%')
                  ->orWhere('context_type', 'LIKE', '%Onboarding%');
            })
            ->get(['id', 'team_id', 'context_type', 'context_id', 'name', 'label', 'type']);

        foreach ($defs as $d) {
            $ctxId = $d->context_id ?? 'NULL';
            $this->line("  #{$d->id} team={$d->team_id} context_type='{$d->context_type}' context_id={$ctxId} name={$d->name} label='{$d->label}' type={$d->type}");
        }
        $this->newLine();

        $this->components->info('4. Test: was würde HasExtraFields für den letzten Contract finden?');
        $latest = $contracts->first();
        if ($latest) {
            $expectedContextType = 'Platform\\Recruiting\\Models\\RecContract';
            $this->line("  Letzter Contract: #{$latest->id} team={$latest->team_id}");
            $this->line("  Erwarteter Query: team_id={$latest->team_id} AND context_type='{$expectedContextType}' AND (context_id IS NULL OR context_id = {$latest->id})");

            $match = DB::table('core_extra_field_definitions')
                ->where('team_id', $latest->team_id)
                ->where('context_type', $expectedContextType)
                ->where(function ($q) use ($latest) {
                    $q->whereNull('context_id')->orWhere('context_id', $latest->id);
                })
                ->get();

            $this->line("  Treffer: {$match->count()}");
            foreach ($match as $m) {
                $this->line("    #{$m->id} name={$m->name}");
            }
        }

        return self::SUCCESS;
    }
}
