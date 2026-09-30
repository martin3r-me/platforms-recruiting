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
use Platform\Recruiting\Models\RecDispoDressPackage;
use Platform\Recruiting\Models\RecDispoEvent;

/**
 * Gemeinsame Basis der Waeschepaket-Tests: Container + Capsule von Hand,
 * SQLite im Speicher, Migrationen einmal je Klasse.
 *
 * Der Event-Dispatcher wird gesetzt, BEVOR Modelle booten — sonst fallen die
 * creating-Hooks (UUID) fuer alle spaeteren Testklassen im geteilten Prozess
 * still aus (siehe Kommentar in phpunit.xml).
 */
abstract class DressTestCase extends TestCase
{
    protected const TEAM = 1101;

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

        $own = dirname(__DIR__, 2);
        foreach ([
            'database/migrations/2026_08_12_000001_create_rec_dispo_events_table.php',
            'database/migrations/2026_08_12_000002_create_rec_dispo_assignments_table.php',
            'database/migrations/2026_08_14_000001_add_confirmation_fields_to_rec_dispo_assignments.php',
            'database/migrations/2026_08_20_000001_add_filiale_to_rec_dispo_events.php',
            'database/migrations/2026_09_04_000001_add_decline_fields_to_rec_dispo_assignments.php',
            'database/migrations/2026_09_30_000001_create_rec_dispo_dress_packages_table.php',
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
        Container::getInstance()->forgetInstance('config');
        Facade::clearResolvedInstances();
    }

    protected function setUp(): void
    {
        foreach (['rec_dispo_events', 'rec_dispo_assignments', 'rec_dispo_dress_packages'] as $t) {
            Capsule::table($t)->delete();
        }
    }

    protected function event(array $attrs = []): RecDispoEvent
    {
        static $n = 0;
        $n++;

        return RecDispoEvent::create(array_merge([
            'einsatz_ref' => 'VA-' . $n,
            'name'        => 'Testveranstaltung ' . $n,
            'dresscode'   => null,
        ], $attrs));
    }

    protected function assignment(RecDispoEvent $event, array $attrs = []): RecDispoAssignment
    {
        static $n = 0;
        $n++;

        return RecDispoAssignment::create(array_merge([
            'ds_ref'             => 'DS-' . $n,
            'rec_dispo_event_id' => $event->id,
            'pnr_raw'            => 'RG' . $n,
            'rec_employee_id'    => 900 + $n,
            'datum'              => '2026-10-01',
            'von'                => '08:00',
            'bis'                => '16:00',
            'status_id'          => RecDispoAssignment::STATUS_AUFTRAG,
            'taetigkeit'         => 'Service',
        ], $attrs));
    }

    protected function package(string $name, string $text): RecDispoDressPackage
    {
        return RecDispoDressPackage::create([
            'team_id'    => self::TEAM,
            'name'       => $name,
            'items_text' => $text,
        ]);
    }
}
