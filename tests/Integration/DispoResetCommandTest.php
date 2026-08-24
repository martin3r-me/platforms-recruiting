<?php

namespace Platform\Recruiting\Tests\Integration;

use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Console\Commands\DispoResetCommand;
use Platform\Recruiting\Models\RecDispoAssignment;
use Platform\Recruiting\Models\RecDispoEvent;

/**
 * recruiting:dispo-reset (sauberer Start): leert NUR die zwei Dispo-Tabellen.
 * Guard-Vertrag: ohne --force wird NICHTS geloescht, nur gezaehlt.
 * Echte Modelle auf SQLite via Capsule (kein Testbench) — Muster wie
 * DispoIndividualNoteTest.
 */
class DispoResetCommandTest extends TestCase
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

    /** @return array{0: int, 1: int} [eventId, otherEventId] */
    private function seed(): array
    {
        $event = RecDispoEvent::create(['einsatz_ref' => 'RG-RESET-1', 'name' => 'Test-VA']);
        $other = RecDispoEvent::create(['einsatz_ref' => 'RG-RESET-2', 'name' => 'Andere-VA']);

        RecDispoAssignment::create(['ds_ref' => 'DS-R1', 'rec_dispo_event_id' => $event->id, 'pnr_raw' => 'RG5', 'rec_employee_id' => 5, 'datum' => '2026-09-01']);
        RecDispoAssignment::create(['ds_ref' => 'DS-R2', 'rec_dispo_event_id' => $event->id, 'pnr_raw' => 'RG6', 'rec_employee_id' => 6, 'datum' => '2026-09-01']);
        RecDispoAssignment::create(['ds_ref' => 'DS-R3', 'rec_dispo_event_id' => $other->id, 'pnr_raw' => 'RG5', 'rec_employee_id' => 5, 'datum' => '2026-09-01']);

        return [$event->id, $other->id];
    }

    private function probe(): DispoResetCommandProbe
    {
        return new DispoResetCommandProbe();
    }

    public function test_without_force_nothing_is_deleted(): void
    {
        $this->seed();

        $result = $this->probe()->probeReset(false);

        $this->assertSame(['events' => 2, 'assignments' => 3, 'deleted' => false], $result);
        $this->assertSame(2, RecDispoEvent::count());
        $this->assertSame(3, RecDispoAssignment::count());
    }

    public function test_with_force_both_tables_are_emptied(): void
    {
        $this->seed();

        $result = $this->probe()->probeReset(true);

        $this->assertSame(['events' => 2, 'assignments' => 3, 'deleted' => true], $result);
        $this->assertSame(0, RecDispoEvent::count());
        $this->assertSame(0, RecDispoAssignment::count());
    }

    public function test_reset_leaves_other_dispo_tables_untouched_by_construction(): void
    {
        // Dokumentiert die Spec-Vorgabe: reset() referenziert AUSSCHLIESSLICH
        // RecDispoEvent/RecDispoAssignment (kein Zugriff auf
        // rec_zas_dispo_inbound_files / rec_dispo_filiale_settings / rec_employees).
        $source = file_get_contents(dirname(__DIR__, 2) . '/src/Console/Commands/DispoResetCommand.php');
        $this->assertStringNotContainsString('RecZasDispoInboundFile', (string) $source);
        $this->assertStringNotContainsString('RecDispoFilialeSettings', (string) $source);
        $this->assertStringNotContainsString('RecEmployee', (string) $source);
    }
}

final class DispoResetCommandProbe extends DispoResetCommand
{
    /** @return array{events: int, assignments: int, deleted: bool} */
    public function probeReset(bool $force): array
    {
        return $this->reset($force);
    }
}
