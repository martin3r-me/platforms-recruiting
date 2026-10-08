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
use Platform\Recruiting\Services\Zas\Dispo\DispoEmployeeGateway;

/**
 * Crew-Karte der Veranstaltungsseite: zeigt die ZAS-Taetigkeiten ({Dispo5}) statt
 * des handgepflegten Felds. qualifications() (Info-Versand) bleibt auf dem alten Feld.
 */
class DispoCrewKarteQualifikationenTest extends TestCase
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

    private function employee(string $pnr, array $hr = []): RecEmployee
    {
        $e = RecEmployee::create([
            'team_id' => self::TEAM, 'first_name' => 'E', 'last_name' => 'Muster',
            'personnel_number' => $pnr, 'portal_token' => 'tok-' . $pnr, 'is_active' => true,
        ]);
        $row = $e->ensureHrData();
        if ($hr !== []) {
            Capsule::table('rec_employee_hr_data')->where('id', $row->id)->update($hr);
        }

        return $e->fresh();
    }

    public function test_card_shows_the_zas_taetigkeiten_not_the_old_field(): void
    {
        $e = $this->employee('RG1', [
            'dispo_taetigkeiten'   => json_encode(['Servicekräfte', 'Logistiker'], JSON_UNESCAPED_UNICODE),
            'qualifications'       => json_encode(['ALTWERT'], JSON_UNESCAPED_UNICODE),
        ]);

        $card = (new DispoEmployeeGateway())->profileCards([$e->id])[$e->id];

        $this->assertSame(['Servicekräfte', 'Logistiker'], $card['qualifications']);
        $this->assertNotContains('ALTWERT', $card['qualifications'],
            'Das handgepflegte Feld gehoert nicht mehr auf die Karte.');
    }

    public function test_names_pass_through_without_lookup_translation(): void
    {
        // Reale Katalognamen aus Lieferung #258 — Schraegstriche, Punkte,
        // Umlaute. Frueher lief das durch core_lookup_values; jetzt nicht mehr.
        $e = $this->employee('RG1', [
            'dispo_taetigkeiten' => json_encode(['1. FC Köln / Service', 'Barista / Kaffee'], JSON_UNESCAPED_UNICODE),
        ]);

        $card = (new DispoEmployeeGateway())->profileCards([$e->id])[$e->id];

        $this->assertSame(['1. FC Köln / Service', 'Barista / Kaffee'], $card['qualifications']);
    }

    public function test_card_carries_the_sync_timestamp(): void
    {
        $e = $this->employee('RG1', [
            'dispo_taetigkeiten'           => json_encode(['Servicekräfte'], JSON_UNESCAPED_UNICODE),
            'dispo_taetigkeiten_synced_at' => '2026-10-08 14:12:00',
        ]);

        $card = (new DispoEmployeeGateway())->profileCards([$e->id])[$e->id];

        $this->assertSame('2026-10-08 14:12:00', $card['qualifications_synced_at'],
            'Roh und sortierbar — die Anzeigeform macht erst crewCard().');
    }

    public function test_employee_without_assignment_yields_empty_list_and_null_timestamp(): void
    {
        $e = $this->employee('RG1');

        $card = (new DispoEmployeeGateway())->profileCards([$e->id])[$e->id];

        $this->assertSame([], $card['qualifications'],
            '266 aktive Neuzugaenge haben noch nichts — das darf nicht knallen.');
        $this->assertNull($card['qualifications_synced_at']);
    }

    public function test_eightyseven_taetigkeiten_come_through_uncapped_in_the_gateway(): void
    {
        $viele = [];
        for ($i = 1; $i <= 87; $i++) {
            $viele[] = 'Taetigkeit ' . $i;
        }
        $e = $this->employee('RG1', ['dispo_taetigkeiten' => json_encode($viele, JSON_UNESCAPED_UNICODE)]);

        $card = (new DispoEmployeeGateway())->profileCards([$e->id])[$e->id];

        $this->assertCount(87, $card['qualifications'],
            'Die Deckelung gehoert in die Anzeige, nicht in die Datenschicht — der Aufklapper braucht alle.');
    }

    public function test_the_info_versand_source_is_untouched(): void
    {
        $e = $this->employee('RG1', [
            'dispo_taetigkeiten' => json_encode(['Servicekräfte'], JSON_UNESCAPED_UNICODE),
            'qualifications'     => json_encode(['ALTWERT'], JSON_UNESCAPED_UNICODE),
        ]);

        $data = (new DispoEmployeeGateway())->qualifications([$e->id]);

        $this->assertSame(['ALTWERT'], $data['byEmployee'][$e->id],
            'qualifications() speist den Filter im Info-Versand und bleibt auf dem alten Feld.');
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
