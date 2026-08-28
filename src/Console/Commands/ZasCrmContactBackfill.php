<?php

namespace Platform\Recruiting\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Platform\Recruiting\Models\RecApplicantSettings;
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
 *   php artisan recruiting:zas-crm-contact-backfill --team=7
 *   Laeuft stuendlich im Scheduler (RecruitingServiceProvider) mit
 *   --scheduled, Ausgabe in storage/logs/zas-contact-backfill.log. Skips sind
 *   sichtbar unter Disposition -> Einstellungen -> CRM-Zuordnung offen.
 *
 * --scheduled ist der EINZIGE Aufruf, der abschaltbar ist und einen Team-Anker
 * erzwingt (fail-closed): ohne recruiting.zas.inbound_team_id passiert nichts
 * (sonst liefe der stuendliche Lauf ueber ALLE Mandanten), und der Haken
 * "Automatischer CRM-Abgleich" unter Disposition -> Einstellungen kann ihn
 * stilllegen. Ein Aufruf von Hand bleibt unbeeinflusst.
 */
class ZasCrmContactBackfill extends Command
{
    protected $signature = 'recruiting:zas-crm-contact-backfill
        {--dry-run : Nur Match-Entscheidungen anzeigen, nichts schreiben}
        {--limit= : Max. Anzahl MA in diesem Lauf}
        {--imported-only : Nur aus ZAS importierte MA (rec_zas_inbound_file_id gesetzt)}
        {--team= : Nur MA dieses Teams}
        {--scheduled : Aufruf aus dem Scheduler — Team = recruiting.zas.inbound_team_id, respektiert dispo_contact_backfill_enabled}';

    protected $description = 'Verlinkt/erstellt CRM-Kontakte fuer MA ohne Kontakt-Link (ZAS-Import-Backfill)';

    public function handle(ZasEmployeeContactLinker $linker): int
    {
        $counts = $this->backfill($linker, [
            'dry-run'       => (bool) $this->option('dry-run'),
            'limit'         => $this->option('limit'),
            'imported-only' => (bool) $this->option('imported-only'),
            'team'          => $this->option('team'),
            'scheduled'     => (bool) $this->option('scheduled'),
        ], function (string $level, string $text = ''): void {
            match ($level) {
                'error' => $this->error($text),
                'warn'  => $this->warn($text),
                'info'  => $this->info($text),
                'blank' => $this->newLine(),
                default => $this->line($text),
            };
        });

        return $counts['failed'] === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Auswahl + Kaskade ohne Artisan-Lebenszyklus (Probe-Muster
     * DispoEscalateCommand::escalate()): alle Konsolen-Ausgaben laufen ueber
     * $emit(level, text) mit level aus line|info|warn|error|blank, damit ein
     * Test die Entscheidungen pruefen kann ohne Command-Runner.
     *
     * @param array{dry-run?:bool, limit?:mixed, imported-only?:bool, team?:mixed, scheduled?:bool} $opts
     * @return array{link:int, create:int, skip:int, failed:int, total:int, ran:bool}
     */
    public function backfill(ZasEmployeeContactLinker $linker, array $opts, callable $emit): array
    {
        $dryRun = (bool) ($opts['dry-run'] ?? false);
        $limit  = ($opts['limit'] ?? null) !== null && $opts['limit'] !== ''
            ? max(1, (int) $opts['limit'])
            : null;
        $counts = ['link' => 0, 'create' => 0, 'skip' => 0, 'failed' => 0, 'total' => 0, 'ran' => false];

        // Team-Anker: --team gewinnt (ausdrueckliche Wahl), --scheduled nimmt
        // sonst das ZAS-Anker-Team aus der Konfiguration.
        $teamId = ($opts['team'] ?? null) !== null && $opts['team'] !== '' ? (int) $opts['team'] : null;

        if ($opts['scheduled'] ?? false) {
            $anchorTeam = (int) config('recruiting.zas.inbound_team_id');
            if ($anchorTeam <= 0) {
                // Fail-closed: ohne Anker-Team wuerde der stuendliche Lauf ueber
                // ALLE Mandanten gehen — lieber gar nichts tun und Bescheid geben.
                Log::warning('zas_contact_backfill_skipped', ['reason' => 'kein inbound_team_id']);
                $emit('line', 'Kein ZAS-Anker-Team konfiguriert (recruiting.zas.inbound_team_id) — Lauf uebersprungen.');

                return $counts;
            }

            $settings = RecApplicantSettings::getOrCreateForTeam($anchorTeam);
            // Fehlende/null-Einstellung = eingeschaltet (Default AN); nur ein
            // ausdrueckliches false legt den automatischen Lauf still.
            if ($settings->getSetting('dispo_contact_backfill_enabled') === false) {
                $emit('line', 'Automatischer Abgleich deaktiviert (Disposition → Einstellungen)');

                return $counts;
            }

            $teamId ??= $anchorTeam;
        }

        $query = RecEmployee::query()
            ->where('is_active', true)
            ->whereDoesntHave('crmContactLinks')
            ->orderBy('id');

        if ($teamId !== null) {
            $query->where('team_id', $teamId);
        }
        if ($opts['imported-only'] ?? false) {
            $query->whereNotNull('rec_zas_inbound_file_id');
        }
        if ($limit !== null) {
            $query->limit($limit);
        }

        $employees = $query->get();
        $counts['total'] = $employees->count();
        $counts['ran'] = true;

        foreach ($employees as $employee) {
            $name = trim(($employee->last_name ?? '') . ', ' . ($employee->first_name ?? ''));
            try {
                $decision = $linker->decide($employee);

                if ($decision['action'] === 'skip') {
                    $counts['skip']++;
                    $emit('line', "SKIP   #{$employee->id} {$name} — {$decision['reason']}");
                    continue;
                }

                if ($decision['action'] === 'link') {
                    $counts['link']++;
                    $emit('line', ($dryRun ? 'WUERDE ' : '') . "LINK   #{$employee->id} {$name} -> Kontakt #{$decision['contact_id']} ({$decision['contact_name']}, Match: {$decision['matched_by']})");
                } else {
                    $counts['create']++;
                    $emit('line', ($dryRun ? 'WUERDE ' : '') . "CREATE #{$employee->id} {$name} (E-Mail: " . ($decision['email'] ?? '—') . ', Tel: ' . ($decision['phone'] ?? '—') . ')');
                }

                if (!$dryRun) {
                    $result = $linker->execute($employee, $decision);
                    foreach ($result['warnings'] as $w) {
                        $emit('warn', "       #{$employee->id}: {$w}");
                    }
                }
            } catch (\Throwable $e) {
                $counts['failed']++;
                $emit('error', "FEHLER #{$employee->id} {$name} — {$e->getMessage()}");
                // Runde 4 (#0): der Befehl laeuft stuendlich im Scheduler — Konsolen-Ausgabe
                // verpufft dort. Fehler zusaetzlich ins Log, damit sie nicht untergehen.
                Log::error('zas_contact_backfill_failed', [
                    'employee_id' => $employee->id, 'name' => $name, 'dry_run' => $dryRun, 'error' => $e->getMessage(),
                ]);
            }
        }

        if ($counts['failed'] > 0 && !$dryRun) {
            Log::warning('zas_contact_backfill_summary', [
                'link' => $counts['link'], 'create' => $counts['create'], 'skip' => $counts['skip'],
                'failed' => $counts['failed'], 'total' => $counts['total'],
            ]);
        }

        $emit('blank');
        $mode = $dryRun ? 'DRY-RUN (nichts geschrieben)' : 'AUSGEFUEHRT';
        $emit('info', "{$mode}: {$counts['link']} verlinkt, {$counts['create']} neu angelegt, {$counts['skip']} uebersprungen, {$counts['failed']} Fehler — von {$counts['total']} MA ohne Kontakt-Link");

        return $counts;
    }
}
