<?php

namespace Platform\Recruiting\Tests\Integration;

use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Models\RecEmployee;
use Platform\Recruiting\Observers\RecEmployeeExportObserver;
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
        RecEmployeeExportObserver::register();
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

    public function test_sync_creates_missing_lookup_values_and_stores_the_list(): void
    {
        $employee = $this->employee();

        $r = (new ZasDispoTaetigkeitSync())->syncLabels($employee, ['Teamleitung', 'Supervisor', 'Borussia Thekenleiter']);

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
        $sync->syncLabels($employee, ['Teamleitung', 'Supervisor']);

        $r = $sync->syncLabels($employee, ['Supervisor', 'Kasse']);

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

        (new ZasDispoTaetigkeitSync())->syncLabels($employee, ['Teamleitung']);

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

    public function test_sync_many_writes_only_changed_employees(): void
    {
        $a = $this->employee('RG100');
        $b = $this->employee('RG200');
        $sync = new ZasDispoTaetigkeitSync();
        $sync->syncMany([$a->id => ['Servicekräfte'], $b->id => ['Logistiker']], ['Servicekräfte', 'Logistiker']);

        $r = $sync->syncMany([$a->id => ['Servicekräfte'], $b->id => ['Logistiker', 'Kasse']], ['Servicekräfte', 'Logistiker', 'Kasse']);

        $this->assertSame(1, $r['updated'], 'Nur B hat sich geaendert.');
        $this->assertSame(1, $r['unchanged']);
        $this->assertSame(['Logistiker', 'Kasse'], (array) $b->fresh()->hrData->dispo_taetigkeiten);
    }

    public function test_sync_many_ignores_order_jitter(): void
    {
        $a = $this->employee('RG100');
        $sync = new ZasDispoTaetigkeitSync();
        $sync->syncMany([$a->id => ['Servicekräfte', 'Logistiker']], ['Servicekräfte', 'Logistiker']);

        $r = $sync->syncMany([$a->id => ['Logistiker', 'Servicekräfte']], ['Servicekräfte', 'Logistiker']);

        $this->assertSame(0, $r['updated'], 'Andere Reihenfolge ist keine Aenderung.');
        $this->assertSame(1, $r['unchanged']);
    }

    public function test_sync_many_refreshes_the_timestamp_even_without_a_change(): void
    {
        $a = $this->employee('RG100');
        $sync = new ZasDispoTaetigkeitSync();
        $sync->syncMany([$a->id => ['Servicekräfte']], ['Servicekräfte']);
        Capsule::table('rec_employee_hr_data')
            ->where('rec_employee_id', $a->id)
            ->update(['dispo_taetigkeiten_synced_at' => '2020-01-01 00:00:00']);

        $sync->syncMany([$a->id => ['Servicekräfte']], ['Servicekräfte']);

        $this->assertNotSame(
            '2020-01-01 00:00:00',
            (string) Capsule::table('rec_employee_hr_data')->where('rec_employee_id', $a->id)->value('dispo_taetigkeiten_synced_at'),
            'Sonst zeigt die MA-Akte einen alten Stand, obwohl ZAS den Wert heute bestaetigt hat.'
        );
    }

    public function test_sync_many_puts_the_whole_catalogue_into_the_lookup(): void
    {
        $a = $this->employee('RG100');

        $r = (new ZasDispoTaetigkeitSync())->syncMany(
            [$a->id => ['Servicekräfte']],
            ['Servicekräfte', 'Logistiker', 'Kasse']
        );

        $this->assertSame(3, $r['created_values'],
            'Auch nicht zugewiesene Taetigkeiten gehoeren in die Liste — sonst kann die MA-Akte nicht zeigen, was jemand NICHT kann.');
        $lookupId = Capsule::table('core_lookups')->where('name', 'dispo_taetigkeit')->value('id');
        $this->assertSame(3, Capsule::table('core_lookup_values')->where('lookup_id', $lookupId)->count());
    }

    public function test_sync_many_counts_unknown_employee_ids(): void
    {
        $r = (new ZasDispoTaetigkeitSync())->syncMany([999999 => ['Servicekräfte']], ['Servicekräfte']);

        $this->assertSame(1, $r['missing_employees']);
        $this->assertSame(0, $r['updated']);
    }

    public function test_sync_many_does_not_set_the_zas_export_marker(): void
    {
        // Dieser Test bleibt, ist aber harmlos: dispo_taetigkeiten steht bewusst
        // NICHT in RecEmployeeExportObserver::RELEVANT_HR_FIELDS, daher setzt
        // kein Schreibzugriff (DB::table oder Eloquent) einen Marker. Der Test
        // wird nicht rot, wenn jemand die Zusicherung bricht — daher: siehe
        // test_sync_many_marker_guard_against_observer_violation() fuer die
        // echte Regression-Bewachung.
        $a = $this->employee('RG100');
        Capsule::table('rec_employees')->where('id', $a->id)->update(['zas_changed_at' => null]);

        (new ZasDispoTaetigkeitSync())->syncMany([$a->id => ['Servicekräfte']], ['Servicekräfte']);

        $this->assertNull(
            Capsule::table('rec_employees')->where('id', $a->id)->value('zas_changed_at'),
            'Sonst schickten wir ZAS seine eigenen Daten als Aenderung zurueck.'
        );
    }

    public function test_sync_many_uses_eager_loaded_hrdata_relation(): void
    {
        // Drei Mitarbeiter: zwei mit vorhandener hr-Zeile, einer ohne
        $a = $this->employee('RG100');
        $b = $this->employee('RG200');
        $c = $this->employee('RG300');

        // Laden die hr_data fuer a und b vor (simuliert Eager Loading)
        $a->ensureHrData();
        $b->ensureHrData();
        // c hat keine hr_data

        DB::connection()->enableQueryLog();
        $beforeQueries = count(DB::connection()->getQueryLog());

        $sync = new ZasDispoTaetigkeitSync();
        $sync->syncMany(
            [$a->id => ['Servicekräfte'], $b->id => ['Logistiker'], $c->id => ['Kasse']],
            ['Servicekräfte', 'Logistiker', 'Kasse']
        );

        $allQueries = DB::connection()->getQueryLog();
        $queryCount = count($allQueries) - $beforeQueries;
        DB::connection()->disableQueryLog();

        // Mit Eager Loading (hrData schon geladen): ~11-13 Queries
        // Ohne Eager Loading ($employee->ensureHrData() in der Schleife):
        // + 2 extra firstOrCreate-Queries pro existierendem Mitarbeiter (a+b)
        // = 13-15+ Queries. Grenze bei <= 13 faengt die Regression.
        $this->assertLessThanOrEqual(13, $queryCount,
            "Mit eager-loaded hrData sollten Queries <= 13 sein. " .
            "Ohne Eager Loading waeren es 15+. Gezaehlt: {$queryCount}");
    }

    public function test_sync_many_marker_guard_against_observer_violation(): void
    {
        // Die echte Regression-Bewachung: wenn dispo_taetigkeiten in
        // RecEmployeeExportObserver::RELEVANT_HR_FIELDS eingetragen wird,
        // verursacht syncMany() bei jeder Lieferung einen Export-Marker
        // fuer alle Mitarbeiter (1.442 pro Tag → Massen-Update-Abruf).
        // Am 02.09. schickten wir ZAS auf diese Weise 505 seiner eigenen
        // Datensaetze als Aenderung zurueck (ganz ohne inhaltliche Aenderung).
        //
        // Dieser Test wird ROT, wenn das Feld dort eingetragen wird.
        $this->assertNotContains(
            'dispo_taetigkeiten',
            RecEmployeeExportObserver::RELEVANT_HR_FIELDS,
            'dispo_taetigkeiten muss ausgeschlossen bleiben, sonst schicken wir ' .
            'bei jeder Lieferung (1.442 MA) einen Export-Marker ohne inhaltliche ' .
            'Aenderung wie am 02.09. mit 505 Mitarbeitern passiert.'
        );
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
