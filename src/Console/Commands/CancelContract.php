<?php

namespace Platform\Recruiting\Console\Commands;

use Illuminate\Console\Command;
use Platform\Recruiting\Models\RecContract;

/**
 * Admin-/Test-Helper: stornt Verträge — auch abgeschlossene.
 * Nicht für Produktiv-Nutzung gedacht, primär um Test-Applicants
 * frisch durch den Zuweisungs-Flow zu schicken.
 */
class CancelContract extends Command
{
    protected $signature = 'recruiting:cancel-contract
        {id? : Contract-ID (einzelner Vertrag)}
        {--applicant= : Applicant-ID — storniert ALLE Verträge des Bewerbers (auch completed)}
        {--include-completed : Erlaubt Stornieren signierter/abgeschlossener Verträge (bei --applicant default an)}
        {--dry-run : Zeigt nur was passieren würde}';

    protected $description = 'Storniert Verträge (status=cancelled). Admin/Test-Tool — überschreibt ggf. auch signed_at/completed_at nicht, damit das Audit-Snapshot erhalten bleibt.';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $id = $this->argument('id');
        $applicantId = $this->option('applicant');
        $includeCompleted = (bool) $this->option('include-completed') || (bool) $applicantId;

        if (!$id && !$applicantId) {
            $this->error('Bitte entweder {id} oder --applicant=<id> angeben.');
            return self::FAILURE;
        }

        $query = RecContract::query()->with(['contractTemplate', 'applicant']);

        if ($id)          $query->where('id', (int) $id);
        if ($applicantId) $query->where('rec_applicant_id', (int) $applicantId);

        $query->where('status', '!=', 'cancelled');
        if (!$includeCompleted) {
            $query->where('status', '!=', 'completed');
        }

        $contracts = $query->orderBy('id')->get();

        if ($contracts->isEmpty()) {
            $this->warn('Keine stornierbaren Verträge gefunden (oder alle schon cancelled).');
            return self::SUCCESS;
        }

        $this->components->info("Cancel Contracts ({$contracts->count()})");

        foreach ($contracts as $c) {
            $name = $c->contractTemplate?->name ?? '—';
            $code = $c->contractTemplate?->code ?? '—';
            $signed = $c->signed_at ? ' [SIGNED]' : '';
            $this->line("  ✗ #{$c->id} applicant=#{$c->rec_applicant_id} status={$c->status}{$signed} — [{$code}] {$name}");
        }

        if ($dry) {
            $this->newLine();
            $this->warn('[DRY-RUN] Keine Writes. Ohne --dry-run erneut laufen lassen.');
            return self::SUCCESS;
        }

        $ids = $contracts->pluck('id')->all();
        RecContract::whereIn('id', $ids)->update([
            'status'     => 'cancelled',
            'updated_at' => now(),
        ]);

        $this->newLine();
        $this->components->info('Fertig — ' . count($ids) . ' Vertrag/Verträge storniert.');

        return self::SUCCESS;
    }
}
