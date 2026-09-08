<?php

namespace Platform\Recruiting\Tests\Integration;

use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Models\RecDispoAssignment;
use Platform\Recruiting\Models\RecDispoEvent;
use Platform\Recruiting\Services\Zas\Dispo\ZasDispoConfirmationExport;

/**
 * Rueckkanal Bestaetigungen -> ZAS: Snapshot je Einbuchung mit DS-ID als
 * Schluessel; Status-Klartext fuer die Gegenkontrolle; Zeitfenster + optionaler
 * Einsatz-Filter.
 */
class ZasDispoConfirmationExportTest extends TestCase
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
        $container->instance('config', new ConfigRepository([]));

        self::runMigrations();
    }

    public static function tearDownAfterClass(): void
    {
        Container::getInstance()->forgetInstance('config');
        Facade::clearResolvedInstances();
    }

    protected function setUp(): void
    {
        Capsule::table('rec_dispo_assignments')->delete();
        Capsule::table('rec_dispo_events')->delete();
    }

    private function row(int $eventId, string $dsRef, string $datum, array $extra = []): RecDispoAssignment
    {
        return RecDispoAssignment::create(array_merge([
            'ds_ref' => $dsRef, 'rec_dispo_event_id' => $eventId, 'pnr_raw' => 'RG1',
            'datum' => $datum, 'von' => '09:00', 'bis' => '17:00',
            'status_id' => RecDispoAssignment::STATUS_AUFTRAG,
        ], $extra));
    }

    public function test_snapshot_contains_only_confirmed_rows_with_ds_id_and_timestamp(): void
    {
        $event = RecDispoEvent::create(['einsatz_ref' => 'RG19734', 'name' => 'Borussia']);
        $this->row($event->id, 'DS-OFFEN', now()->addDay()->toDateString());
        $this->row($event->id, 'DS-BEST', now()->addDay()->toDateString(), [
            'reminder_sent_at' => '2026-09-03 16:20:00', 'confirmed_at' => '2026-09-04 09:15:00',
        ]);
        $this->row($event->id, 'DS-RAUS', now()->addDay()->toDateString(), [
            'reminder_sent_at' => '2026-09-03 16:20:00', 'deletion_marked_at' => now(),
        ]);
        // Bestaetigt, aber Einsatz vor dem Cutoff -> raus.
        $this->row($event->id, 'DS-ALT', now()->subDays(30)->toDateString(), ['confirmed_at' => now()->subDays(30)]);

        $rows = (new ZasDispoConfirmationExport())->rows(7);

        $this->assertCount(1, $rows, 'NUR Bestaetigte im Fenster (User-Entscheid 08.09.).');
        $this->assertSame('DS-BEST', $rows[0]['DSID']);
        $this->assertSame('RG19734', $rows[0]['EinsatzRef']);
        $this->assertSame('04.09.2026 09:15', $rows[0]['BestaetigtAm']);
    }

    public function test_einsatz_filter_limits_to_one_event(): void
    {
        $a = RecDispoEvent::create(['einsatz_ref' => 'RG-A']);
        $b = RecDispoEvent::create(['einsatz_ref' => 'RG-B']);
        $this->row($a->id, 'DS-A', now()->addDay()->toDateString(), ['confirmed_at' => now()]);
        $this->row($b->id, 'DS-B', now()->addDay()->toDateString(), ['confirmed_at' => now()]);

        $rows = (new ZasDispoConfirmationExport())->rows(7, 'RG-A');

        $this->assertCount(1, $rows);
        $this->assertSame('DS-A', $rows[0]['DSID']);
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
            'database/migrations/2026_09_03_000002_add_note_timestamp_to_rec_dispo_assignments.php',
            'database/migrations/2026_09_04_000001_add_decline_fields_to_rec_dispo_assignments.php',
            'database/migrations/2026_09_04_000002_add_escalation_plan_to_rec_dispo_assignments.php',
            'database/migrations/2026_09_04_000003_add_reminder_sent_to_to_rec_dispo_assignments.php',
            'database/migrations/2026_09_04_000004_add_manual_confirm_to_rec_dispo_assignments.php',
            'database/migrations/2026_09_08_000001_add_late_marker_to_rec_dispo_assignments.php',
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
