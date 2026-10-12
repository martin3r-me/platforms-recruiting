<?php

namespace Platform\Recruiting\Tests\Integration;

use Carbon\Carbon;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use Platform\Crm\Models\CrmContactLink;
use Platform\Crm\Services\Comms\WhatsAppMetaService;
use Platform\Recruiting\Jobs\SendDispoJobWave;
use Platform\Recruiting\Models\RecDispoAssignment;
use Platform\Recruiting\Models\RecDispoEvent;
use Platform\Recruiting\Models\RecDispoJobOffer;
use Platform\Recruiting\Models\RecDispoJobRequest;
use Platform\Recruiting\Models\RecDispoJobWave;
use Platform\Recruiting\Services\Zas\Dispo\DispoChannelResolver;
use Platform\Recruiting\Services\Zas\Dispo\Jobs\DispoJobOfferSender;

/** Welle-Job: plant beim Start neu, sendet ueber den ECHTEN Sender (Meta-Stub), schliesst die Welle. */
class SendDispoJobWaveJobTest extends TestCase
{
    private object $stub;
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
        self::runMigrations();
    }

    public static function tearDownAfterClass(): void
    {
        $c = Container::getInstance();
        $c->forgetInstance('config');
        $c->forgetInstance(WhatsAppMetaService::class);
        $c->forgetInstance(DispoChannelResolver::class);
        Facade::clearResolvedInstances();
    }

    protected function setUp(): void
    {
        foreach (['integrations_whatsapp_templates', 'rec_dispo_job_offer_shifts', 'rec_dispo_job_offers', 'rec_dispo_job_waves', 'rec_dispo_job_shifts', 'rec_dispo_job_requests',
            'crm_contact_links', 'rec_employees', 'rec_employee_hr_data', 'rec_positions', 'rec_dispo_events', 'rec_dispo_assignments'] as $t) {
            Capsule::table($t)->delete();
        }

        $this->stub = new class {
            public int $calls = 0;
            public function sendTemplate($channel, string $to, string $templateName, array $components = [], string $languageCode = 'de', $sender = null, bool $isAutoReply = false): object
            {
                $this->calls++;
                return (object) ['id' => 7000 + $this->calls, 'status' => 'sent'];
            }
        };
        Container::getInstance()->instance(WhatsAppMetaService::class, $this->stub);
        Container::getInstance()->instance(DispoChannelResolver::class, new class {
            public function resolveForEvent($event) { return new \Platform\Crm\Models\CommsChannel(); }
        });
    }

    private function employee(string $first, string $phone): int
    {
        return (int) Capsule::table('rec_employees')->insertGetId([
            'uuid' => (string) \Illuminate\Support\Str::uuid(), 'team_id' => 3, 'first_name' => $first, 'last_name' => 'Muster', 'is_active' => true,
            'phone' => $phone, 'cost_center' => '201', 'portal_token' => 'tok-' . $first, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** @return array{0:RecDispoJobRequest,1:RecDispoJobWave,2:string} */
    private function anfrage(array $wave = []): array
    {
        $templateId = (int) Capsule::table('integrations_whatsapp_templates')->insertGetId([
            'uuid' => 'tpl-' . mt_rand(), 'external_id' => 'ext-' . mt_rand(), 'user_id' => 1, 'whatsapp_account_id' => 1,
            'name' => 't_jobs', 'language' => 'de', 'status' => 'APPROVED', 'category' => 'UTILITY',
            'components' => json_encode([['type' => 'BODY', 'text' => 'Hi {{name}}']]), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $datum = Carbon::now()->addDays(10)->toDateString();
        $request = RecDispoJobRequest::create(['team_id' => 3, 'filial_nr' => 200, 'zeitraum_von' => $datum, 'zeitraum_bis' => $datum,
            'modus' => RecDispoJobRequest::MODUS_SAMMELN, 'status' => RecDispoJobRequest::STATUS_ENTWURF, 'whatsapp_template_id' => $templateId]);
        $request->shifts()->create(['rec_dispo_event_id' => 1, 'datum' => $datum, 'von' => '17:00', 'taetigkeit' => 'Zapfer', 'gesucht' => 5]);
        $w = $request->waves()->create($wave + ['nr' => 1, 'ma_bloecke' => [200], 'gruppen' => ['alle'], 'nur_passende_taetigkeit' => false]);

        return [$request, $w, $datum];
    }

    private function cache(): Repository
    {
        return new Repository(new ArrayStore());
    }

    private function sender(): DispoJobOfferSender
    {
        return new DispoJobOfferSender();
    }

    public function test_welle_plant_beim_start_neu_sendet_und_schliesst(): void
    {
        [$request, $wave, $datum] = $this->anfrage();
        $anna = $this->employee('Anna', '0176 12345678');
        $ben = $this->employee('Ben', '0176 87654321');
        $event = RecDispoEvent::create(['einsatz_ref' => 'E1']);
        RecDispoAssignment::create(['ds_ref' => 'd1', 'rec_dispo_event_id' => $event->id, 'pnr_raw' => 'x', 'rec_employee_id' => $ben, 'datum' => $datum, 'status_id' => 1, 'taetigkeit' => 'Zapfer']);
        $cache = $this->cache();

        $job = new SendDispoJobWave($wave->id, 3, 9);
        $job->handle($cache, $this->sender());

        $wave->refresh();
        $this->assertNotNull($wave->started_at);
        $this->assertNotNull($wave->finished_at);
        $this->assertSame(1, $this->stub->calls);
        $this->assertSame(1, $wave->summary['sent']);
        $this->assertSame(1, $wave->summary['skipped_toepfe']['alle_tage_gebucht']);
        $this->assertTrue($wave->summary['done']);
        $this->assertSame(RecDispoJobRequest::STATUS_LAEUFT, $request->fresh()->status);
        $this->assertSame([$anna], RecDispoJobOffer::pluck('anchor_employee_id')->map(fn ($v) => (int) $v)->all());
        $this->assertTrue($cache->get(SendDispoJobWave::cacheKey($wave->job_uuid))['done']);
    }

    public function test_geplante_welle_in_der_zukunft_sendet_nichts(): void
    {
        [, $wave] = $this->anfrage(['scheduled_for' => Carbon::now()->addMinutes(30)]);
        $this->employee('Anna', '0176 12345678');

        (new SendDispoJobWave($wave->id, 3, 9))->handle($this->cache(), $this->sender());

        $this->assertNull($wave->fresh()->started_at);
        $this->assertSame(0, $this->stub->calls);
    }

    public function test_geloeschte_welle_sendet_nichts(): void
    {
        (new SendDispoJobWave(999, 3, 9))->handle($this->cache(), $this->sender());

        $this->assertSame(0, $this->stub->calls);
    }

    public function test_schon_gestartete_welle_laeuft_nicht_zweimal(): void
    {
        [, $wave] = $this->anfrage(['started_at' => Carbon::now()->subMinute()]);
        $this->employee('Anna', '0176 12345678');

        (new SendDispoJobWave($wave->id, 3, 9))->handle($this->cache(), $this->sender());

        $this->assertSame(0, $this->stub->calls);
        $this->assertNull($wave->fresh()->finished_at);
    }

    private static function runMigrations(): void
    {
        $own = dirname(__DIR__, 2);
        $core = self::packageRootOf(\Platform\Core\Models\CoreLookup::class);
        $crm = self::packageRootOf(CrmContactLink::class);
        $integrations = self::packageRootOf(\Platform\Integrations\Models\IntegrationsWhatsAppTemplate::class);
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
            [$own, 'database/migrations/2026_10_13_000001_create_rec_dispo_job_tables.php'],
            [$core, 'database/migrations/2026_02_12_000003_create_core_lookups_tables.php'],
            [$crm, 'database/migrations/2024_01_01_000020_create_crm_contact_links_table.php'],
            [$integrations, 'database/migrations/2026_01_17_150000_create_integrations_whatsapp_accounts_table.php'],
            [$integrations, 'database/migrations/2026_02_12_000001_create_integrations_whatsapp_templates_table.php'],
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
        $dir = dirname((string) (new \ReflectionClass($class))->getFileName());
        while ($dir !== '/' && !file_exists($dir . '/composer.json')) {
            $dir = dirname($dir);
        }

        return $dir;
    }
}
