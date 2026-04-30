<?php

namespace Platform\Recruiting\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Platform\Recruiting\Models\RecApplicant;

/**
 * Backfill: korrigiert applicant.applied_at-Werte die vom Enrichment-LLM
 * mit einem Datum aus dem Mail-Body überschrieben wurden.
 *
 * Wahrheits-Quelle ist das Pivot rec_applicant_posting.applied_at — das wird
 * vom Inbound-Listener auf den Tag unseres Eingangs gesetzt und nicht
 * angefasst.
 *
 * SELEKTIVE LOGIK (Option A):
 * Nur die typischen Bug-Cases werden korrigiert, um nicht versehentlich
 * legitime Anpassungen zu überschreiben:
 *   1. current applied_at IS NULL                     → setzen aus Pivot
 *   2. current applied_at < earliest_pivot.applied_at → korrigieren
 *      (= LLM hat auf ein früheres Datum überschrieben — typischer Bug)
 *   3. current applied_at > earliest_pivot.applied_at → NICHT anfassen
 *      (unklar, könnte legitime manuelle Anpassung sein)
 *   4. current applied_at == earliest_pivot.applied_at → no-op
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
        $skippedBackwardShift = 0;
        $skippedNoPivot = 0;
        $fallbackToCreatedAt = 0;
        $errors = 0;

        foreach ($query->cursor() as $applicant) {
            $checked++;

            $current = $applicant->applied_at?->toDateString();
            $earliestPivot = $this->resolveEarliestPivotDate($applicant);

            // Entscheidung: was tun?
            //  - kein Pivot, current null → fallback auf created_at
            //  - kein Pivot, current gesetzt → skip (kein Wahrheits-Anker)
            //  - Pivot, current null → setzen
            //  - Pivot, current < Pivot → korrigieren (Bug-Case: LLM hat zu
            //                                          früh überschrieben)
            //  - Pivot, current >= Pivot → skip (unklare Situation, evtl.
            //                                    legitime HR-Anpassung)
            $newDate = null;
            $source = null;

            if ($earliestPivot === null) {
                if ($current === null) {
                    $createdAt = $applicant->created_at?->toDateString();
                    if ($createdAt) {
                        $newDate = $createdAt;
                        $source = 'created_at';
                    }
                } else {
                    $skippedNoPivot++;
                    continue;
                }
            } else {
                if ($current === null) {
                    $newDate = $earliestPivot;
                    $source = 'pivot';
                } elseif ($current < $earliestPivot) {
                    // Bug-Pattern: applied_at zeigt FRÜHER als Pivot →
                    // wahrscheinlich LLM hat ein früheres Datum aus dem
                    // Mail-Body extrahiert.
                    $newDate = $earliestPivot;
                    $source = 'pivot';
                } elseif ($current > $earliestPivot) {
                    // Unklare Forward-Shift — nicht anfassen.
                    $skippedBackwardShift++;
                    continue;
                } else {
                    // current === earliestPivot → schon korrekt
                    $unchanged++;
                    continue;
                }
            }

            if ($newDate === null) {
                $errors++;
                continue;
            }

            $name = $applicant->crmContactLinks?->first()?->contact?->full_name ?? "#{$applicant->id}";

            $this->line(sprintf(
                ' #%d %s : %s → %s (Quelle: %s)',
                $applicant->id,
                str_pad($name, 30),
                $current ?? 'null',
                $newDate,
                $source
            ));

            if ($source === 'created_at') {
                $fallbackToCreatedAt++;
            }

            if (!$dryRun) {
                try {
                    DB::table('rec_applicants')
                        ->where('id', $applicant->id)
                        ->update(['applied_at' => $newDate]);
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
        $this->info("Geprüft:                       {$checked}");
        $this->info("Geändert:                      {$changed}" . ($dryRun ? ' (dry-run)' : ''));
        $this->info("Schon korrekt:                 {$unchanged}");
        $this->info("Skipped (current >= pivot):    {$skippedBackwardShift}");
        $this->info("Skipped (kein Pivot, gesetzt): {$skippedNoPivot}");
        $this->info("Fallback -> created_at:        {$fallbackToCreatedAt}");
        if ($errors > 0) {
            $this->warn("Fehler:                        {$errors}");
        }

        return Command::SUCCESS;
    }

    /**
     * Liefert das früheste Pivot-applied_at-Datum als YYYY-MM-DD-string,
     * oder null wenn der Bewerber kein Pivot hat.
     */
    private function resolveEarliestPivotDate(RecApplicant $applicant): ?string
    {
        $earliest = $applicant->postings
            ->map(fn ($p) => $p->pivot?->applied_at)
            ->filter()
            ->sort()
            ->first();

        if (!$earliest) {
            return null;
        }

        try {
            return \Carbon\Carbon::parse(
                $earliest instanceof \DateTimeInterface
                    ? $earliest->format('Y-m-d')
                    : (string) $earliest
            )->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }
}
