<?php

namespace Platform\Recruiting\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Platform\Recruiting\Models\RecApplicant;

/**
 * Backfill: korrigiert applicant.applied_at-Werte die vom Enrichment-LLM
 * mit Datum aus dem Mail-Body überschrieben wurden.
 *
 * Wahrheits-Quelle ist das Pivot rec_applicant_posting.applied_at — das wird
 * vom Inbound-Listener auf den Tag unseres Eingangs gesetzt und nie wieder
 * angefasst. Dieser Wert ist die "richtige" Bewerbungs-Eingangs-Daten.
 *
 * Der Command setzt applicant.applied_at auf das früheste pivot.applied_at
 * (= erste Posting-Verknüpfung = erstes Inbound-Eingang). Bewerber ohne
 * Pivot-Daten werden auf created_at gesetzt als Fallback.
 *
 * Aufruf:
 *   php artisan recruiting:fix-applied-at --dry-run
 *   php artisan recruiting:fix-applied-at
 *   php artisan recruiting:fix-applied-at --team-id=3
 */
class FixAppliedAt extends Command
{
    protected $signature = 'recruiting:fix-applied-at
        {--team-id= : Optional auf ein Team beschränken}
        {--dry-run : Nur anzeigen was geändert würde, nichts schreiben}
        {--limit=0 : Maximale Anzahl Bewerber pro Run (0 = alle)}';

    protected $description = 'Backfill applicant.applied_at aus pivot rec_applicant_posting.applied_at (Wahrheit: Inbound-Eingangs-Datum)';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $teamId = $this->option('team-id');
        $limit = max(0, (int) $this->option('limit'));

        $query = RecApplicant::query()
            ->with(['postings' => fn ($q) => $q->orderBy('rec_applicant_posting.applied_at')]);

        if ($teamId) {
            $query->where('team_id', (int) $teamId);
        }

        if ($limit > 0) {
            $query->limit($limit);
        }

        if ($dryRun) {
            $this->warn('DRY-RUN — es wird nichts geschrieben.');
        }

        $checked = 0;
        $changed = 0;
        $unchanged = 0;
        $fallbackToCreatedAt = 0;
        $errors = 0;

        foreach ($query->cursor() as $applicant) {
            $checked++;

            $expected = $this->resolveExpected($applicant);
            if ($expected === null) {
                $errors++;
                continue;
            }

            $current = $applicant->applied_at?->toDateString();
            if ($current === $expected['date']) {
                $unchanged++;
                continue;
            }

            $name = $applicant->crmContactLinks?->first()?->contact?->full_name ?? "#{$applicant->id}";

            $this->line(sprintf(
                ' #%d %s : %s → %s (Quelle: %s)',
                $applicant->id,
                str_pad($name, 30),
                $current ?? 'null',
                $expected['date'],
                $expected['source']
            ));

            if ($expected['source'] === 'created_at') {
                $fallbackToCreatedAt++;
            }

            if (!$dryRun) {
                try {
                    DB::table('rec_applicants')
                        ->where('id', $applicant->id)
                        ->update(['applied_at' => $expected['date']]);
                    $changed++;
                } catch (\Throwable $e) {
                    $errors++;
                    $this->error(" Fehler bei #{$applicant->id}: {$e->getMessage()}");
                }
            } else {
                $changed++;
            }
        }

        $this->info('');
        $this->info("Geprüft:           {$checked}");
        $this->info("Geändert:          {$changed}" . ($dryRun ? ' (dry-run)' : ''));
        $this->info("Schon korrekt:     {$unchanged}");
        $this->info("Fallback->created: {$fallbackToCreatedAt}");
        if ($errors > 0) {
            $this->warn("Fehler:            {$errors}");
        }

        return Command::SUCCESS;
    }

    /**
     * @return array{date: string, source: string}|null
     *   ['date' => 'YYYY-MM-DD', 'source' => 'pivot'|'created_at']
     */
    private function resolveExpected(RecApplicant $applicant): ?array
    {
        // Frühestes Pivot-Datum (= unser Inbound-Eingang)
        $earliestPivot = $applicant->postings
            ->map(fn ($p) => $p->pivot?->applied_at)
            ->filter()
            ->sort()
            ->first();

        if ($earliestPivot) {
            $date = $earliestPivot instanceof \DateTimeInterface
                ? $earliestPivot->format('Y-m-d')
                : (string) $earliestPivot;
            // sicherheitshalber auf YYYY-MM-DD normalisieren
            try {
                $date = \Carbon\Carbon::parse($date)->toDateString();
                return ['date' => $date, 'source' => 'pivot'];
            } catch (\Throwable) {}
        }

        // Fallback: created_at
        if ($applicant->created_at) {
            return ['date' => $applicant->created_at->toDateString(), 'source' => 'created_at'];
        }

        return null;
    }
}
