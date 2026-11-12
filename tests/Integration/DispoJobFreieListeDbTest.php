<?php

namespace Platform\Recruiting\Tests\Integration;

use Carbon\Carbon;
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
use Platform\Recruiting\Models\RecDispoJobRequest;
use Platform\Recruiting\Models\RecDispoJobShift;
use Platform\Recruiting\Services\Zas\Dispo\Jobs\DispoJobFreieListe;

/** „Wer ist an dem Tag frei?“ aus der Datenbank (Markus 12.11.2026) — Stand je Schicht und Filialen der Wellen. */
class DispoJobFreieListeDbTest extends TestCase
{
    private string $tag;

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

        $own = dirname(__DIR__, 2);
        $core = self::packageRootOf(\Platform\Core\Models\CoreLookup::class);
        $crm = self::packageRootOf(CrmContactLink::class);
        foreach ([
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
            [$own, 'database/migrations/2026_05_21_000004_add_linen_package_to_hr_data.php'],
            [$own, 'database/migrations/2026_09_15_000001_add_dispo_taetigkeiten_to_hr_data.php'],
            [$own, 'database/migrations/2026_08_12_000001_create_rec_dispo_events_table.php'],
            [$own, 'database/migrations/2026_08_12_000002_create_rec_dispo_assignments_table.php'],
            [$own, 'database/migrations/2026_08_14_000001_add_confirmation_fields_to_rec_dispo_assignments.php'],
            [$own, 'database/migrations/2026_08_20_000001_add_filiale_to_rec_dispo_events.php'],
            [$own, 'database/migrations/2026_09_04_000001_add_decline_fields_to_rec_dispo_assignments.php'],
            [$own, 'database/migrations/2026_10_13_000001_create_rec_dispo_job_tables.php'],
            [$own, 'database/migrations/2026_10_13_000002_add_anlass_to_rec_dispo_job_requests.php'],
            [$own, 'database/migrations/2026_10_14_000001_add_bis_to_rec_dispo_job_shifts.php'],
            [$own, 'database/migrations/2026_10_20_000001_add_stati_to_rec_dispo_job_waves.php'],
            [$own, 'database/migrations/2026_10_27_000001_add_wellen_to_rec_dispo_job_tables.php'],
            [$core, 'database/migrations/2026_02_12_000003_create_core_lookups_tables.php'],
            [$crm, 'database/migrations/2024_01_01_000020_create_crm_contact_links_table.php'],
        ] as [$root, $relative]) {
            $path = $root . '/' . $relative;
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

    private static function packageRootOf(string $class): string
    {
        $dir = dirname((string) (new \ReflectionClass($class))->getFileName());
        while ($dir !== '/' && !file_exists($dir . '/composer.json')) {
            $dir = dirname($dir);
        }

        return $dir;
    }

    protected function setUp(): void
    {
        foreach (['rec_dispo_job_offer_shifts', 'rec_dispo_job_offers', 'rec_dispo_job_waves', 'rec_dispo_job_shifts', 'rec_dispo_job_requests',
            'crm_contact_links', 'rec_employees', 'rec_employee_hr_data', 'rec_positions', 'rec_dispo_events', 'rec_dispo_assignments'] as $t) {
            Capsule::table($t)->delete();
        }
        $this->tag = Carbon::now()->addDays(10)->toDateString();
    }

    private function employee(string $first, string $kst = '201', array $taetigkeiten = ['Zapfer']): int
    {
        $id = (int) Capsule::table('rec_employees')->insertGetId([
            'uuid' => (string) \Illuminate\Support\Str::uuid(), 'team_id' => 3, 'first_name' => $first, 'last_name' => 'Muster', 'is_active' => true,
            'phone' => '0176 1234567' . strlen($first), 'cost_center' => $kst, 'portal_token' => 'tok-' . $first, 'created_at' => now(), 'updated_at' => now(),
        ]);
        Capsule::table('rec_employee_hr_data')->insert(['uuid' => (string) \Illuminate\Support\Str::uuid(), 'team_id' => 3, 'rec_employee_id' => $id,
            'dispo_taetigkeiten' => json_encode($taetigkeiten), 'created_at' => now(), 'updated_at' => now()]);

        return $id;
    }

    /** @return array{0:RecDispoJobRequest,1:RecDispoJobShift} */
    private function anfrage(?array $bloecke = [200]): array
    {
        $request = RecDispoJobRequest::create(['team_id' => 3, 'filial_nr' => 200, 'zeitraum_von' => $this->tag, 'zeitraum_bis' => $this->tag,
            'modus' => RecDispoJobRequest::MODUS_SAMMELN, 'status' => RecDispoJobRequest::STATUS_LAEUFT]);
        $shift = $request->shifts()->create(['rec_dispo_event_id' => 1, 'datum' => $this->tag, 'von' => '17:00', 'bis' => '23:00', 'taetigkeit' => 'Zapfer', 'gesucht' => 5]);
        if ($bloecke !== null) {
            $request->waves()->create(['nr' => 1, 'ma_bloecke' => $bloecke, 'gruppen' => ['alle'], 'nur_passende_taetigkeit' => true]);
        }

        return [$request, $shift];
    }

    private function angebot(RecDispoJobRequest $request, RecDispoJobShift $shift, int $employee, array $offerShift = [], bool $gesendet = true): void
    {
        $offerId = (int) Capsule::table('rec_dispo_job_offers')->insertGetId(['wave_id' => 1, 'request_id' => $request->id, 'rec_employee_id' => $employee,
            'anchor_employee_id' => $employee, 'sent_at' => $gesendet ? now() : null, 'send_error' => $gesendet ? null : 'x', 'created_at' => now(), 'updated_at' => now()]);
        Capsule::table('rec_dispo_job_offer_shifts')->insert($offerShift + ['offer_id' => $offerId, 'shift_id' => $shift->id, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function namen(array $liste): array
    {
        return array_map(fn ($z) => explode(' ', $z['name'])[0], $liste['zeilen']);
    }

    public function test_stand_je_person_kommt_aus_den_angeboten_dieser_schicht(): void
    {
        [$request, $shift] = $this->anfrage();
        $nie = $this->employee('Anna');
        $offen = $this->employee('Bernd');
        $ja = $this->employee('Cem');
        $nein = $this->employee('Dana');
        $fehl = $this->employee('Emil');
        $this->angebot($request, $shift, $offen);
        $this->angebot($request, $shift, $ja, ['accepted_at' => now()]);
        $this->angebot($request, $shift, $nein, ['declined_at' => now(), 'declined_reason' => 'krank']);
        $this->angebot($request, $shift, $fehl, [], false);

        $liste = DispoJobFreieListe::laden(3, $request, $shift);

        $this->assertEqualsCanonicalizing(['Anna', 'Bernd', 'Emil'], $this->namen($liste));
        $angeschrieben = array_column($liste['zeilen'], 'angeschrieben', 'employee_id');
        $this->assertFalse($angeschrieben[$nie]);
        $this->assertTrue($angeschrieben[$offen]);
        $this->assertFalse($angeschrieben[$fehl], 'Fehlversand hat die Person nicht erreicht');
        $this->assertSame(1, $liste['ohne']['zugesagt']);
        $this->assertSame(1, $liste['ohne']['abgesagt']);
        $this->assertStringStartsWith('+49', $liste['zeilen'][0]['phone']);
    }

    public function test_buchung_am_tag_nimmt_die_person_heraus(): void
    {
        [$request, $shift] = $this->anfrage();
        $this->employee('Anna');
        $b = $this->employee('Bernd');
        $event = RecDispoEvent::create(['einsatz_ref' => 'E1']);
        RecDispoAssignment::create(['ds_ref' => 'd1', 'rec_dispo_event_id' => $event->id, 'pnr_raw' => 'x', 'rec_employee_id' => $b, 'datum' => $this->tag, 'status_id' => 1, 'taetigkeit' => 'Zapfer']);

        $liste = DispoJobFreieListe::laden(3, $request, $shift);

        $this->assertSame(['Anna'], $this->namen($liste));
        $this->assertSame(1, $liste['ohne']['gebucht']);
    }

    private function paaren(int $kontakt, int ...$ids): void
    {
        foreach ($ids as $id) {
            Capsule::table('crm_contact_links')->insert([
                'uuid' => (string) \Illuminate\Support\Str::uuid(), 'contact_id' => $kontakt, 'team_id' => 3, 'created_by_user_id' => 1,
                'linkable_id' => $id, 'linkable_type' => (new \Platform\Recruiting\Models\RecEmployee())->getMorphClass(), 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    /**
     * Review 12.11.2026: Zusage/Absage haengt am Datensatz von damals. Wird der aeltere
     * Datensatz der Person deaktiviert, wandert ihr Anker — sie darf trotzdem nicht als frei erscheinen.
     */
    public function test_zusage_auf_inzwischen_deaktiviertem_datensatz_zaehlt_fuer_die_person(): void
    {
        config(['recruiting.zas.inbound_team_id' => 3]);
        try {
            [$request, $shift] = $this->anfrage();
            $altJa = $this->employee('AltJa');
            $neuJa = $this->employee('NeuJa');
            $altNein = $this->employee('AltNein');
            $neuNein = $this->employee('NeuNein');
            $this->employee('Frei');
            $this->paaren(71, $altJa, $neuJa);
            $this->paaren(72, $altNein, $neuNein);
            $this->angebot($request, $shift, $altJa, ['accepted_at' => now()]);
            $this->angebot($request, $shift, $altNein, ['declined_at' => now(), 'declined_reason' => 'krank']);
            Capsule::table('rec_employees')->whereIn('id', [$altJa, $altNein])->update(['is_active' => false]);

            $liste = DispoJobFreieListe::laden(3, $request, $shift);

            $this->assertSame(['Frei'], $this->namen($liste));
            $this->assertSame(1, $liste['ohne']['zugesagt']);
            $this->assertSame(1, $liste['ohne']['abgesagt']);
        } finally {
            config(['recruiting.zas.inbound_team_id' => 0]);
        }
    }

    /** Dasselbe fuer die Tagesregel: Zusage in einer ANDEREN Anfrage am selben Tag, gespeichert unter dem alten Anker. */
    public function test_zusage_am_selben_tag_in_anderer_anfrage_auch_nach_ankerwechsel(): void
    {
        config(['recruiting.zas.inbound_team_id' => 3]);
        try {
            [$request, $shift] = $this->anfrage();
            [$andere, $andereSchicht] = $this->anfrage();
            $alt = $this->employee('Alt');
            $neu = $this->employee('Neu');
            $direkt = $this->employee('Direkt');
            $this->employee('Frei');
            $this->paaren(71, $alt, $neu);
            $this->angebot($andere, $andereSchicht, $alt, ['accepted_at' => now()]);
            $this->angebot($andere, $andereSchicht, $direkt, ['accepted_at' => now()]);
            Capsule::table('rec_employees')->where('id', $alt)->update(['is_active' => false]);

            $liste = DispoJobFreieListe::laden(3, $request, $shift);

            $this->assertSame(['Frei'], $this->namen($liste));
            $this->assertSame(2, $liste['ohne']['zugesagt']);
        } finally {
            config(['recruiting.zas.inbound_team_id' => 0]);
        }
    }

    /** Grundmenge = Filialen ALLER Wellen der Anfrage; ohne Welle alle. */
    public function test_filialen_kommen_aus_den_wellen(): void
    {
        $this->employee('Anna', '201');
        $this->employee('Bernd', '401');

        [$request, $shift] = $this->anfrage([200]);
        $this->assertSame(['Anna'], $this->namen(DispoJobFreieListe::laden(3, $request, $shift)));

        $request->waves()->create(['nr' => 2, 'ma_bloecke' => [400], 'gruppen' => ['alle'], 'nur_passende_taetigkeit' => true]);
        $beide = DispoJobFreieListe::laden(3, $request, $shift);
        $this->assertEqualsCanonicalizing(['Anna', 'Bernd'], $this->namen($beide));
        $this->assertSame([200, 400], $beide['bloecke']);

        [$ohneWelle, $s2] = $this->anfrage(null);
        $alle = DispoJobFreieListe::laden(3, $ohneWelle, $s2);
        $this->assertEqualsCanonicalizing(['Anna', 'Bernd'], $this->namen($alle));
        $this->assertNull($alle['bloecke']);
    }
}
