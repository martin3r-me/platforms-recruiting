<?php

namespace Platform\Recruiting\Console\Commands;

use Illuminate\Console\Command;
use Platform\Recruiting\Models\RecDispoAssignment;
use Platform\Recruiting\Models\RecDispoEvent;

/**
 * Sauberer Start fuer die Dispo: leert NUR rec_dispo_assignments +
 * rec_dispo_events. Bewusst NICHT angefasst: rec_zas_dispo_inbound_files
 * (Rohdaten-Historie), rec_dispo_filiale_settings, rec_employees, Settings.
 *
 * Guard: ohne --force wird NICHTS geloescht, nur die aktuellen Zaehler
 * angezeigt. Reihenfolge beim Loeschen: Einbuchungen zuerst, dann Events
 * (Assignments haengen per FK+cascadeOnDelete an Events, aber wir wollen
 * exakte Vorher/Nachher-Zaehler statt Cascade-Nebenwirkung).
 *
 * @see self::reset() Reine Logik ohne $this->option()/$this->info() Co.,
 *      per Probe-Muster (siehe DispoEscalateCommand) ohne Artisan-
 *      Lebenszyklus direkt aufrufbar — siehe tests/Integration/DispoResetCommandTest.php.
 */
class DispoResetCommand extends Command
{
    protected $signature = 'recruiting:dispo-reset {--force}';
    protected $description = 'Leert die Dispo-Tabellen (Veranstaltungen + Einbuchungen) fuer einen sauberen Start';

    public function handle(): int
    {
        $force = (bool) $this->option('force');
        $result = $this->reset($force);

        if (!$result['deleted']) {
            $this->info(sprintf(
                'Wuerde %d Veranstaltungen + %d Einbuchungen loeschen; mit --force ausfuehren.',
                $result['events'],
                $result['assignments']
            ));

            return self::SUCCESS;
        }

        $this->info(sprintf(
            'Geloescht: %d Veranstaltungen, %d Einbuchungen.',
            $result['events'],
            $result['assignments']
        ));

        return self::SUCCESS;
    }

    /**
     * @return array{events: int, assignments: int, deleted: bool}
     */
    protected function reset(bool $force): array
    {
        $eventCount = RecDispoEvent::count();
        $assignmentCount = RecDispoAssignment::count();

        if (!$force) {
            return ['events' => $eventCount, 'assignments' => $assignmentCount, 'deleted' => false];
        }

        RecDispoAssignment::query()->delete();
        RecDispoEvent::query()->delete();

        return ['events' => $eventCount, 'assignments' => $assignmentCount, 'deleted' => true];
    }
}
