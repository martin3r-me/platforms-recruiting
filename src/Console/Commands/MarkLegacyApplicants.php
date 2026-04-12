<?php

namespace Platform\Recruiting\Console\Commands;

use Illuminate\Console\Command;
use Platform\Recruiting\Models\RecApplicant;

class MarkLegacyApplicants extends Command
{
    protected $signature = 'recruiting:mark-legacy-applicants
        {--before= : Datum (YYYY-MM-DD), davor = legacy. Default: vor 7 Tagen}
        {--dry-run : Nur anzeigen was passieren würde}';

    protected $description = 'Setzt enrichment_status=legacy für alte Bewerber die nie enriched wurden';

    public function handle(): int
    {
        $dryRun = $this->option('dry-run');
        $before = $this->option('before') ?? now()->subDays(7)->toDateString();

        $this->components->info("Mark Legacy Applicants (vor {$before})");

        if ($dryRun) {
            $this->warn('[DRY-RUN] Keine Änderungen werden vorgenommen.');
        }

        $query = RecApplicant::query()
            ->where(fn ($q) => $q->whereNull('enrichment_status')->orWhere('enrichment_status', ''))
            ->where('created_at', '<', $before);

        $count = $query->count();

        // Debug: show total without date filter
        $totalNoStatus = RecApplicant::query()
            ->where(fn ($q) => $q->whereNull('enrichment_status')->orWhere('enrichment_status', ''))
            ->count();
        $this->line("(Debug: {$totalNoStatus} total ohne Status, {$count} davon vor {$before})");

        if ($count === 0) {
            $this->info('Keine alten Bewerber ohne enrichment_status gefunden.');
            return self::SUCCESS;
        }

        $this->info("{$count} Bewerber ohne enrichment_status vor {$before}.");

        if (!$dryRun) {
            $query->update(['enrichment_status' => 'legacy']);
        }

        $this->components->info($dryRun
            ? "Würde {$count} Bewerber auf 'legacy' setzen."
            : "{$count} Bewerber auf 'legacy' gesetzt.");

        return self::SUCCESS;
    }
}
