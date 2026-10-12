<?php

namespace Platform\Recruiting\Tests\Integration;

use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use Platform\Crm\Models\CrmContactLink;
use Platform\Recruiting\Models\RecDispoAssignment;
use Platform\Recruiting\Models\RecDispoEvent;
use Platform\Recruiting\Models\RecEmployee;
use Platform\Recruiting\Services\Zas\Dispo\Jobs\DispoJobKandidaten;

class DispoJobKandidatenTest extends TestCase
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
        $container->instance('config', new ConfigRepository([
            'recruiting' => ['zas' => ['inbound_team_id' => 0], 'filialen' => [200 => ['code' => 'MGL', 'name' => 'Mönchengladbach']]],
        ]));

        self::runMigrations();
    }

    public static function tearDownAfterClass(): void
    {
        Container::getInstance()->forgetInstance('config');
        Facade::clearResolvedInstances();
    }

    protected function setUp(): void
    {
        foreach (['crm_contact_links', 'rec_employees', 'rec_employee_hr_data', 'rec_positions', 'rec_dispo_events', 'rec_dispo_assignments'] as $t) {
            Capsule::table($t)->delete();
        }
    }

    private function employee(array $o = []): int
    {
        return (int) Capsule::table('rec_employees')->insertGetId($o + [
            'uuid' => (string) \Illuminate\Support\Str::uuid(), 'team_id' => 3, 'first_name' => 'E', 'last_name' => 'Muster', 'is_active' => true,
            'phone' => '0176 9', 'portal_token' => 't' . uniqid(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_eine_zeile_je_person_mit_nummer_block_taetigkeiten_und_zaehlern(): void
    {
        $a = $this->employee(['first_name' => 'Anna', 'phone' => '0176 12345678', 'cost_center' => '401', 'portal_token' => 'ta']);
        $b = $this->employee(['first_name' => 'Ben', 'phone' => null, 'cost_center' => '', 'portal_token' => 'tb']);
        $c = $this->employee(['first_name' => 'Cem', 'phone' => '0176 3', 'cost_center' => null, 'portal_token' => 'tc', 'portal_locked_at' => '2026-10-01 00:00:00', 'is_active' => false]);
        Capsule::table('rec_employee_hr_data')->insert(['uuid' => (string) \Illuminate\Support\Str::uuid(), 'team_id' => 3, 'rec_employee_id' => $a, 'dispo_taetigkeiten' => json_encode(['Zapfer', 'Logistiker']), 'created_at' => now(), 'updated_at' => now()]);
        $event = RecDispoEvent::create(['einsatz_ref' => 'E1']);
        foreach (['2026-10-24', '2026-10-02', '2026-09-30'] as $d) {
            RecDispoAssignment::create(['ds_ref' => 'd' . $d, 'rec_dispo_event_id' => $event->id, 'pnr_raw' => 'x', 'rec_employee_id' => $a, 'datum' => $d, 'status_id' => 1, 'taetigkeit' => 'Zapfer']);
        }

        $r = DispoJobKandidaten::laden(3, ['2026-10-24', '2026-10-25'], '2026-10');

        $this->assertCount(2, $r['kandidaten'], 'inaktive fallen raus');
        $anna = $r['kandidaten'][0];
        $this->assertSame(['anchor' => $a, 'block' => 400, 'portal_locked' => false, 'taetigkeiten' => ['Zapfer', 'Logistiker']], array_intersect_key($anna, array_flip(['anchor', 'block', 'taetigkeiten', 'portal_locked'])));
        $this->assertStringStartsWith('+49', $anna['phone']);
        $this->assertNull($r['kandidaten'][1]['block']);
        $this->assertSame([$a => 2], $r['einsaetze']);
        $this->assertSame([$a => ['2026-10-24' => true]], $r['gebucht'], 'nur die gefragten Tage');
    }

    public function test_block_optionen_mit_labels(): void
    {
        $this->employee(['cost_center' => '201']);
        $this->employee(['cost_center' => null]);
        $o = DispoJobKandidaten::blockOptionen(3);
        $this->assertSame([200, null], array_column($o, 'block'));
        $this->assertSame('ohne Kostenstelle', $o[1]['label']);
    }

    public function test_gruppe_wird_zu_einer_zeile_mit_zusammengefuehrten_feldern(): void
    {
        config(['recruiting.zas.inbound_team_id' => 3]);
        try {
            $a = $this->employee(['first_name' => 'Alt', 'phone' => null, 'cost_center' => null, 'portal_token' => 'tok-a']);
            $b = $this->employee(['first_name' => 'Neu', 'phone' => '0176 12345678', 'cost_center' => '201', 'portal_token' => 'tok-b', 'portal_locked_at' => '2026-10-01 00:00:00']);
            foreach ([$a, $b] as $id) {
                Capsule::table('crm_contact_links')->insert([
                    'uuid' => (string) \Illuminate\Support\Str::uuid(), 'contact_id' => 77, 'team_id' => 3, 'created_by_user_id' => 1,
                    'linkable_id' => $id, 'linkable_type' => (new RecEmployee())->getMorphClass(), 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            foreach ([[$a, ['Zapfer']], [$b, ['Koch']]] as [$id, $t]) {
                Capsule::table('rec_employee_hr_data')->insert(['uuid' => (string) \Illuminate\Support\Str::uuid(), 'team_id' => 3, 'rec_employee_id' => $id, 'dispo_taetigkeiten' => json_encode($t), 'created_at' => now(), 'updated_at' => now()]);
            }
            $event = RecDispoEvent::create(['einsatz_ref' => 'E1']);
            RecDispoAssignment::create(['ds_ref' => 'dg', 'rec_dispo_event_id' => $event->id, 'pnr_raw' => 'x', 'rec_employee_id' => $a, 'datum' => '2026-10-24', 'status_id' => 1, 'taetigkeit' => 'Zapfer']);

            $r = DispoJobKandidaten::laden(3, ['2026-10-24'], '2026-10');

            $this->assertCount(1, $r['kandidaten']);
            $k = $r['kandidaten'][0];
            $this->assertSame($a, $k['anchor']);
            $this->assertSame($b, $k['employee_id']);
            $this->assertSame('tok-b', $k['portal_token']);
            $this->assertSame('Neu', $k['first_name']);
            $this->assertStringStartsWith('+49', $k['phone']);
            $this->assertSame(200, $k['block']);
            $this->assertTrue($k['portal_locked']);
            $this->assertSame(['Zapfer', 'Koch'], $k['taetigkeiten']);
            $this->assertSame([$a => ['2026-10-24' => true]], $r['gebucht']);
        } finally {
            config(['recruiting.zas.inbound_team_id' => 0]);
        }
    }

    private static function runMigrations(): void
    {
        $own = dirname(__DIR__, 2);
        $core = self::packageRootOf(\Platform\Core\Models\CoreLookup::class);
        $crm = self::packageRootOf(CrmContactLink::class);

        $files = [
            [$own, 'database/migrations/2026_02_09_000001_create_rec_positions_table.php'],
            [$own, 'database/migrations/2026_05_21_000006_add_cost_center_to_rec_positions.php'],
            [$own, 'database/migrations/2026_05_20_000001_create_rec_employees_table.php'],
            [$own, 'database/migrations/2026_05_21_000005_add_zas_export_markers_to_rec_employees.php'],
            [$own, 'database/migrations/2026_05_22_000001_add_personnel_number_to_rec_employees.php'],
            [$own, 'database/migrations/2026_07_07_000001_add_cost_center_to_rec_employees.php'],
            [$own, 'database/migrations/2026_08_24_000004_add_portal_lock_to_rec_employees.php'],
            [$own, 'database/migrations/2026_08_26_000002_add_company_to_rec_employees.php'],
            [$own, 'database/migrations/2026_05_21_000002_create_rec_employee_hr_data_table.php'],
            [$own, 'database/migrations/2026_05_21_000004_add_linen_package_to_hr_data.php'],
            [$own, 'database/migrations/2026_09_15_000001_add_dispo_taetigkeiten_to_hr_data.php'],
            [$own, 'database/migrations/2026_08_12_000001_create_rec_dispo_events_table.php'],
            [$own, 'database/migrations/2026_08_12_000002_create_rec_dispo_assignments_table.php'],
            [$own, 'database/migrations/2026_08_14_000001_add_confirmation_fields_to_rec_dispo_assignments.php'],
            [$own, 'database/migrations/2026_08_20_000001_add_filiale_to_rec_dispo_events.php'],
            [$own, 'database/migrations/2026_09_04_000001_add_decline_fields_to_rec_dispo_assignments.php'],
            [$core, 'database/migrations/2026_02_12_000003_create_core_lookups_tables.php'],
            [$crm, 'database/migrations/2024_01_01_000020_create_crm_contact_links_table.php'],
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
