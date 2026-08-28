<?php

namespace Platform\Recruiting\Tests\Integration;

use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Models\RecDispoAssignment;
use Platform\Recruiting\Models\RecDispoEvent;
use Platform\Recruiting\Services\Zas\Dispo\DispoReconfirmMarker;

/**
 * DispoReconfirmMarker (Runde 4, #2): duenner DB-Rand um DispoReconfirmPolicy,
 * liest bestaetigte Einbuchungen gegen eine Lieferung und liefert je ds_ref
 * die Reset-Attribute fuer den Importer. Ende zu Ende gegen ECHTE Migrationen
 * (rec_dispo_events/rec_dispo_assignments + alle Bestaetigungs-/Eskalations-
 * /Reconfirm-Spalten), kein Testbench — Muster DispoIdentityResolverTest.
 */
class DispoReconfirmMarkerTest extends TestCase
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

        self::runMigrations();
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

    public function test_changed_time_on_confirmed_assignment_yields_reset_overrides(): void
    {
        $event = RecDispoEvent::create(['einsatz_ref' => 'RG-RC1']);
        RecDispoAssignment::create([
            'ds_ref' => 'DS1', 'rec_dispo_event_id' => $event->id, 'pnr_raw' => 'RG1',
            'datum' => '2026-09-02', 'von' => '10:00', 'bis' => '18:00', 'status_id' => 1,
            'confirmed_at' => now(), 'confirmed_datum' => '2026-09-02', 'confirmed_von' => '10:00', 'confirmed_bis' => '18:00',
            'reminder_sent_at' => now(),
        ]);

        $r = (new DispoReconfirmMarker())->plan(['DS1' => ['datum' => '2026-09-02', 'von' => '11:00', 'bis' => '18:00']], '2026-08-28');

        $this->assertSame(1, $r['count']);
        $o = $r['overrides']['DS1'];
        $this->assertNull($o['confirmed_at']);
        $this->assertNull($o['reminder_sent_at']);
        $this->assertNull($o['escalation_1_at']);
        $this->assertNotNull($o['reconfirm_required_at']);
        $this->assertSame(['datum' => '2026-09-02', 'von' => '10:00', 'bis' => '18:00'], $o['reconfirm_previous']);
        $this->assertArrayNotHasKey('confirmed_datum', $o, 'Snapshot bleibt bis zur naechsten Bestaetigung');
    }

    public function test_unchanged_unconfirmed_or_deletion_marked_are_ignored(): void
    {
        $event = RecDispoEvent::create(['einsatz_ref' => 'RG-RC2']);

        // DS2: bestaetigt, aber Lieferung liefert dieselben Zeiten -> kein Override.
        RecDispoAssignment::create([
            'ds_ref' => 'DS2', 'rec_dispo_event_id' => $event->id, 'pnr_raw' => 'RG2',
            'datum' => '2026-09-02', 'von' => '10:00', 'bis' => '18:00', 'status_id' => 1,
            'confirmed_at' => now(), 'confirmed_datum' => '2026-09-02', 'confirmed_von' => '10:00', 'confirmed_bis' => '18:00',
        ]);

        // DS3: unbestaetigt (confirmed_at = null) -> kein Override, egal was die Lieferung sagt.
        RecDispoAssignment::create([
            'ds_ref' => 'DS3', 'rec_dispo_event_id' => $event->id, 'pnr_raw' => 'RG3',
            'datum' => '2026-09-02', 'von' => '10:00', 'bis' => '18:00', 'status_id' => 1,
        ]);

        // DS4: bestaetigt UND zur Loeschung markiert -> kein Override (deletion_marked_at gewinnt).
        RecDispoAssignment::create([
            'ds_ref' => 'DS4', 'rec_dispo_event_id' => $event->id, 'pnr_raw' => 'RG4',
            'datum' => '2026-09-02', 'von' => '10:00', 'bis' => '18:00', 'status_id' => 1,
            'confirmed_at' => now(), 'confirmed_datum' => '2026-09-02', 'confirmed_von' => '10:00', 'confirmed_bis' => '18:00',
            'deletion_marked_at' => now(),
        ]);

        $r = (new DispoReconfirmMarker())->plan([
            'DS2' => ['datum' => '2026-09-02', 'von' => '10:00', 'bis' => '18:00'],
            'DS3' => ['datum' => '2026-09-03', 'von' => '10:00', 'bis' => '18:00'],
            'DS4' => ['datum' => '2026-09-03', 'von' => '10:00', 'bis' => '18:00'],
            'DS-UNBEKANNT' => ['datum' => '2026-09-03', 'von' => '10:00', 'bis' => '18:00'],
        ], '2026-08-28');

        $this->assertSame(0, $r['count']);
        $this->assertSame([], $r['overrides']);
    }

    public function test_plan_does_not_write(): void
    {
        $event = RecDispoEvent::create(['einsatz_ref' => 'RG-RC5']);
        RecDispoAssignment::create([
            'ds_ref' => 'DS5', 'rec_dispo_event_id' => $event->id, 'pnr_raw' => 'RG5',
            'datum' => '2026-09-02', 'von' => '10:00', 'bis' => '18:00', 'status_id' => 1,
            'confirmed_at' => now(), 'confirmed_datum' => '2026-09-02', 'confirmed_von' => '10:00', 'confirmed_bis' => '18:00',
        ]);

        $r = (new DispoReconfirmMarker())->plan(['DS5' => ['datum' => '2026-09-02', 'von' => '12:00', 'bis' => '18:00']], '2026-08-28');

        $this->assertSame(1, $r['count'], 'Vorbedingung: Policy schlaegt hier tatsaechlich an.');
        $this->assertNotNull(RecDispoAssignment::where('ds_ref', 'DS5')->value('confirmed_at'), 'plan() liest nur, schreibt nie.');
    }

    private static function runMigrations(): void
    {
        $own = dirname(__DIR__, 2);

        $files = [
            'database/migrations/2026_08_12_000001_create_rec_dispo_events_table.php',
            'database/migrations/2026_08_12_000002_create_rec_dispo_assignments_table.php',
            'database/migrations/2026_08_14_000001_add_confirmation_fields_to_rec_dispo_assignments.php',
            'database/migrations/2026_08_20_000002_add_individual_note_to_rec_dispo_assignments.php',
            'database/migrations/2026_08_24_000002_add_escalation_fields_to_rec_dispo_assignments.php',
            'database/migrations/2026_08_28_000002_add_reconfirm_fields_to_rec_dispo_assignments.php',
        ];

        foreach ($files as $relative) {
            $path = $own . '/' . $relative;
            if (!file_exists($path)) {
                throw new \RuntimeException("Migration fehlt: {$path}");
            }
            (require $path)->up();
        }
    }
}
