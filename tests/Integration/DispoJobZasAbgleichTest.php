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
use Platform\Recruiting\Models\RecDispoJobOffer;
use Platform\Recruiting\Models\RecDispoJobOfferShift;
use Platform\Recruiting\Models\RecDispoJobRequest;
use Platform\Recruiting\Models\RecEmployee;
use Platform\Recruiting\Services\Zas\Dispo\Jobs\DispoJobZasAbgleich;

/** Zusagen werden mit angekommenen ZAS-Einbuchungen verknuepft. */
class DispoJobZasAbgleichTest extends TestCase
{
    private static int $seq = 0;

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
        $container->instance('config', new ConfigRepository(['recruiting' => ['zas' => ['inbound_team_id' => 0]]]));
        $own = dirname(__DIR__, 2);
        foreach ([
            'database/migrations/2026_08_12_000001_create_rec_dispo_events_table.php',
            'database/migrations/2026_08_12_000002_create_rec_dispo_assignments_table.php',
            'database/migrations/2026_08_14_000001_add_confirmation_fields_to_rec_dispo_assignments.php',
            'database/migrations/2026_08_20_000001_add_filiale_to_rec_dispo_events.php',
            'database/migrations/2026_09_04_000001_add_decline_fields_to_rec_dispo_assignments.php',
            'database/migrations/2026_10_13_000001_create_rec_dispo_job_tables.php',
            'database/migrations/2026_10_20_000001_add_stati_to_rec_dispo_job_waves.php',
            'database/migrations/2026_10_13_000002_add_anlass_to_rec_dispo_job_requests.php',
            'database/migrations/2026_05_20_000001_create_rec_employees_table.php',
            'database/migrations/2026_05_21_000005_add_zas_export_markers_to_rec_employees.php',
            'database/migrations/2026_05_22_000001_add_personnel_number_to_rec_employees.php',
            'database/migrations/2026_07_07_000001_add_cost_center_to_rec_employees.php',
            'database/migrations/2026_08_24_000004_add_portal_lock_to_rec_employees.php',
            'database/migrations/2026_08_26_000002_add_company_to_rec_employees.php',
        ] as $rel) {
            (require $own . '/' . $rel)->up();
        }
        $crm = dirname((string) (new \ReflectionClass(\Platform\Crm\Models\CrmContactLink::class))->getFileName());
        while ($crm !== '/' && !file_exists($crm . '/composer.json')) {
            $crm = dirname($crm);
        }
        (require $crm . '/database/migrations/2024_01_01_000020_create_crm_contact_links_table.php')->up();
    }

    public static function tearDownAfterClass(): void
    {
        Container::getInstance()->forgetInstance('config');
        Facade::clearResolvedInstances();
    }

    protected function setUp(): void
    {
        foreach (['rec_dispo_job_offer_shifts', 'rec_dispo_job_offers', 'rec_dispo_job_waves', 'rec_dispo_job_shifts', 'rec_dispo_job_requests', 'rec_dispo_assignments', 'rec_employees', 'crm_contact_links'] as $t) {
            Capsule::table($t)->delete();
        }
    }

    private function assignment(int $event, string $datum, int $emp, array $extra = []): RecDispoAssignment
    {
        $n = ++self::$seq;
        return RecDispoAssignment::create(array_merge([
            'ds_ref' => 'DS' . $n, 'rec_dispo_event_id' => $event, 'pnr_raw' => 'RG' . $emp,
            'rec_employee_id' => $emp, 'datum' => $datum, 'status_id' => RecDispoAssignment::STATUS_AUFTRAG,
        ], $extra));
    }

    private function offerShift(RecDispoJobRequest $req, int $event, string $datum, int $emp, bool $accepted): RecDispoJobOfferShift
    {
        $shift = $req->shifts()->create(['rec_dispo_event_id' => $event, 'datum' => $datum, 'von' => '17:00', 'taetigkeit' => 'Zapfer', 'gesucht' => 5]);
        $wave = $req->waves()->firstOrCreate(['nr' => 1], ['ma_bloecke' => [200], 'gruppen' => ['0'], 'nur_passende_taetigkeit' => true]);
        $offer = RecDispoJobOffer::firstOrCreate(
            ['wave_id' => $wave->id, 'request_id' => $req->id, 'rec_employee_id' => $emp],
            ['anchor_employee_id' => $emp]
        );
        return $offer->shifts()->create(['shift_id' => $shift->id, 'accepted_at' => $accepted ? now() : null]);
    }

    public function test_zusage_wird_mit_angekommener_einbuchung_verknuepft(): void
    {
        $req = RecDispoJobRequest::create(['team_id' => 3, 'filial_nr' => 200, 'zeitraum_von' => '2026-10-20', 'zeitraum_bis' => '2026-11-17',
            'modus' => RecDispoJobRequest::MODUS_SAMMELN, 'status' => RecDispoJobRequest::STATUS_ENTWURF]);

        $treffer = $this->offerShift($req, 1, '2026-10-24', 5, true);
        $andererTag = $this->offerShift($req, 1, '2026-10-25', 5, true);
        $andereVa = $this->offerShift($req, 2, '2026-10-24', 5, true);
        $storno = $this->offerShift($req, 3, '2026-10-24', 5, true);
        $fehlend = $this->offerShift($req, 4, '2026-10-24', 5, true);
        $markiert = $this->offerShift($req, 6, '2026-10-24', 5, true);
        $nichtZugesagt = $this->offerShift($req, 5, '2026-10-24', 5, false);
        $abgesagt = $this->offerShift($req, 7, '2026-10-24', 5, true);

        $this->assignment(1, '2026-10-24', 99);         // andere Person, KLEINERE id als die richtige
        $gut = $this->assignment(1, '2026-10-24', 5);
        $this->assignment(1, '2026-10-24', 5);          // zweiter Treffer, hoehere id -> kleinste gewinnt
        $this->assignment(1, '2026-10-26', 5);          // anderer Tag als Zusage 2
        $this->assignment(2, '2026-10-30', 5);          // andere Tage
        $this->assignment(3, '2026-10-24', 5, ['status_id' => 3]);
        $this->assignment(4, '2026-10-24', 5, ['missing_since' => now()]);
        $this->assignment(6, '2026-10-24', 5, ['deletion_marked_at' => now()]);
        $this->assignment(5, '2026-10-24', 5);          // passt, aber Zusage fehlt
        $this->assignment(7, '2026-10-24', 5, ['declined_at' => now()]); // abgesagt -> zaehlt nicht

        $run = app(DispoJobZasAbgleich::class);
        $this->assertSame(1, $run->run());

        $this->assertSame((int) $gut->id, (int) $treffer->fresh()->zas_assignment_id);
        foreach ([$andererTag, $andereVa, $storno, $fehlend, $markiert, $nichtZugesagt, $abgesagt] as $os) {
            $this->assertNull($os->fresh()->zas_assignment_id);
        }

        $this->assertSame(0, $run->run());
    }

    private function zweiMitarbeiterEinePerson(): array
    {
        $ids = [];
        foreach ([1, 2] as $i) {
            $ids[] = (int) Capsule::table('rec_employees')->insertGetId([
                'uuid' => (string) \Illuminate\Support\Str::uuid(), 'team_id' => 3, 'first_name' => 'E' . $i, 'last_name' => 'Muster',
                'is_active' => true, 'portal_token' => 'tok' . uniqid(), 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        foreach ($ids as $id) {
            Capsule::table('crm_contact_links')->insert([
                'uuid' => (string) \Illuminate\Support\Str::uuid(), 'contact_id' => 77, 'team_id' => 3, 'created_by_user_id' => 1,
                'linkable_id' => $id, 'linkable_type' => (new RecEmployee())->getMorphClass(), 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        return $ids;
    }

    private function request(): RecDispoJobRequest
    {
        return RecDispoJobRequest::create(['team_id' => 3, 'filial_nr' => 200, 'zeitraum_von' => '2026-10-20', 'zeitraum_bis' => '2026-11-17',
            'modus' => RecDispoJobRequest::MODUS_SAMMELN, 'status' => RecDispoJobRequest::STATUS_ENTWURF]);
    }

    public function test_einbuchung_des_zweiten_mitarbeiters_derselben_person_wird_mit_gruppe_verknuepft(): void
    {
        [$a, $b] = $this->zweiMitarbeiterEinePerson();
        $os = $this->offerShift($this->request(), 11, '2026-10-24', $a, true);
        $booking = $this->assignment(11, '2026-10-24', $b);

        config(['recruiting.zas.inbound_team_id' => 3]);
        try {
            $this->assertSame(1, app(DispoJobZasAbgleich::class)->run());
            $this->assertSame((int) $booking->id, (int) $os->fresh()->zas_assignment_id);
        } finally {
            config(['recruiting.zas.inbound_team_id' => 0]);
        }
    }

    public function test_ohne_gruppierung_verknuepft_die_einbuchung_des_anderen_mitarbeiters_nicht(): void
    {
        [$a, $b] = $this->zweiMitarbeiterEinePerson();
        $os = $this->offerShift($this->request(), 12, '2026-10-24', $a, true);
        $this->assignment(12, '2026-10-24', $b);

        $this->assertSame(0, app(DispoJobZasAbgleich::class)->run());
        $this->assertNull($os->fresh()->zas_assignment_id);
    }
}
