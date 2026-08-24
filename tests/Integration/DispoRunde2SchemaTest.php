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
use Platform\Recruiting\Models\RecDispoFilialeSettings;

/**
 * Verifikation der Runde-2-Schema-Migrationen + Modelle:
 * Neue Tabellen, Spalten und Modell-Attribut-Bindings funktionieren.
 * Nutzt Capsule+SQLite (kein Testbench) analog DispoIndividualNoteTest.
 */
class DispoRunde2SchemaTest extends TestCase
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

        // Alle noetige Basis-Migrationen + die neuen Runde-2-Migrationen.
        $own = dirname(__DIR__, 2);
        foreach ([
            'database/migrations/2026_05_20_000001_create_rec_employees_table.php',
            'database/migrations/2026_08_12_000001_create_rec_dispo_events_table.php',
            'database/migrations/2026_08_12_000002_create_rec_dispo_assignments_table.php',
            'database/migrations/2026_08_14_000001_add_confirmation_fields_to_rec_dispo_assignments.php',
            'database/migrations/2026_08_14_000002_add_vorlauf_minuten_to_rec_dispo_events.php',
            'database/migrations/2026_08_19_000001_add_ansprechpartner_to_rec_dispo_events.php',
            'database/migrations/2026_08_20_000001_add_filiale_to_rec_dispo_events.php',
            'database/migrations/2026_08_20_000002_add_individual_note_to_rec_dispo_assignments.php',
            'database/migrations/2026_08_21_000001_add_filial_nr_to_rec_dispo_events.php',
            // Runde-2-Migrationen
            'database/migrations/2026_08_24_000001_create_rec_dispo_filiale_settings_table.php',
            'database/migrations/2026_08_24_000002_add_escalation_fields_to_rec_dispo_assignments.php',
            'database/migrations/2026_08_24_000003_add_alarm_message_id_to_rec_dispo_events.php',
            'database/migrations/2026_08_24_000004_add_portal_lock_to_rec_employees.php',
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
        Capsule::table('rec_dispo_filiale_settings')->delete();
        Capsule::table('rec_dispo_assignments')->delete();
        Capsule::table('rec_dispo_events')->delete();
    }

    public function test_new_schema_and_models_work(): void
    {
        $event = RecDispoEvent::create(['einsatz_ref' => 'RG-E1', 'filial_nr' => 400, 'alarm_message_id' => null]);
        $a = RecDispoAssignment::create([
            'ds_ref' => 'DS-1', 'rec_dispo_event_id' => $event->id, 'pnr_raw' => 'RG5',
            'rec_employee_id' => 5, 'datum' => '2026-09-01', 'escalation_1_at' => now(),
        ]);
        $this->assertNotNull($a->escalation_1_at);

        $fs = RecDispoFilialeSettings::create(['team_id' => 3, 'filial_nr' => 400, 'comms_channel_id' => 28, 'duty_phone' => '+49170000']);
        $this->assertSame(400, $fs->filial_nr);
    }
}
