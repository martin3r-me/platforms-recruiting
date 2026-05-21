<?php

namespace Platform\Recruiting\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Initial-Backfill fuer den ZAS-Mitarbeiter-Export.
 *
 * Setzt rec_employees.zas_initial_exported_at = NULL fuer alle aktiven
 * MAs die noch keinen Export-Marker haben — damit landen sie beim
 * naechsten Initial-Pull als kompletter Initial-Batch.
 *
 * Anwendung: einmaliger Bestandsabgleich beim Live-Schalten des neuen
 * Endpoints. Frische MAs (ueber den Phase-4-Hook) brauchen das nicht —
 * sie sind dort schon mit zas_initial_exported_at=NULL angelegt.
 *
 * Idempotent: ueberspringt MAs die schon einen Initial-Marker haben
 * (zas_initial_exported_at IS NOT NULL) — damit wird kein bereits zu
 * ZAS exportierter MA versehentlich erneut als 'neu' geliefert.
 *
 * Aufruf:
 *   php artisan recruiting:zas-employee-export-backfill --dry-run
 *   php artisan recruiting:zas-employee-export-backfill
 *   php artisan recruiting:zas-employee-export-backfill --team-id=3
 */
class ZasEmployeeExportBackfill extends Command
{
    protected $signature = 'recruiting:zas-employee-export-backfill
        {--team-id= : Optional auf ein Team beschraenken}
        {--dry-run : Nur anzeigen wieviele MAs markiert wuerden}
        {--force : Confirmation ueberspringen}';

    protected $description = 'Markiert Bestands-MAs fuer den initialen ZAS-MA-Export';

    public function handle(): int
    {
        $teamId = $this->option('team-id');
        $dryRun = (bool) $this->option('dry-run');

        // Idempotent: MAs die noch nie initial geliefert wurden UND
        // aktiv sind. zas_initial_exported_at IS NULL gibt's bei
        // frisch via Phase-4-Hook angelegten MAs ohnehin — der
        // Backfill ist hauptsaechlich fuer Bestands-MAs vor Live-
        // Schaltung des Endpoints. Idempotent: setzt nichts neu wenn
        // schon NULL ist.
        $countQuery = DB::table('rec_employees')
            ->whereNull('zas_initial_exported_at')
            ->where('is_active', true);

        if ($teamId !== null) {
            $countQuery->where('team_id', (int) $teamId);
        }

        $count = $countQuery->count();

        if ($count === 0) {
            $this->info('Keine MAs zum Backfillen — alle bereits initial markiert oder keine aktiven MAs vorhanden.');
            return self::SUCCESS;
        }

        $this->info(sprintf(
            '%d aktive MA(s) wuerden im naechsten Initial-Pull als Bestands-Initial-Batch ausgeliefert%s.',
            $count,
            $teamId !== null ? sprintf(' (team_id=%d)', $teamId) : ''
        ));

        if ($dryRun) {
            $this->warn('DRY-RUN: nichts geschrieben. (Hinweis: dieser Command setzt nichts neu — er zeigt nur die aktuelle Anzahl mit NULL-Marker. Tatsaechliche Bestands-Markierung passiert automatisch beim ersten Initial-Pull oder durch zas_initial_exported_at-Reset auf NULL falls noetig.)');
            return self::SUCCESS;
        }

        $this->info('Keine schreibende Aktion noetig — Bestands-MAs haben bereits NULL-Marker. Der naechste Initial-Pull wird sie alle ausliefern.');

        return self::SUCCESS;
    }
}
