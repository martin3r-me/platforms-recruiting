<?php

namespace Platform\Recruiting\Tests\Integration;

use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Models\RecEmployee;
use Platform\Recruiting\Services\Zas\ZasDispoTaetigkeitSync;

/**
 * Dispo-Taetigkeiten aus ZAS (Kunde 15.09.): Komma-Liste wird zerlegt, fehlende
 * Auswahl-Eintraege werden nachgelegt (ZAS erweitert seinen Katalog laufend),
 * der Stand des MA wird ERSETZT — und der ZAS-Export-Marker bleibt unberuehrt
 * (sonst schickten wir ZAS seine eigenen Daten zurueck).
 */
class ZasDispoTaetigkeitSyncTest extends TestCase
{
    private const TEAM = 1101;

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
        foreach (['rec_employees', 'rec_employee_hr_data', 'core_lookups', 'core_lookup_values'] as $t) {
            Capsule::table($t)->delete();
        }
    }

    private function employee(string $pnr = 'RG1'): RecEmployee
    {
        return RecEmployee::create([
            'team_id' => self::TEAM, 'first_name' => 'E', 'last_name' => 'Muster',
            'personnel_number' => $pnr, 'portal_token' => 'tok-' . $pnr, 'is_active' => true,
        ]);
    }

    public function test_parse_trims_deduplicates_and_drops_empty(): void
    {
        $this->assertSame(
            ['Teamleitung', 'Supervisor', 'Barkraft'],
            ZasDispoTaetigkeitSync::parse(' Teamleitung , Supervisor,,Barkraft , teamleitung ')
        );
        $this->assertSame([], ZasDispoTaetigkeitSync::parse(null));
        $this->assertSame([], ZasDispoTaetigkeitSync::parse('   '));
    }

    public function test_sync_creates_missing_lookup_values_and_stores_the_list(): void
    {
        $employee = $this->employee();

        $r = (new ZasDispoTaetigkeitSync())->sync($employee, 'Teamleitung,Supervisor,Borussia Thekenleiter');

        $this->assertSame(3, $r['created_values'], 'Unbekannte Taetigkeiten legen die Auswahlliste an.');
        $this->assertSame(['Teamleitung', 'Supervisor', 'Borussia Thekenleiter'], $r['values']);
        $this->assertSame(
            ['Teamleitung', 'Supervisor', 'Borussia Thekenleiter'],
            (array) $employee->fresh()->hrData->dispo_taetigkeiten
        );
        $this->assertNotNull($employee->fresh()->hrData->dispo_taetigkeiten_synced_at);

        $lookupId = Capsule::table('core_lookups')->where('name', 'dispo_taetigkeit')->value('id');
        $this->assertNotNull($lookupId);
        $this->assertSame(3, Capsule::table('core_lookup_values')->where('lookup_id', $lookupId)->count());
    }

    public function test_second_delivery_replaces_the_list_and_reuses_known_values(): void
    {
        $employee = $this->employee();
        $sync = new ZasDispoTaetigkeitSync();
        $sync->sync($employee, 'Teamleitung,Supervisor');

        $r = $sync->sync($employee, 'Supervisor,Kasse');

        $this->assertSame(1, $r['created_values'], 'Nur "Kasse" ist neu.');
        $this->assertSame(['Supervisor', 'Kasse'], (array) $employee->fresh()->hrData->dispo_taetigkeiten,
            'ZAS ist fuehrend — "Teamleitung" faellt weg.');
        $lookupId = Capsule::table('core_lookups')->where('name', 'dispo_taetigkeit')->value('id');
        $this->assertSame(3, Capsule::table('core_lookup_values')->where('lookup_id', $lookupId)->count(),
            'Die Auswahlliste waechst nur, sie schrumpft nicht.');
    }

    public function test_sync_does_not_set_the_zas_export_marker(): void
    {
        $employee = $this->employee();
        Capsule::table('rec_employees')->where('id', $employee->id)->update(['zas_changed_at' => null]);

        (new ZasDispoTaetigkeitSync())->sync($employee, 'Teamleitung');

        $this->assertNull(
            Capsule::table('rec_employees')->where('id', $employee->id)->value('zas_changed_at'),
            'Sonst schickten wir ZAS seine eigenen Daten als Aenderung zurueck.'
        );
    }

    private static function runMigrations(): void
    {
        $own = dirname(__DIR__, 2);
        $core = self::packageRootOf(\Platform\Core\Models\CoreLookup::class);

        $files = [
            [$own, 'database/migrations/2026_05_20_000001_create_rec_employees_table.php'],
            [$own, 'database/migrations/2026_05_21_000005_add_zas_export_markers_to_rec_employees.php'],
            [$own, 'database/migrations/2026_05_22_000001_add_personnel_number_to_rec_employees.php'],
            [$own, 'database/migrations/2026_05_21_000002_create_rec_employee_hr_data_table.php'],
            [$own, 'database/migrations/2026_05_21_000004_add_linen_package_to_hr_data.php'],
            [$own, 'database/migrations/2026_09_15_000001_add_dispo_taetigkeiten_to_hr_data.php'],
            [$core, 'database/migrations/2026_02_12_000003_create_core_lookups_tables.php'],
        ];

        foreach ($files as [$root, $relative]) {
            $path = $root . '/' . $relative;
            if (!file_exists($path)) {
                throw new \RuntimeException("Migration fehlt: {$path}");
            }
            (require $path)->up();
        }
    }

    private static function packageRootOf(string $class): string
    {
        $file = (new \ReflectionClass($class))->getFileName();
        $dir = dirname((string) $file);
        while ($dir !== '/' && !file_exists($dir . '/composer.json')) {
            $dir = dirname($dir);
        }

        return $dir;
    }
}
