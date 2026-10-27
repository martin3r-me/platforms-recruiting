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

    private function hr(int $id, ?string $status): void
    {
        Capsule::table('rec_employee_hr_data')->insert(['uuid' => (string) \Illuminate\Support\Str::uuid(), 'team_id' => 3, 'rec_employee_id' => $id,
            'export_status' => $status ?? 'GO', 'created_at' => now(), 'updated_at' => now()]);
    }

    private function verknuepfen(int ...$ids): void
    {
        foreach ($ids as $id) {
            Capsule::table('crm_contact_links')->insert([
                'uuid' => (string) \Illuminate\Support\Str::uuid(), 'contact_id' => 88, 'team_id' => 3, 'created_by_user_id' => 1,
                'linkable_id' => $id, 'linkable_type' => (new RecEmployee())->getMorphClass(), 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function test_inaktive_werden_je_block_getrennt_gezaehlt_und_nie_kandidaten(): void
    {
        $this->employee(['cost_center' => '201']);
        $this->employee(['cost_center' => '202', 'is_active' => false]);
        $this->employee(['cost_center' => null]);
        $this->employee(['cost_center' => null, 'is_active' => false]);
        $this->employee(['cost_center' => '401', 'is_active' => false]);
        $this->employee(['cost_center' => '203', 'is_active' => false, 'team_id' => 99]);

        $o = DispoJobKandidaten::blockOptionen(3);
        $byBlock = array_column($o, null, 'block');
        $this->assertSame(1, $byBlock[200]['count']);
        $this->assertSame(1, $byBlock[200]['inaktiv']);
        $this->assertArrayNotHasKey(400, $byBlock, 'Block nur mit Inaktiven wird keine Auswahl');
        $this->assertSame(1, $byBlock[null]['count']);
        $this->assertSame(1, $byBlock[null]['inaktiv']);
        $this->assertCount(2, DispoJobKandidaten::laden(3, ['2026-10-24'], '2026-10')['kandidaten']);
    }

    public function test_ohne_vertrag_nur_wenn_kein_go_und_mindestens_ein_ma(): void
    {
        config(['recruiting.zas.inbound_team_id' => 3]);
        try {
            $rg = $this->employee(['first_name' => 'RG', 'phone' => null]);
            $ma = $this->employee(['first_name' => 'MA']);
            $this->verknuepfen($rg, $ma);
            $this->hr($rg, 'MA');
            $this->hr($ma, 'GO');
            $nurMa = $this->employee(['first_name' => 'NurMA']);
            $this->hr($nurMa, 'MA');
            $keinHr = $this->employee(['first_name' => 'KeinHr']);

            $k = array_column(DispoJobKandidaten::laden(3, ['2026-10-24'], '2026-10')['kandidaten'], null, 'anchor');
            $this->assertFalse($k[$rg]['ohne_vertrag'], 'Gruppe mit GO-Datensatz');
            $this->assertTrue($k[$nurMa]['ohne_vertrag']);
            $this->assertFalse($k[$keinHr]['ohne_vertrag'], 'ohne hrData zaehlt nicht');
            $this->assertSame('GO', $k[$rg]['status'], 'GO in einer Anstellung gewinnt, egal welche Gesellschaft');
            $this->assertSame('MA', $k[$nurMa]['status']);
            $this->assertSame('GO', $k[$keinHr]['status'], 'ohne Status = GO, niemand faellt still raus');
        } finally {
            config(['recruiting.zas.inbound_team_id' => 0]);
        }
    }

    public function test_zahlen_zaehlt_empfaenger_ohne_vertrag(): void
    {
        $k = fn (int $a, bool $ov) => ['anchor' => $a, 'employee_id' => $a, 'phone' => '+4917612345' . $a . '0', 'first_name' => 'P' . $a, 'name' => 'P' . $a,
            'portal_token' => 't', 'block' => 200, 'portal_locked' => false, 'taetigkeiten' => [], 'ohne_vertrag' => $ov];
        $daten = ['kandidaten' => [$k(1, true), $k(2, false), $k(3, true)], 'einsaetze' => [], 'gebucht' => []];
        $schichten = [['id' => 1, 'datum' => '2026-10-24', 'taetigkeit' => 'Zapfer']];
        $z = \Platform\Recruiting\Services\Zas\Dispo\Jobs\DispoJobZahlen::ausDaten($daten, $schichten, [], ['gruppen' => array_keys(\Platform\Recruiting\Services\Zas\Dispo\Jobs\DispoEinsatzZaehler::BAENDER), 'bloecke' => [200], 'nur_passend' => false]);
        $this->assertSame(3, $z['empfaenger']);
        $this->assertSame(2, $z['ohne_vertrag']);
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
            [$own, 'database/migrations/2026_10_21_000001_add_is_test_to_rec_employees.php'],
            [$own, 'database/migrations/2026_05_21_000005_add_zas_export_markers_to_rec_employees.php'],
            [$own, 'database/migrations/2026_05_22_000001_add_personnel_number_to_rec_employees.php'],
            [$own, 'database/migrations/2026_07_07_000001_add_cost_center_to_rec_employees.php'],
            [$own, 'database/migrations/2026_08_24_000004_add_portal_lock_to_rec_employees.php'],
            [$own, 'database/migrations/2026_08_26_000002_add_company_to_rec_employees.php'],
            [$own, 'database/migrations/2026_05_21_000002_create_rec_employee_hr_data_table.php'],
            [$own, 'database/migrations/2026_05_21_000003_add_full_hr_field_set_to_rec_employees_and_hr_data.php'],
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

    public function test_gesellschaft_je_person_und_je_block(): void
    {
        config(['recruiting.zas.inbound_team_id' => 3]);
        try {
            $rg = $this->employee(['first_name' => 'Beide', 'company' => 'RG', 'cost_center' => '201', 'personnel_number' => 'RG1']);
            $ma = $this->employee(['first_name' => 'Beide', 'company' => 'MA', 'cost_center' => '201', 'personnel_number' => 'MA1']);
            $this->verknuepfen($rg, $ma);
            $nurMa = $this->employee(['first_name' => 'NurMA', 'company' => 'MA', 'cost_center' => '201']);
            $ohne = $this->employee(['first_name' => 'Ohne', 'company' => null, 'cost_center' => '201']);
            $this->employee(['first_name' => 'Inaktiv', 'company' => 'MA', 'cost_center' => '201', 'is_active' => false]);

            $k = array_column(DispoJobKandidaten::laden(3, ['2026-10-24'], '2026-10')['kandidaten'], null, 'anchor');
            $this->assertSame(['RG', 'MA'], $k[$rg]['firmen']);
            $this->assertSame(['MA'], $k[$nurMa]['firmen']);
            $this->assertSame(['RG'], $k[$ohne]['firmen'], 'ohne Firma = RG');

            $o = array_column(DispoJobKandidaten::blockOptionen(3), null, 'block');
            $this->assertSame(['aktiv' => 2, 'inaktiv' => 0], $o[200]['firmen']['RG']);
            $this->assertSame(['aktiv' => 2, 'inaktiv' => 1], $o[200]['firmen']['MA'], 'die Person mit beiden zaehlt bei RG und bei MA');
            $this->assertSame(4, $o[200]['count']);
        } finally {
            config(['recruiting.zas.inbound_team_id' => 0]);
        }
    }
}
