<?php

namespace Platform\Recruiting\Tests\Integration;

use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Console\Commands\DispoTestVaCommand;
use Platform\Recruiting\Models\RecDispoAssignment;
use Platform\Recruiting\Models\RecDispoEvent;

/**
 * recruiting:dispo-test-va — bucht einen (Test-)MA in eine Test-VA fuer den
 * Bestaetigungs-Flow. Getestet wird die reine Logik (createTestVa/removeTestVa)
 * auf den zwei Dispo-Tabellen (kein rec_employees noetig — MA-ID wird als int
 * uebergeben). Muster wie DispoResetCommandTest (Capsule/SQLite, kein Testbench).
 */
class DispoTestVaCommandTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        $container = Container::getInstance();

        $capsule = new Capsule();
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $capsule->setEventDispatcher(new Dispatcher($container));
        $capsule->setAsGlobal();
        $capsule->bootEloquent();
        Model::unguard();
        Model::clearBootedModels();

        $container->instance('db', $capsule->getDatabaseManager());
        $container->instance('db.schema', $capsule->getConnection()->getSchemaBuilder());
        Facade::setFacadeApplication($container);

        $own = dirname(__DIR__, 2);
        // Volle Dispo-Spalten-Kette (Event+Assignment), damit das In-Memory-
        // Schema dem Prod-Stand entspricht (ansprechpartner/individual_note/
        // confirmation-Felder etc. kamen per spaeterer Migration).
        foreach ([
            'database/migrations/2026_08_12_000001_create_rec_dispo_events_table.php',
            'database/migrations/2026_08_12_000002_create_rec_dispo_assignments_table.php',
            'database/migrations/2026_08_14_000001_add_confirmation_fields_to_rec_dispo_assignments.php',
            'database/migrations/2026_08_14_000002_add_vorlauf_minuten_to_rec_dispo_events.php',
            'database/migrations/2026_08_19_000001_add_ansprechpartner_to_rec_dispo_events.php',
            'database/migrations/2026_08_20_000001_add_filiale_to_rec_dispo_events.php',
            'database/migrations/2026_08_20_000002_add_individual_note_to_rec_dispo_assignments.php',
            'database/migrations/2026_08_21_000001_add_filial_nr_to_rec_dispo_events.php',
            'database/migrations/2026_08_24_000002_add_escalation_fields_to_rec_dispo_assignments.php',
            'database/migrations/2026_08_24_000003_add_alarm_message_id_to_rec_dispo_events.php',
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

    private function command(): DispoTestVaCommand
    {
        return new DispoTestVaCommand();
    }

    public function test_single_day_creates_one_bookable_assignment(): void
    {
        $result = $this->command()->createTestVa(42, 'RG42', 1, 'TEST-VA');

        $this->assertSame(1, RecDispoEvent::count());
        $this->assertSame(1, RecDispoAssignment::count());
        $this->assertSame($result['days'], 1);

        $event = RecDispoEvent::first();
        $this->assertSame('TEST-VA', $event->einsatz_ref);
        $this->assertSame(30, (int) $event->vorlauf_minuten);
        $this->assertNotEmpty($event->venue_text);
        $this->assertNotEmpty($event->dresscode);

        $assignment = RecDispoAssignment::first();
        $this->assertSame(RecDispoAssignment::STATUS_AUFTRAG, (int) $assignment->status_id);
        $this->assertSame(42, (int) $assignment->rec_employee_id);
        $this->assertNull($assignment->missing_since);
        $this->assertNull($assignment->deletion_marked_at);
        $this->assertNull($assignment->confirmed_at);
        $this->assertNull($assignment->reminder_sent_at);
        // ab morgen -> immer >= heute
        $this->assertGreaterThanOrEqual(now()->toDateString(), $assignment->datum->toDateString());
    }

    public function test_multi_day_creates_one_assignment_per_day_with_note_on_first(): void
    {
        $result = $this->command()->createTestVa(7, 'RG7', 3, 'TEST-VA');

        $this->assertSame(3, RecDispoAssignment::count());
        $this->assertSame(3, $result['days']);

        $days = RecDispoAssignment::query()->orderBy('datum')->get();
        $this->assertNotNull($days[0]->individual_note);
        $this->assertNull($days[1]->individual_note);
        $this->assertNull($days[2]->individual_note);
        // Datumsfolge lueckenlos
        $this->assertSame(
            $days[0]->datum->copy()->addDay()->toDateString(),
            $days[1]->datum->toDateString()
        );
    }

    public function test_rerun_is_idempotent_no_duplicate_bookings(): void
    {
        $this->command()->createTestVa(42, 'RG42', 1, 'TEST-VA');
        $this->command()->createTestVa(42, 'RG42', 1, 'TEST-VA');

        $this->assertSame(1, RecDispoEvent::count());
        $this->assertSame(1, RecDispoAssignment::count());
    }

    public function test_second_employee_in_same_va_keeps_both(): void
    {
        $this->command()->createTestVa(42, 'RG42', 1, 'TEST-VA');
        $this->command()->createTestVa(43, 'RG43', 1, 'TEST-VA');

        $this->assertSame(1, RecDispoEvent::count());
        $this->assertSame(2, RecDispoAssignment::count());
    }

    public function test_remove_deletes_event_and_its_assignments(): void
    {
        $this->command()->createTestVa(42, 'RG42', 3, 'TEST-VA');

        $removed = $this->command()->removeTestVa('TEST-VA');

        $this->assertSame(['events' => 1, 'assignments' => 3], $removed);
        $this->assertSame(0, RecDispoEvent::count());
        $this->assertSame(0, RecDispoAssignment::count());
    }

    public function test_remove_unknown_ref_is_noop(): void
    {
        $removed = $this->command()->removeTestVa('NOPE');

        $this->assertSame(['events' => 0, 'assignments' => 0], $removed);
    }
}
