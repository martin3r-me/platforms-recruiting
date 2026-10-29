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
use Platform\Recruiting\Services\Zas\Dispo\Jobs\DispoJobZusagenInfo;

class DispoJobZusagenInfoTest extends TestCase
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

    public function test_eine_zeile_je_mitarbeiter_mit_einsaetzen_taetigkeiten_und_fuenf_bewertungen(): void
    {
        $a = $this->employee(['first_name' => 'Anna', 'last_name' => 'Ast', 'phone' => '0176 12345678', 'personnel_number' => 'RG1']);
        Capsule::table('rec_employee_hr_data')->insert(['uuid' => (string) \Illuminate\Support\Str::uuid(), 'team_id' => 3, 'rec_employee_id' => $a,
            'dispo_taetigkeiten' => json_encode(['Zapfer', 'Logistiker']),
            'rating_erscheinungsbild' => 4, 'rating_fachkompetenz' => 5, 'rating_auffassungsgabe' => 3, 'rating_auftreten' => 4, 'rating_teamintegration' => 4,
            'created_at' => now(), 'updated_at' => now()]);
        $event = RecDispoEvent::create(['einsatz_ref' => 'E1']);
        foreach (['2026-10-02', '2026-10-20', '2026-09-30'] as $d) {
            RecDispoAssignment::create(['ds_ref' => 'd' . $d, 'rec_dispo_event_id' => $event->id, 'pnr_raw' => 'x', 'rec_employee_id' => $a, 'datum' => $d, 'status_id' => 1]);
        }
        RecDispoAssignment::create(['ds_ref' => 'abgesagt', 'rec_dispo_event_id' => $event->id, 'pnr_raw' => 'x', 'rec_employee_id' => $a, 'datum' => '2026-10-21', 'status_id' => 1, 'declined_at' => now()]);

        $info = DispoJobZusagenInfo::laden([$a, 999999], '2026-10');

        $this->assertSame([$a], array_keys($info), 'unbekannte IDs fallen raus');
        $this->assertSame('Anna Ast', $info[$a]['name']);
        $this->assertSame('RG1', $info[$a]['personnel_number']);
        $this->assertStringStartsWith('+49', $info[$a]['phone']);
        $this->assertSame(2, $info[$a]['einsaetze'], 'nur Oktober, abgesagte zaehlen nicht');
        $this->assertSame(['Logistiker', 'Zapfer'], $info[$a]['taetigkeiten'], 'sortiert');
        $this->assertSame([4, 5, 3, 4, 4], array_values($info[$a]['ratings']));
    }

    public function test_gruppe_rg_und_ma_zaehlt_einsaetze_zusammen_und_fuellt_luecken(): void
    {
        // Gruppierung braucht den Team-Anker (Muster DispoJobKandidatenTest) — am Ende zuruecksetzen.
        config(['recruiting.zas.inbound_team_id' => 3]);
        try {
            $this->gruppenFall();
        } finally {
            config(['recruiting.zas.inbound_team_id' => 0]);
        }
    }

    private function gruppenFall(): void
    {
        $rg = $this->employee(['first_name' => 'Alt', 'personnel_number' => 'RG7', 'phone' => null]);
        $ma = $this->employee(['first_name' => 'Neu', 'personnel_number' => 'MA7', 'phone' => '0176 12345678']);
        foreach ([$rg, $ma] as $id) {
            Capsule::table('crm_contact_links')->insert([
                'uuid' => (string) \Illuminate\Support\Str::uuid(), 'contact_id' => 77, 'team_id' => 3, 'created_by_user_id' => 1,
                'linkable_id' => $id, 'linkable_type' => (new RecEmployee())->getMorphClass(), 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        Capsule::table('rec_employee_hr_data')->insert(['uuid' => (string) \Illuminate\Support\Str::uuid(), 'team_id' => 3, 'rec_employee_id' => $rg,
            'dispo_taetigkeiten' => json_encode(['Zapfer']), 'star_rating' => 4, 'created_at' => now(), 'updated_at' => now()]);
        Capsule::table('rec_employee_hr_data')->insert(['uuid' => (string) \Illuminate\Support\Str::uuid(), 'team_id' => 3, 'rec_employee_id' => $ma,
            'dispo_taetigkeiten' => json_encode(['Koch']), 'created_at' => now(), 'updated_at' => now()]);
        $event = RecDispoEvent::create(['einsatz_ref' => 'E2']);
        RecDispoAssignment::create(['ds_ref' => 'g1', 'rec_dispo_event_id' => $event->id, 'pnr_raw' => 'x', 'rec_employee_id' => $rg, 'datum' => '2026-10-03', 'status_id' => 1]);
        RecDispoAssignment::create(['ds_ref' => 'g2', 'rec_dispo_event_id' => $event->id, 'pnr_raw' => 'x', 'rec_employee_id' => $ma, 'datum' => '2026-10-04', 'status_id' => 1]);

        $info = DispoJobZusagenInfo::laden([$ma], '2026-10');

        $this->assertSame(2, $info[$ma]['einsaetze'], 'beide Datensaetze derselben Person');
        $this->assertSame(['Koch', 'Zapfer'], $info[$ma]['taetigkeiten'], 'Vereinigung, sortiert');
        $this->assertSame(['Sternebewertung (Altbestand)' => 4], $info[$ma]['ratings'], 'Luecke aus dem RG-Datensatz gefuellt');
    }

    public function test_ohne_vertrag_je_person_ueber_die_gruppe(): void
    {
        config(['recruiting.zas.inbound_team_id' => 3]);
        try {
            $mk = function (string $name, array $stati, bool $link = true) {
                $ids = [];
                foreach ($stati as $st) {
                    $id = $this->employee(['first_name' => $name]);
                    if ($st !== null) {
                        Capsule::table('rec_employee_hr_data')->insert(['uuid' => (string) \Illuminate\Support\Str::uuid(), 'team_id' => 3, 'rec_employee_id' => $id,
                            'export_status' => $st, 'created_at' => now(), 'updated_at' => now()]);
                    }
                    $ids[] = $id;
                }
                static $contact = 500;
                $contact++;
                if ($link) {
                    foreach ($ids as $id) {
                        Capsule::table('crm_contact_links')->insert([
                            'uuid' => (string) \Illuminate\Support\Str::uuid(), 'contact_id' => $contact, 'team_id' => 3, 'created_by_user_id' => 1,
                            'linkable_id' => $id, 'linkable_type' => (new RecEmployee())->getMorphClass(), 'created_at' => now(), 'updated_at' => now(),
                        ]);
                    }
                }

                return $ids;
            };
            [$a1, $a2] = $mk('Beide', ['MA', 'GO']);
            [$b] = $mk('NurMa', ['MA']);
            [$c] = $mk('OhneHr', [null]);

            $info = DispoJobZusagenInfo::laden([$a2, $b, $c], '2026-10');

            $this->assertFalse($info[$a2]['ohne_vertrag']);
            $this->assertTrue($info[$b]['ohne_vertrag']);
            $this->assertFalse($info[$c]['ohne_vertrag']);
        } finally {
            config(['recruiting.zas.inbound_team_id' => 0]);
        }
    }

    public function test_bewertungen_werden_je_kriterium_aus_der_gruppe_gefuellt(): void
    {
        config(['recruiting.zas.inbound_team_id' => 3]);
        try {
            $rg = $this->employee(['first_name' => 'Alt', 'personnel_number' => 'RG8', 'phone' => null]);
            $ma = $this->employee(['first_name' => 'Neu', 'personnel_number' => 'MA8', 'phone' => '0176 12345678']);
            foreach ([$rg, $ma] as $id) {
                Capsule::table('crm_contact_links')->insert([
                    'uuid' => (string) \Illuminate\Support\Str::uuid(), 'contact_id' => 78, 'team_id' => 3, 'created_by_user_id' => 1,
                    'linkable_id' => $id, 'linkable_type' => (new RecEmployee())->getMorphClass(), 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            // MA hat nur Auffassungsgabe; RG liefert Erscheinungsbild und (nachrangig) eine abweichende Auffassungsgabe.
            Capsule::table('rec_employee_hr_data')->insert(['uuid' => (string) \Illuminate\Support\Str::uuid(), 'team_id' => 3, 'rec_employee_id' => $ma,
                'rating_auffassungsgabe' => 3, 'created_at' => now(), 'updated_at' => now()]);
            Capsule::table('rec_employee_hr_data')->insert(['uuid' => (string) \Illuminate\Support\Str::uuid(), 'team_id' => 3, 'rec_employee_id' => $rg,
                'rating_erscheinungsbild' => 4, 'rating_auffassungsgabe' => 1, 'created_at' => now(), 'updated_at' => now()]);

            $info = DispoJobZusagenInfo::laden([$ma], '2026-10');
        } finally {
            config(['recruiting.zas.inbound_team_id' => 0]);
        }

        $this->assertSame(['Erscheinungsbild & Hygiene' => 4, 'Auffassungsgabe & Lernbereitschaft' => 3], $info[$ma]['ratings'], 'je Kriterium das erste Gruppenmitglied, feste Reihenfolge');
        $this->assertSame('4 · – · 3 · – · –', DispoJobZusagenInfo::bewertung($info[$ma]['ratings'])['text']);
    }

    public function test_passt_und_bewertungstext(): void
    {
        $this->assertTrue(DispoJobZusagenInfo::passt(' zapfer ', ['Zapfer']));
        $this->assertFalse(DispoJobZusagenInfo::passt('Koch', ['Zapfer']));
        $this->assertFalse(DispoJobZusagenInfo::passt('Koch', []));

        $this->assertSame(['text' => '4 · 5 · 3 · 4 · 4', 'title' => 'Erscheinungsbild & Hygiene 4, Fachliche Grundkompetenz 5, Auffassungsgabe & Lernbereitschaft 3, Auftreten & Kommunikation 4, Teamintegration & Verhalten 4'],
            DispoJobZusagenInfo::bewertung(['Erscheinungsbild & Hygiene' => 4, 'Fachliche Grundkompetenz' => 5, 'Auffassungsgabe & Lernbereitschaft' => 3, 'Auftreten & Kommunikation' => 4, 'Teamintegration & Verhalten' => 4]));
        $this->assertSame(['text' => '4 · – · 3 · – · –', 'title' => 'Erscheinungsbild & Hygiene 4, Auffassungsgabe & Lernbereitschaft 3'],
            DispoJobZusagenInfo::bewertung(['Auffassungsgabe & Lernbereitschaft' => 3, 'Erscheinungsbild & Hygiene' => 4]), 'Luecken behalten ihre Position, feste Reihenfolge');
        $this->assertSame(['text' => '★ 4', 'title' => 'Sternebewertung (Altbestand) 4'], DispoJobZusagenInfo::bewertung(['Sternebewertung (Altbestand)' => 4]));
        $this->assertSame(['text' => '–', 'title' => 'keine Bewertung'], DispoJobZusagenInfo::bewertung([]));
    }

    public function test_leere_liste_fragt_nichts(): void
    {
        $this->assertSame([], DispoJobZusagenInfo::laden([], '2026-10'));
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
            [$own, 'database/migrations/2026_10_13_000001_create_rec_dispo_job_tables.php'],
            [$own, 'database/migrations/2026_10_20_000001_add_stati_to_rec_dispo_job_waves.php'],
            [$own, 'database/migrations/2026_10_27_000001_add_wellen_to_rec_dispo_job_tables.php'],
            [$own, 'database/migrations/2026_10_13_000002_add_anlass_to_rec_dispo_job_requests.php'],
            [$own, 'database/migrations/2026_10_14_000001_add_bis_to_rec_dispo_job_shifts.php'],
            [$own, 'database/migrations/2026_10_29_000001_create_rec_dispo_bedarfe_table.php'],
            [$own, 'database/migrations/2026_10_29_000002_add_dispoposten_id_to_rec_dispo_job_shifts.php'],
            [$own, 'database/migrations/2026_08_05_000002_add_ratings_to_rec_employee_hr_data.php'],
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

    public function test_absagen_der_letzten_60_tage_je_person_ueber_alle_anstellungen(): void
    {
        $a = $this->employee(['first_name' => 'Absager']);
        $request = \Platform\Recruiting\Models\RecDispoJobRequest::create(['team_id' => 3, 'filial_nr' => 200, 'zeitraum_von' => '2026-10-20', 'zeitraum_bis' => '2099-11-17',
            'modus' => 'sammeln', 'anlass' => 'dringend', 'status' => 'laeuft', 'created_by_user_id' => 1]);
        $wave = $request->waves()->create(['nr' => 1, 'ma_bloecke' => [200], 'gruppen' => ['0'], 'nur_passende_taetigkeit' => true]);
        $offer = $wave->offers()->create(['request_id' => $request->id, 'rec_employee_id' => $a, 'anchor_employee_id' => $a]);
        foreach ([['2026-11-01', now()], ['2026-11-02', now()->subDays(3)], ['2026-11-03', now()->subDays(90)]] as [$tag, $wann]) {
            $shift = $request->shifts()->create(['rec_dispo_event_id' => 1, 'datum' => $tag, 'von' => '17:00', 'taetigkeit' => 'Zapfer', 'gesucht' => 3]);
            $offer->shifts()->create(['shift_id' => $shift->id, 'declined_at' => $wann, 'declined_reason' => 'krank']);
        }

        $info = DispoJobZusagenInfo::laden([$a], '2026-10');
        $this->assertSame(2, $info[$a]['absagen_60'], 'die 90 Tage alte zaehlt nicht');
    }
}
