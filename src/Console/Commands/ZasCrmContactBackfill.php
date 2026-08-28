<?php

namespace Platform\Recruiting\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Platform\Recruiting\Models\RecEmployee;
use Platform\Recruiting\Services\Zas\ZasEmployeeContactLinker;

/**
 * Backfill: CRM-Kontakte fuer Mitarbeiter ohne crm_contact_links —
 * primaer fuer aus ZAS importierte MA (der Import legt keine Kontakte an;
 * Recruiting-MA bekommen sie beim Anlegen gespiegelt). Ohne Kontakt sind
 * MA in der Kommunikations-Welt unsichtbar (keine WhatsApp-Portal-
 * Einladung, keine Thread-Zuordnung).
 *
 * Match-Kaskade je MA: E-Mail exakt -> Telefon (normalisierter
 * Ziffern-Suffix) -> neu anlegen. Mehrdeutige Treffer werden uebersprungen
 * statt blind verlinkt. Non-destruktiv: bestehende Kontakte werden nur
 * verlinkt, nie veraendert. Idempotent: Auswahl = MA ohne Link -> zweiter
 * Lauf findet nichts mehr.
 *
 * Aufruf:
 *   php artisan recruiting:zas-crm-contact-backfill --dry-run
 *   php artisan recruiting:zas-crm-contact-backfill --limit=100
 *   php artisan recruiting:zas-crm-contact-backfill --imported-only
 *   Laeuft stuendlich im Scheduler (RecruitingServiceProvider), Ausgabe in
 *   storage/logs/zas-contact-backfill.log. Skips sind sichtbar unter
 *   Disposition -> Einstellungen -> CRM-Zuordnung offen.
 */
class ZasCrmContactBackfill extends Command
{
    protected $signature = 'recruiting:zas-crm-contact-backfill
        {--dry-run : Nur Match-Entscheidungen anzeigen, nichts schreiben}
        {--limit= : Max. Anzahl MA in diesem Lauf}
        {--imported-only : Nur aus ZAS importierte MA (rec_zas_inbound_file_id gesetzt)}';

    protected $description = 'Verlinkt/erstellt CRM-Kontakte fuer MA ohne Kontakt-Link (ZAS-Import-Backfill)';

    public function handle(ZasEmployeeContactLinker $linker): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $limit  = $this->option('limit') !== null ? max(1, (int) $this->option('limit')) : null;

        $query = RecEmployee::query()
            ->where('is_active', true)
            ->whereDoesntHave('crmContactLinks')
            ->orderBy('id');

        if ($this->option('imported-only')) {
            $query->whereNotNull('rec_zas_inbound_file_id');
        }
        if ($limit !== null) {
            $query->limit($limit);
        }

        $employees = $query->get();
        $counts = ['link' => 0, 'create' => 0, 'skip' => 0, 'failed' => 0];

        foreach ($employees as $employee) {
            $name = trim(($employee->last_name ?? '') . ', ' . ($employee->first_name ?? ''));
            try {
                $decision = $linker->decide($employee);

                if ($decision['action'] === 'skip') {
                    $counts['skip']++;
                    $this->line("SKIP   #{$employee->id} {$name} — {$decision['reason']}");
                    continue;
                }

                if ($decision['action'] === 'link') {
                    $counts['link']++;
                    $this->line(($dryRun ? 'WUERDE ' : '') . "LINK   #{$employee->id} {$name} -> Kontakt #{$decision['contact_id']} ({$decision['contact_name']}, Match: {$decision['matched_by']})");
                } else {
                    $counts['create']++;
                    $this->line(($dryRun ? 'WUERDE ' : '') . "CREATE #{$employee->id} {$name} (E-Mail: " . ($decision['email'] ?? '—') . ', Tel: ' . ($decision['phone'] ?? '—') . ')');
                }

                if (!$dryRun) {
                    $result = $linker->execute($employee, $decision);
                    foreach ($result['warnings'] as $w) {
                        $this->warn("       #{$employee->id}: {$w}");
                    }
                }
            } catch (\Throwable $e) {
                $counts['failed']++;
                $this->error("FEHLER #{$employee->id} {$name} — {$e->getMessage()}");
                // Runde 4 (#0): der Befehl laeuft stuendlich im Scheduler — Konsolen-Ausgabe
                // verpufft dort. Fehler zusaetzlich ins Log, damit sie nicht untergehen.
                Log::error('zas_contact_backfill_failed', [
                    'employee_id' => $employee->id, 'name' => $name, 'dry_run' => $dryRun, 'error' => $e->getMessage(),
                ]);
            }
        }

        if ($counts['failed'] > 0 && !$dryRun) {
            Log::warning('zas_contact_backfill_summary', $counts + ['total' => $employees->count()]);
        }

        $this->newLine();
        $mode = $dryRun ? 'DRY-RUN (nichts geschrieben)' : 'AUSGEFUEHRT';
        $this->info("{$mode}: {$counts['link']} verlinkt, {$counts['create']} neu angelegt, {$counts['skip']} uebersprungen, {$counts['failed']} Fehler — von {$employees->count()} MA ohne Kontakt-Link");

        return $counts['failed'] === 0 ? self::SUCCESS : self::FAILURE;
    }
}
