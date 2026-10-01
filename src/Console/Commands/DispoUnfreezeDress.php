<?php

namespace Platform\Recruiting\Console\Commands;

use Illuminate\Console\Command;
use Platform\Recruiting\Models\RecDispoAssignment;
use Platform\Recruiting\Models\RecDispoEvent;

/**
 * Nimmt ein festgeschriebenes Waeschepaket an den Einbuchungen einer
 * Veranstaltung zurueck.
 *
 * Grund (Fix-Runde 3, Befund 4): DispoDressResolver::freeze() ueberspringt
 * jede Einbuchung mit gesetztem dress_frozen_at — was beim ersten Versand
 * falsch gesetzt wurde, blieb dauerhaft falsch, und die einzige Abhilfe waere
 * SQL auf prod gewesen. Nach dem Zuruecknehmen greift wieder die normale
 * Aufloesungskette (VA-/Taetigkeits-Zuordnung), ein erneuter Versand
 * schreibt neu fest.
 *
 * Geleert werden alle drei Stempel-Spalten: Referenz, Zeitpunkt UND die
 * Textkopie — sonst zeigte die Einsatz-Seite weiter den alten Wortlaut
 * (EmployeeAssignments::eventGroups() bevorzugt die Kopie).
 *
 * @see self::unfreeze() Reine Logik ohne $this->option()/$this->info(),
 *      direkt aus dem Test aufrufbar (Muster DispoResetCommand).
 */
class DispoUnfreezeDress extends Command
{
    protected $signature = 'recruiting:dispo-unfreeze-dress {eventId : id der Veranstaltung} {--taetigkeit= : nur diese Taetigkeit (exakter Wert wie in den Einbuchungen)} {--dry-run : nur zeigen, was zurueckgenommen wuerde}';

    protected $description = 'Nimmt das festgeschriebene Waeschepaket an den Einbuchungen einer Veranstaltung zurueck';

    /**
     * @return array{event: ?RecDispoEvent, rows: list<array{id:int, datum:string, taetigkeit:string, package_id:?int}>, cleared: int}
     */
    public static function unfreeze(int $eventId, ?string $taetigkeit, bool $dryRun): array
    {
        $event = RecDispoEvent::query()->find($eventId);
        if ($event === null) {
            return ['event' => null, 'rows' => [], 'cleared' => 0];
        }

        $query = RecDispoAssignment::query()
            ->where('rec_dispo_event_id', $eventId)
            ->whereNotNull('dress_frozen_at');

        // Exakter Vergleich, keine Normalisierung: taetigkeit ist ZAS-Freitext
        // und wird ueberall so behandelt, wie er geliefert wurde.
        if ($taetigkeit !== null && $taetigkeit !== '') {
            $query->where('taetigkeit', $taetigkeit);
        }

        $rows = [];
        foreach ((clone $query)->orderBy('datum')->orderBy('id')->get() as $assignment) {
            $rows[] = [
                'id'         => (int) $assignment->id,
                'datum'      => (string) ($assignment->datum?->format('Y-m-d') ?? ''),
                'taetigkeit' => (string) $assignment->taetigkeit,
                'package_id' => $assignment->rec_dispo_dress_package_id !== null
                    ? (int) $assignment->rec_dispo_dress_package_id
                    : null,
            ];
        }

        if ($dryRun || $rows === []) {
            return ['event' => $event, 'rows' => $rows, 'cleared' => 0];
        }

        $cleared = $query->update([
            'rec_dispo_dress_package_id' => null,
            'dress_frozen_at'            => null,
            'dress_items_text'           => null,
        ]);

        return ['event' => $event, 'rows' => $rows, 'cleared' => (int) $cleared];
    }

    public function handle(): int
    {
        $eventId = (int) $this->argument('eventId');
        $taetigkeit = $this->option('taetigkeit');
        $taetigkeit = $taetigkeit === null ? null : (string) $taetigkeit;
        $dryRun = (bool) $this->option('dry-run');

        $result = self::unfreeze($eventId, $taetigkeit, $dryRun);

        if ($result['event'] === null) {
            $this->error('Veranstaltung ' . $eventId . ' nicht gefunden.');

            return self::FAILURE;
        }

        $this->line('Veranstaltung ' . $eventId . ' — ' . ($result['event']->name ?? $result['event']->einsatz_ref));
        if ($taetigkeit !== null && $taetigkeit !== '') {
            $this->line('Eingeschraenkt auf Taetigkeit: ' . $taetigkeit);
        }

        if ($result['rows'] === []) {
            $this->info('Keine festgeschriebene Einbuchung gefunden — nichts zu tun.');

            return self::SUCCESS;
        }

        foreach ($result['rows'] as $row) {
            $this->line(sprintf(
                '  Einbuchung #%d · %s · %s · Paket %s',
                $row['id'],
                $row['datum'] !== '' ? $row['datum'] : '—',
                $row['taetigkeit'] !== '' ? $row['taetigkeit'] : '(ohne Taetigkeit)',
                $row['package_id'] !== null ? '#' . $row['package_id'] : '—'
            ));
        }

        if ($dryRun) {
            $this->info(count($result['rows']) . ' Einbuchung(en) wuerden zurueckgenommen; ohne --dry-run ausfuehren.');

            return self::SUCCESS;
        }

        $this->info($result['cleared'] . ' Einbuchung(en) zurueckgenommen. Der naechste Versand schreibt neu fest.');

        return self::SUCCESS;
    }
}
