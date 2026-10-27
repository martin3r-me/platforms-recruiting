<?php

namespace Platform\Recruiting\Tests\Integration;

use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use Platform\Crm\Services\Comms\WhatsAppMetaService;
use Platform\Recruiting\Models\RecDispoJobOffer;
use Platform\Recruiting\Models\RecDispoJobOfferShift;
use Platform\Recruiting\Models\RecDispoJobRequest;
use Platform\Recruiting\Services\Zas\Dispo\DispoChannelResolver;
use Platform\Recruiting\Services\Zas\Dispo\Jobs\DispoJobOfferSender;

/** Angebots-Sender: Angebote anlegen, name + Token senden, Fehler am Angebot stempeln, nie doppelt anschreiben. */
class DispoJobOfferSenderTest extends TestCase
{
    private object $stub;

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
        $container->instance('config', new ConfigRepository(['recruiting' => ['zas' => []]]));
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
        foreach (['integrations_whatsapp_templates', 'rec_dispo_job_offer_shifts', 'rec_dispo_job_offers', 'rec_dispo_job_waves', 'rec_dispo_job_shifts', 'rec_dispo_job_requests'] as $t) {
            Capsule::table($t)->delete();
        }

        $this->stub = new class {
            public int $calls = 0;
            public array $log = [];
            public array $failFor = [];
            public array $throwFor = [];

            public function sendTemplate($channel, string $to, string $templateName, array $components = [], string $languageCode = 'de', $sender = null, bool $isAutoReply = false): object
            {
                $this->calls++;
                $this->log[] = ['to' => $to, 'template' => $templateName, 'components' => $components];
                if (in_array($to, $this->throwFor, true)) {
                    throw new \RuntimeException('Netz weg');
                }
                if (in_array($to, $this->failFor, true)) {
                    return (object) ['id' => 1, 'status' => 'failed', 'meta_payload' => ['error' => ['message' => 'kaputt']]];
                }
                return (object) ['id' => 7000 + $this->calls, 'status' => 'sent'];
            }
        };
        Container::getInstance()->instance(WhatsAppMetaService::class, $this->stub);
        Container::getInstance()->instance(DispoChannelResolver::class, new class {
            public function resolveForEvent($event) { return new \Platform\Crm\Models\CommsChannel(); }
        });
    }

    private function template(string $body, string $status = 'APPROVED'): int
    {
        return (int) Capsule::table('integrations_whatsapp_templates')->insertGetId([
            'uuid' => 'tpl-' . mt_rand(), 'external_id' => 'ext-' . mt_rand(), 'user_id' => 1,
            'whatsapp_account_id' => 1, 'name' => 't_jobs', 'language' => 'de', 'status' => $status,
            'category' => 'UTILITY',
            'components' => json_encode([['type' => 'BODY', 'text' => $body]]),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function anfrageMitWelle(): array
    {
        $request = RecDispoJobRequest::create(['team_id' => 3, 'filial_nr' => 200, 'zeitraum_von' => '2026-10-20', 'zeitraum_bis' => '2026-11-17',
            'modus' => RecDispoJobRequest::MODUS_SAMMELN, 'status' => RecDispoJobRequest::STATUS_ENTWURF]);
        $shift = $request->shifts()->create(['rec_dispo_event_id' => 1, 'datum' => '2026-10-24', 'von' => '17:00', 'taetigkeit' => 'Zapfer', 'gesucht' => 5]);
        $wave = $request->waves()->create(['nr' => 1, 'ma_bloecke' => [200], 'gruppen' => ['alle']]);

        return [$request, $wave, $shift];
    }

    private function recipient(int $shiftId, string $phone = '+491761', string $first = 'Anna'): array
    {
        return ['anchor' => 5, 'employee_id' => 5, 'phone' => $phone, 'first_name' => $first, 'portal_token' => 'tok5', 'shift_ids' => [$shiftId]];
    }

    public function test_legt_angebote_an_und_sendet_name_und_token(): void
    {
        $templateId = $this->template('Hi {{name}}');
        [, $wave, $shift] = $this->anfrageMitWelle();

        $r = (new DispoJobOfferSender())->send($wave, [$this->recipient($shift->id)], $templateId);

        $this->assertSame(1, $r['sent']);
        $this->assertSame([['type' => 'text', 'parameter_name' => 'name', 'text' => 'Anna']], $this->stub->log[0]['components'][0]['parameters']);
        $this->assertSame([['type' => 'text', 'text' => 'tok5']], $this->stub->log[0]['components'][1]['parameters']);
        $offer = RecDispoJobOffer::where('wave_id', $wave->id)->first();
        $this->assertSame(5, (int) $offer->anchor_employee_id);
        $this->assertNotNull($offer->sent_at);
        $this->assertSame([$shift->id], $offer->shifts()->pluck('shift_id')->map(fn ($v) => (int) $v)->all());
    }

    public function test_fehlschlag_wird_am_angebot_gestempelt(): void
    {
        $templateId = $this->template('Hi');
        [, $wave, $shift] = $this->anfrageMitWelle();
        $this->stub->failFor = ['+491761'];

        $r = (new DispoJobOfferSender())->send($wave, [$this->recipient($shift->id)], $templateId);

        $this->assertSame(0, $r['sent']);
        $this->assertSame('kaputt', RecDispoJobOffer::first()->send_error);
        $this->assertNull(RecDispoJobOffer::first()->sent_at);
    }

    public function test_zweites_angebot_an_dieselbe_person_in_derselben_anfrage_wird_nicht_angelegt(): void
    {
        $templateId = $this->template('Hi');
        [, $wave, $shift] = $this->anfrageMitWelle();
        $sender = new DispoJobOfferSender();

        $sender->send($wave, [$this->recipient($shift->id)], $templateId);
        $r = $sender->send($wave, [$this->recipient($shift->id)], $templateId);

        $this->assertSame(0, $r['sent']);
        $this->assertSame('in dieser Anfrage schon angeschrieben', $r['failed'][0]['error']);
        $this->assertSame(1, $this->stub->calls);
        $this->assertSame(1, RecDispoJobOffer::count());
        $this->assertSame(1, RecDispoJobOfferShift::count());
    }

    public function test_exception_im_versand_wird_gesammelt_und_am_angebot_gestempelt(): void
    {
        $templateId = $this->template('Hi');
        [, $wave, $shift] = $this->anfrageMitWelle();
        $this->stub->throwFor = ['+491761'];

        $r = (new DispoJobOfferSender())->send($wave, [$this->recipient($shift->id)], $templateId);

        $this->assertSame(0, $r['sent']);
        $this->assertCount(1, $r['failed']);
        $this->assertSame('Netz weg', $r['failed'][0]['error']);
        $offer = RecDispoJobOffer::first();
        $this->assertSame('Netz weg', $offer->send_error);
        $this->assertNull($offer->sent_at);
        $this->assertSame(1, RecDispoJobOfferShift::count());
    }

    public function test_body_ohne_name_hat_nur_den_button(): void
    {
        $templateId = $this->template('Neue Jobs fuer dich');
        [, $wave, $shift] = $this->anfrageMitWelle();

        (new DispoJobOfferSender())->send($wave, [$this->recipient($shift->id)], $templateId);

        $this->assertCount(1, $this->stub->log[0]['components']);
        $this->assertSame('button', $this->stub->log[0]['components'][0]['type']);
    }

    public function test_db_fehler_beim_anlegen_bricht_die_schleife_nicht_ab(): void
    {
        $templateId = $this->template('Hi');
        [, $wave, $shift] = $this->anfrageMitWelle();
        $bad = $this->recipient($shift->id);
        $bad['shift_ids'] = [$shift->id, null]; // shift_id NOT NULL -> DB-Fehler
        $good = $this->recipient($shift->id, '+491762');
        $good['anchor'] = 6;
        $good['employee_id'] = 6;

        $r = (new DispoJobOfferSender())->send($wave, [$bad, $good], $templateId);

        $this->assertTrue($r['ok']);
        $this->assertSame(1, $r['sent']);
        $this->assertCount(1, $r['failed']);
        $this->assertSame(5, $r['failed'][0]['anchor']);
    }

    public function test_vorlage_nicht_freigegeben_blockt(): void
    {
        $templateId = $this->template('Hi', 'PENDING');
        [, $wave, $shift] = $this->anfrageMitWelle();

        $r = (new DispoJobOfferSender())->send($wave, [$this->recipient($shift->id)], $templateId);

        $this->assertFalse($r['ok']);
        $this->assertSame(0, $this->stub->calls);
        $this->assertSame(0, RecDispoJobOffer::count());
    }

    private static function runMigrations(): void
    {
        $own = dirname(__DIR__, 2);
        $integrations = self::packageRootOf(\Platform\Integrations\Models\IntegrationsWhatsAppTemplate::class);
        foreach ([
            [$own, 'database/migrations/2026_08_12_000001_create_rec_dispo_events_table.php'],
            [$own, 'database/migrations/2026_10_13_000001_create_rec_dispo_job_tables.php'],
            [$own, 'database/migrations/2026_10_20_000001_add_stati_to_rec_dispo_job_waves.php'],
            [$own, 'database/migrations/2026_10_27_000001_add_wellen_to_rec_dispo_job_tables.php'],
            [$own, 'database/migrations/2026_10_13_000002_add_anlass_to_rec_dispo_job_requests.php'],
            [$integrations, 'database/migrations/2026_01_17_150000_create_integrations_whatsapp_accounts_table.php'],
            [$integrations, 'database/migrations/2026_02_12_000001_create_integrations_whatsapp_templates_table.php'],
        ] as [$root, $relative]) {
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

    public function test_datum_variable_nur_wenn_die_vorlage_sie_traegt_in_vorlagenreihenfolge(): void
    {
        $templateId = $this->template('Für den {{datum}} brauchen wir dich, {{name}}!');
        [, $wave, $shift] = $this->anfrageMitWelle();

        $r = (new DispoJobOfferSender())->send($wave, [$this->recipient($shift->id) + ['datum' => '2026-10-31']], $templateId);

        $this->assertSame(1, $r['sent']);
        $this->assertSame([
            ['type' => 'text', 'parameter_name' => 'datum', 'text' => 'Sa, 31.10.'],
            ['type' => 'text', 'parameter_name' => 'name', 'text' => 'Anna'],
        ], $this->stub->log[0]['components'][0]['parameters']);

        $nurName = $this->template('Hi {{name}}');
        [, $wave2, $shift2] = $this->anfrageMitWelle();
        (new DispoJobOfferSender())->send($wave2, [$this->recipient($shift2->id) + ['datum' => '2026-10-31']], $nurName);
        $this->assertSame([['type' => 'text', 'parameter_name' => 'name', 'text' => 'Anna']], $this->stub->log[1]['components'][0]['parameters'], 'ohne {{datum}} in der Vorlage kein Datum');
        $this->assertSame('bald', DispoJobOfferSender::datumText(''), 'nie ein leerer Parameter');
    }
}
