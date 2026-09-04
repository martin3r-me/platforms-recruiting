<?php

namespace Platform\Recruiting\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Platform\Recruiting\Models\RecEmployee;
use Platform\Recruiting\Services\Zas\ContactPhoneSync;

/**
 * Bestands-Abgleich Akte -> CRM-Kontakt-Nummer (Vorfall RG19734, 04.09.):
 * korrigierte MA-Nummern standen nur in der Akte, die Kontakte hielten den
 * alten Stand — eingehende Antworten der MA landeten dadurch in
 * unverknuepften Threads. Laufend haelt der RecEmployeePhoneSyncObserver
 * die Kontakte aktuell; dieser Befehl zieht den Altbestand einmal glatt.
 *
 * Aufruf:
 *   php artisan recruiting:sync-contact-phones --dry-run
 *   php artisan recruiting:sync-contact-phones
 */
class SyncContactPhonesCommand extends Command
{
    protected $signature = 'recruiting:sync-contact-phones
        {--dry-run : Nur zeigen, was passieren wuerde — nichts schreiben}';

    protected $description = 'Zieht die Telefonnummern der verknuepften CRM-Kontakte auf den Stand der MA-Akte';

    public function handle(ContactPhoneSync $sync): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $counts = ['synced' => 0, 'match' => 0, 'no_phone' => 0, 'no_contact' => 0, 'unparseable' => 0, 'no_type' => 0];

        RecEmployee::query()
            ->where('is_active', true)
            ->whereNotNull('phone')
            ->where('phone', '!=', '')
            ->orderBy('id')
            ->chunkById(200, function ($employees) use ($sync, $dryRun, &$counts) {
                foreach ($employees as $employee) {
                    $r = $sync->syncEmployee($employee, $dryRun);
                    $counts[$r['status']] = ($counts[$r['status']] ?? 0) + 1;
                    if ($r['status'] === 'synced') {
                        $this->line(($dryRun ? 'WUERDE ' : '') . "SYNC  {$employee->personnel_number}  {$employee->last_name}, {$employee->first_name}  -> {$employee->phone} ({$r['contacts']} Kontakt(e))");
                    }
                    if ($r['status'] === 'unparseable') {
                        $this->warn("UNPARSEBAR  {$employee->personnel_number}  '{$employee->phone}'");
                    }
                }
            });

        $mode = $dryRun ? 'DRY-RUN (nichts geschrieben)' : 'AUSGEFUEHRT';
        $this->newLine();
        $this->info(sprintf(
            '%s: %d Kontakte angeglichen, %d bereits passend, %d ohne Kontakt-Link, %d ohne Nummer, %d unparsebar.',
            $mode, $counts['synced'], $counts['match'], $counts['no_contact'], $counts['no_phone'], $counts['unparseable']
        ));
        if (!$dryRun && $counts['synced'] > 0) {
            Log::info('contact_phones_synced', $counts);
        }

        return self::SUCCESS;
    }
}
