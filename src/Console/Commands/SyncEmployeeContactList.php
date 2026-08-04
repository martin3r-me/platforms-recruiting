<?php

namespace Platform\Recruiting\Console\Commands;

use Illuminate\Console\Command;
use Platform\Recruiting\Models\RecApplicantSettings;
use Platform\Recruiting\Services\EmployeeContactListSyncService;

/**
 * Voll-Sync des MA-Kontaktbuchs (sync-verwaltete CRM-Kontaktliste).
 * Laeuft stuendlich im Scheduler (ohne --force); manueller Lauf bzw. Panel zusaetzlich.
 *
 *   php artisan recruiting:sync-employee-contact-list --dry-run
 *   php artisan recruiting:sync-employee-contact-list --team=3
 *   php artisan recruiting:sync-employee-contact-list --team=3 --force
 */
class SyncEmployeeContactList extends Command
{
    protected $signature = 'recruiting:sync-employee-contact-list
        {--team= : Nur dieses Team syncen}
        {--dry-run : Nichts schreiben, nur Report}
        {--force : Entfernungs-Schwellen-Guard uebersteuern (leere Soll-Menge bleibt gesperrt)}';

    protected $description = 'Synct das MA-Kontaktbuch (CRM-Kontaktliste "Aktive Mitarbeiter") mit den aktiven Mitarbeitern';

    public function handle(EmployeeContactListSyncService $sync): int
    {
        // Bewusst KEIN JSON-Path-Where (settings->employee_contact_list_id):
        // verhaelt sich je nach MySQL-Version unterschiedlich und waere
        // ungetestete Flaeche. Es sind eine Handvoll Teams — alle Zeilen
        // laden und in PHP filtern.
        $teamIds = $this->option('team')
            ? [(int) $this->option('team')]
            : RecApplicantSettings::query()->get()
                ->filter(fn ($s) => $s->getSetting(EmployeeContactListSyncService::SETTING_LIST_ID))
                ->pluck('team_id')
                ->map(fn ($id) => (int) $id)
                ->all();

        if ($teamIds === []) {
            $this->warn('Kein Team mit konfiguriertem MA-Kontaktbuch gefunden.');

            return Command::SUCCESS;
        }

        $rows = [];
        $failed = false;

        foreach ($teamIds as $teamId) {
            $report = $sync->syncAll(
                $teamId,
                dryRun: (bool) $this->option('dry-run'),
                force: (bool) $this->option('force'),
            );

            $rows[] = [
                $teamId,
                $report->status,
                $report->added,
                $report->removed,
                $report->normalized,
                $report->unchanged,
                $report->skipped_without_contact,
                $report->hidden_from_carddav,
                $report->ambiguous_multi_link,
            ];

            if ($report->status === 'guard_tripped') {
                $failed = true;
                $this->error("Team {$teamId}: Guard ausgeloest ({$report->removed} Entfernungen). Schwellen-Guard mit --force uebersteuerbar; leere Soll-Menge nicht.");
            } elseif ($report->status === 'list_missing') {
                $failed = true;
                $this->error("Team {$teamId}: konfigurierte Liste fehlt oder ist inaktiv — im Panel neu anlegen.");
            } elseif ($report->status === 'partial') {
                $failed = true;
                $this->warn("Team {$teamId}: Sync unvollstaendig (partial) — mindestens ein Write fehlgeschlagen, Details im Laravel-Log unter [EmployeeContactListSync]. last_sync wurde nicht aktualisiert.");
            }
        }

        $this->table(
            ['Team', 'Status', '+add', '-rem', '~norm', '=same', 'ohne Link', 'hidden', 'multi-link'],
            $rows,
        );

        if ($this->option('dry-run')) {
            $this->info('DRY-RUN — nichts geschrieben.');
        }

        return $failed ? Command::FAILURE : Command::SUCCESS;
    }
}
