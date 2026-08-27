<?php

namespace Platform\Recruiting\Console\Commands;

use Illuminate\Console\Command;
use Platform\Recruiting\Models\RecDispoAssignment;
use Platform\Recruiting\Models\RecDispoAttachment;
use Platform\Recruiting\Models\RecDispoEvent;
use Platform\Recruiting\Services\Zas\Dispo\DispoAttachmentStore;

/**
 * Sauberer Start fuer die Dispo: leert NUR rec_dispo_assignments +
 * rec_dispo_events + rec_dispo_attachments. Bewusst NICHT angefasst:
 * rec_zas_dispo_inbound_files (Rohdaten-Historie), rec_dispo_filiale_settings,
 * rec_employees, Settings.
 *
 * Guard: ohne --force wird NICHTS geloescht, nur die aktuellen Zaehler
 * angezeigt. Reihenfolge beim Loeschen: Anhaenge zuerst (Dateien + Zeilen),
 * dann Einbuchungen, dann Events (Assignments haengen per FK+cascadeOnDelete
 * an Events, aber wir wollen exakte Vorher/Nachher-Zaehler statt
 * Cascade-Nebenwirkung).
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
        $result = $this->reset($force, $force ? DispoAttachmentStore::default() : null);

        if (!$result['deleted']) {
            $this->info(sprintf(
                'Wuerde %d Veranstaltungen + %d Einbuchungen + %d Anhaenge loeschen; mit --force ausfuehren.',
                $result['events'],
                $result['assignments'],
                $result['attachments']
            ));

            return self::SUCCESS;
        }

        $this->info(sprintf(
            'Geloescht: %d Veranstaltungen, %d Einbuchungen, %d Anhaenge.',
            $result['events'],
            $result['assignments'],
            $result['attachments']
        ));

        return self::SUCCESS;
    }

    /**
     * @return array{events: int, assignments: int, attachments: int, deleted: bool}
     */
    protected function reset(bool $force, ?DispoAttachmentStore $attachments = null): array
    {
        $eventCount = RecDispoEvent::count();
        $assignmentCount = RecDispoAssignment::count();
        $attachmentCount = RecDispoAttachment::count();

        if (!$force) {
            return ['events' => $eventCount, 'assignments' => $assignmentCount, 'attachments' => $attachmentCount, 'deleted' => false];
        }

        // Anhaenge zuerst (Dateien + Zeilen), dann Einbuchungen, dann Events.
        if ($attachments !== null) {
            $attachments->removeAll();
        } else {
            RecDispoAttachment::query()->delete(); // ohne Store (Tests): nur Zeilen
        }
        RecDispoAssignment::query()->delete();
        RecDispoEvent::query()->delete();

        return ['events' => $eventCount, 'assignments' => $assignmentCount, 'attachments' => $attachmentCount, 'deleted' => true];
    }
}
