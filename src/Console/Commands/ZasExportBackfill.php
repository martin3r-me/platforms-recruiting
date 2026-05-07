<?php

namespace Platform\Recruiting\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Initial-Backfill fuer den ZAS-Bewerber-Export.
 *
 * Setzt rec_applicants.export_changed_at = NOW() fuer alle Bestands-
 * bewerber, die bereits einen Vertrag erhalten haben (rec_contracts
 * .sent_at IS NOT NULL). Beim ersten ZAS-Pull nach dem Backfill
 * bekommt das externe System diese Bewerber komplett ausgeliefert.
 *
 * Idempotent: ueberschreibt KEINE bereits gesetzten Marker. Wenn ein
 * Bewerber durch den Observer schon markiert ist (z. B. vor dem Backfill
 * eingelaufene Aenderung), bleibt der bestehende Timestamp erhalten.
 *
 * Aufruf:
 *   php artisan recruiting:zas-export-backfill --dry-run
 *   php artisan recruiting:zas-export-backfill
 *   php artisan recruiting:zas-export-backfill --team-id=3
 *
 * Siehe docs/meingedeck/zas-applicant-export.md
 */
class ZasExportBackfill extends Command
{
    protected $signature = 'recruiting:zas-export-backfill
        {--team-id= : Optional auf ein Team beschraenken}
        {--dry-run : Nur anzeigen wieviele Bewerber markiert wuerden}';

    protected $description = 'Markiert Bestandsbewerber mit versendetem Vertrag fuer den initialen ZAS-Export';

    public function handle(): int
    {
        $teamId = $this->option('team-id');
        $dryRun = (bool) $this->option('dry-run');

        // 1. Kandidaten zaehlen
        $countQuery = DB::table('rec_applicants as a')
            ->whereNull('a.export_changed_at')
            ->whereExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('rec_contracts')
                    ->whereColumn('rec_contracts.rec_applicant_id', 'a.id')
                    ->whereNotNull('rec_contracts.sent_at');
            });

        if ($teamId !== null) {
            $countQuery->where('a.team_id', (int) $teamId);
        }

        $count = $countQuery->count();

        if ($count === 0) {
            $this->info('Keine Bewerber zum Backfillen gefunden — entweder keine versendeten Vertraege oder alle bereits markiert.');
            return self::SUCCESS;
        }

        $this->info(sprintf(
            '%d Bewerber wuerden fuer den ZAS-Export markiert%s.',
            $count,
            $teamId !== null ? sprintf(' (team_id=%d)', $teamId) : ''
        ));

        if ($dryRun) {
            $this->warn('DRY-RUN: nichts geschrieben. Re-run ohne --dry-run zum Anwenden.');
            return self::SUCCESS;
        }

        if (!$this->confirm('Markierung jetzt schreiben?', true)) {
            $this->warn('Abgebrochen.');
            return self::SUCCESS;
        }

        // 2. Update — direkt per Query, ohne Eloquent-Events. Damit wird
        //    der Observer nicht getriggert (waere ohnehin no-op da der
        //    Observer auf saved-Events laeuft, aber explizit halten).
        $updateQuery = DB::table('rec_applicants')
            ->whereNull('export_changed_at')
            ->whereExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('rec_contracts')
                    ->whereColumn('rec_contracts.rec_applicant_id', 'rec_applicants.id')
                    ->whereNotNull('rec_contracts.sent_at');
            });

        if ($teamId !== null) {
            $updateQuery->where('team_id', (int) $teamId);
        }

        $affected = $updateQuery->update(['export_changed_at' => now()]);

        $this->info(sprintf('OK — %d Bewerber markiert. Beim naechsten ZAS-Pull liegt das Initial-Batch an.', $affected));

        return self::SUCCESS;
    }
}
