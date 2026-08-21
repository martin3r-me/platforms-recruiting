<?php

namespace Platform\Recruiting\Tests\Integration;

use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Livewire\Dispo\Events\Show;
use Platform\Recruiting\Models\RecDispoAssignment;
use Platform\Recruiting\Models\RecDispoEvent;

/**
 * Nagelt den Persistenz-Vertrag des individuellen Hinweises fest (der bisher
 * ungetestet war): saveNote/saveNoteFromModal schreiben NUR auf die Einbuchungen
 * DIESES Events UND DIESES Mitarbeiters, leer -> null, und der Modal-Null-Guard
 * schreibt gar nichts. Echte Modelle auf SQLite via Capsule (kein Testbench).
 */
class DispoIndividualNoteTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        $container = Container::getInstance();

        $capsule = new Capsule();
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        // Dispatcher noetig, damit die creating-Hooks (uuid) der Modelle feuern.
        $capsule->setEventDispatcher(new Dispatcher($container));
        $capsule->setAsGlobal();
        $capsule->bootEloquent();
        Model::unguard();
        // Erzwingt Re-Boot der Modelle unter DIESEM Dispatcher — sonst haengt der
        // creating-uuid-Hook im Gesamtlauf noch am Dispatcher einer frueheren
        // Testklasse und feuert nicht (uuid NOT NULL bricht sonst).
        Model::clearBootedModels();

        $container->instance('db', $capsule->getDatabaseManager());
        $container->instance('db.schema', $capsule->getConnection()->getSchemaBuilder());
        Facade::setFacadeApplication($container);

        // Nur die Dispo-Migrationen des EIGENEN Arbeitsbaums (zwei Ebenen ueber
        // tests/Integration/ — funktioniert im Haupt-Checkout wie im Worktree).
        $own = dirname(__DIR__, 2);
        foreach ([
            'database/migrations/2026_08_12_000001_create_rec_dispo_events_table.php',
            'database/migrations/2026_08_12_000002_create_rec_dispo_assignments_table.php',
            'database/migrations/2026_08_14_000001_add_confirmation_fields_to_rec_dispo_assignments.php',
            'database/migrations/2026_08_14_000002_add_vorlauf_minuten_to_rec_dispo_events.php',
            'database/migrations/2026_08_19_000001_add_ansprechpartner_to_rec_dispo_events.php',
            'database/migrations/2026_08_20_000001_add_filiale_to_rec_dispo_events.php',
            'database/migrations/2026_08_20_000002_add_individual_note_to_rec_dispo_assignments.php',
            'database/migrations/2026_08_21_000001_add_filial_nr_to_rec_dispo_events.php',
        ] as $relative) {
            $path = $own . '/' . $relative;
            if (!file_exists($path)) {
                throw new \RuntimeException("Migration fehlt: {$path}");
            }
            (require $path)->up();
        }
    }

    public static function tearDownAfterClass(): void
    {
        Facade::clearResolvedInstances();
    }

    protected function setUp(): void
    {
        Capsule::table('rec_dispo_assignments')->delete();
        Capsule::table('rec_dispo_events')->delete();
    }

    /**
     * Baut ein Event mit vier Einbuchungen:
     *  - MA 5, zwei Tage (Mehrtages-Fall)
     *  - MA 6, ein Tag (darf NICHT mitbeschrieben werden)
     *  - ungematchte PNr (rec_employee_id null)
     * und ein ZWEITES Event mit MA 5 (darf NICHT mitbeschrieben werden).
     *
     * @return array{0: int, 1: int} [eventId, otherEventId]
     */
    private function seed(): array
    {
        $event = RecDispoEvent::create(['einsatz_ref' => 'RG-EVT-1', 'name' => 'Test-VA']);
        $other = RecDispoEvent::create(['einsatz_ref' => 'RG-EVT-2', 'name' => 'Andere-VA']);

        RecDispoAssignment::create(['ds_ref' => 'DS-A', 'rec_dispo_event_id' => $event->id, 'pnr_raw' => 'RG5', 'rec_employee_id' => 5, 'datum' => '2026-09-01']);
        RecDispoAssignment::create(['ds_ref' => 'DS-B', 'rec_dispo_event_id' => $event->id, 'pnr_raw' => 'RG5', 'rec_employee_id' => 5, 'datum' => '2026-09-02']);
        RecDispoAssignment::create(['ds_ref' => 'DS-C', 'rec_dispo_event_id' => $event->id, 'pnr_raw' => 'RG6', 'rec_employee_id' => 6, 'datum' => '2026-09-01']);
        RecDispoAssignment::create(['ds_ref' => 'DS-D', 'rec_dispo_event_id' => $event->id, 'pnr_raw' => 'RG9', 'rec_employee_id' => null, 'datum' => '2026-09-01']);
        RecDispoAssignment::create(['ds_ref' => 'DS-E', 'rec_dispo_event_id' => $other->id, 'pnr_raw' => 'RG5', 'rec_employee_id' => 5, 'datum' => '2026-09-01']);

        return [$event->id, $other->id];
    }

    private function component(int $eventId): Show
    {
        $c = new Show();
        $c->eventId = $eventId;

        return $c;
    }

    public function test_saveNoteFromModal_writes_only_this_employee_in_this_event(): void
    {
        [$eventId, $otherId] = $this->seed();

        $c = $this->component($eventId);
        $c->noteEmployeeId = 5;
        $c->noteDraft = '  Türcode 1234  ';
        $c->saveNoteFromModal();

        // Beide Tage von MA 5 in DIESEM Event, getrimmt.
        $this->assertSame('Türcode 1234', RecDispoAssignment::where('ds_ref', 'DS-A')->value('individual_note'));
        $this->assertSame('Türcode 1234', RecDispoAssignment::where('ds_ref', 'DS-B')->value('individual_note'));
        // MA 6 unberuehrt, ungematchte Zeile unberuehrt, anderes Event unberuehrt.
        $this->assertNull(RecDispoAssignment::where('ds_ref', 'DS-C')->value('individual_note'));
        $this->assertNull(RecDispoAssignment::where('ds_ref', 'DS-D')->value('individual_note'));
        $this->assertNull(RecDispoAssignment::where('ds_ref', 'DS-E')->value('individual_note'));
        // Modal ist danach zu.
        $this->assertFalse($c->showNoteModal);
        $this->assertNull($c->noteEmployeeId);
    }

    public function test_empty_draft_clears_note_to_null(): void
    {
        [$eventId] = $this->seed();

        $c = $this->component($eventId);
        $c->noteEmployeeId = 5;
        $c->noteDraft = 'erst was';
        $c->saveNoteFromModal();
        $this->assertSame('erst was', RecDispoAssignment::where('ds_ref', 'DS-A')->value('individual_note'));

        $c->noteEmployeeId = 5;
        $c->noteDraft = '   ';
        $c->saveNoteFromModal();
        $this->assertNull(RecDispoAssignment::where('ds_ref', 'DS-A')->value('individual_note'));
        $this->assertNull(RecDispoAssignment::where('ds_ref', 'DS-B')->value('individual_note'));
    }

    public function test_null_guard_writes_nothing(): void
    {
        [$eventId] = $this->seed();

        $c = $this->component($eventId);
        $c->noteEmployeeId = null;
        $c->noteDraft = 'darf nicht landen';
        $c->saveNoteFromModal();

        foreach (['DS-A', 'DS-B', 'DS-C', 'DS-D', 'DS-E'] as $ds) {
            $this->assertNull(RecDispoAssignment::where('ds_ref', $ds)->value('individual_note'));
        }
    }
}
