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
use Platform\Crm\Models\CommsChannel;
use Platform\Crm\Services\Comms\WhatsAppMetaService;
use Platform\Integrations\Models\IntegrationsWhatsAppTemplate;
use Platform\Recruiting\Services\Comms\HoldingTemplateSender;
use Platform\Recruiting\Services\Comms\NummernwechselHinweisSender;
use Platform\Recruiting\Support\PortalVorlagen;

/**
 * Der Hinweis an die alte Nummer liest seine Vorlage aus der Team-Einstellung
 * (PortalVorlagen::HINWEIS_ALTE_NUMMER), nicht aus der Config.
 *
 * Aufbau wie EinmalcodeSenderTest: Capsule + SQLite, echte Migrationen fuer
 * Kanal/Konto/Vorlage/Einstellung, Meta-Attrappe, Log-Attrappe.
 */
final class NummernwechselHinweisSenderTest extends TestCase
{
    private const TEAM = 3;

    private const ALTE_NUMMER = '+4915111111111';

    private const NEUE_NUMMER = '+4915122222222';

    private const ANGEFASST = '2026-09-28 09:00:00';

    private Capsule $capsule;

    private object $log;

    private object $meta;

    private int $personId;

    private int $kontoId;

    protected function setUp(): void
    {
        parent::setUp();
        $container = Container::getInstance();
        Container::setInstance($container);

        if (!class_exists('Log', false)) {
            class_alias(\Illuminate\Support\Facades\Log::class, 'Log');
        }
        $this->log = new class {
            public array $zeilen = [];

            public function __call($m, $a)
            {
                $this->zeilen[] = ['stufe' => $m, 'nachricht' => (string) ($a[0] ?? ''), 'daten' => (array) ($a[1] ?? [])];
            }
        };
        $container->instance('log', $this->log);
        $container->instance('config', new ConfigRepository(['recruiting' => ['konto' => []]]));

        $this->meta = new class {
            public array $calls = [];

            public function sendTemplate($channel, string $to, string $templateName, array $components = [], string $languageCode = 'de', $sender = null, bool $isAutoReply = false)
            {
                $this->calls[] = ['to' => $to, 'templateName' => $templateName, 'components' => $components, 'sprache' => $languageCode];

                return (object) ['status' => 'sent', 'meta_payload' => []];
            }
        };
        $container->instance(WhatsAppMetaService::class, $this->meta);
        $container->instance(HoldingTemplateSender::class, new HoldingTemplateSender(new class extends WhatsAppMetaService {}));

        $this->capsule = new Capsule($container);
        $this->capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $this->capsule->setEventDispatcher(new Dispatcher($container));
        $this->capsule->setAsGlobal();
        $this->capsule->bootEloquent();
        Model::clearBootedModels();

        $container->instance('db', $this->capsule->getDatabaseManager());
        $container->instance('db.schema', $this->capsule->getConnection()->getSchemaBuilder());
        Facade::setFacadeApplication($container);
        Facade::clearResolvedInstances();

        $this->capsule->schema()->create('rec_persons', function ($t) {
            $t->increments('id');
            $t->string('uuid', 64)->unique();
            $t->integer('team_id')->nullable();
            $t->string('phone', 32)->nullable();
            $t->timestamps();
        });
        $this->capsule->schema()->create('rec_employees', function ($t) {
            $t->increments('id');
            $t->integer('team_id')->nullable();
            $t->string('first_name')->nullable();
            $t->integer('rec_person_id')->nullable();
            $t->timestamps();
        });

        $this->echteMigrationen();

        $this->kontoId = (int) DB::table('integrations_whatsapp_accounts')->insertGetId([
            'uuid' => 'acc-hinweis', 'phone_number' => '+49 160 5552001', 'title' => 'WABA', 'active' => true, 'user_id' => 1,
        ]);
        DB::table('comms_channels')->insert([
            'team_id' => self::TEAM, 'name' => 'Recruiting WhatsApp', 'type' => 'whatsapp', 'provider' => 'whatsapp_meta',
            'sender_identifier' => '+49 160 5552001', 'is_active' => true,
            'meta' => json_encode(['integrations_whatsapp_account_id' => $this->kontoId]),
            'created_at' => self::ANGEFASST, 'updated_at' => self::ANGEFASST,
        ]);

        // Person hat bereits die NEUE Nummer; die alte wird dem Sender gereicht.
        $this->personId = (int) DB::table('rec_persons')->insertGetId([
            'uuid' => 'p-hinweis', 'team_id' => self::TEAM, 'phone' => self::NEUE_NUMMER,
            'created_at' => self::ANGEFASST, 'updated_at' => self::ANGEFASST,
        ]);
        DB::table('rec_employees')->insert([
            'team_id' => self::TEAM, 'first_name' => 'Gregor', 'rec_person_id' => $this->personId,
            'created_at' => self::ANGEFASST, 'updated_at' => self::ANGEFASST,
        ]);
    }

    protected function tearDown(): void
    {
        Model::unsetConnectionResolver();
        Model::clearBootedModels();
        $container = Container::getInstance();
        $container->forgetInstance('log');
        $container->forgetInstance('config');
        $container->forgetInstance(WhatsAppMetaService::class);
        $container->forgetInstance(HoldingTemplateSender::class);
        Facade::clearResolvedInstances();
        parent::tearDown();
    }

    public function testGesetzteVorlageVerschicktMitVornamen(): void
    {
        $this->feld($this->vorlage('nummer_geaendert', 'Hallo {{vorname}}, deine Nummer wurde geaendert.'));

        $status = (new NummernwechselHinweisSender())->sende($this->personId, self::ALTE_NUMMER);

        $this->assertSame(NummernwechselHinweisSender::STATUS_SENT, $status);
        $this->assertCount(1, $this->meta->calls);
        $this->assertSame(self::ALTE_NUMMER, $this->meta->calls[0]['to']);
        $this->assertSame('nummer_geaendert', $this->meta->calls[0]['templateName']);
        $this->assertSame(
            [['type' => 'body', 'parameters' => [['type' => 'text', 'text' => 'Gregor', 'parameter_name' => 'vorname']]]],
            $this->meta->calls[0]['components'],
        );
    }

    public function testVorlageOhnePlatzhalterSchicktKeineKomponenten(): void
    {
        $this->feld($this->vorlage('nummer_geaendert', 'Deine Nummer wurde geaendert.'));

        $status = (new NummernwechselHinweisSender())->sende($this->personId, self::ALTE_NUMMER);

        $this->assertSame(NummernwechselHinweisSender::STATUS_SENT, $status);
        $this->assertSame([], $this->meta->calls[0]['components']);
    }

    public function testLeeresFeldVerschicktNichtsUndNenntDenOrt(): void
    {
        $this->feld(null);

        $status = (new NummernwechselHinweisSender())->sende($this->personId, self::ALTE_NUMMER);

        $this->assertSame(NummernwechselHinweisSender::STATUS_FAILED, $status);
        $this->assertSame([], $this->meta->calls);
        $this->assertStringContainsString('Mitarbeiterportal', $this->logText());
        $this->assertStringContainsString('Hinweis an die alte Nummer', $this->logText());
    }

    public function testUnbekannterPlatzhalterVerschicktNichts(): void
    {
        $this->feld($this->vorlage('nummer_geaendert', 'Hallo {{vorname}}, neue Nummer {{nummer}}.'));

        $status = (new NummernwechselHinweisSender())->sende($this->personId, self::ALTE_NUMMER);

        $this->assertSame(NummernwechselHinweisSender::STATUS_FAILED, $status);
        $this->assertSame([], $this->meta->calls);
        $this->assertStringContainsString('nummer', $this->logText());
    }

    public function testKnopfMitVariableVerschicktNichts(): void
    {
        $this->feld($this->vorlage('nummer_geaendert', 'Hallo.', 'APPROVED', [['type' => 'URL', 'url' => 'https://a.de/{{1}}']]));

        $status = (new NummernwechselHinweisSender())->sende($this->personId, self::ALTE_NUMMER);

        $this->assertSame(NummernwechselHinweisSender::STATUS_FAILED, $status);
        $this->assertSame([], $this->meta->calls);
        $this->assertStringContainsString('Knopf mit Variable', $this->logText());
    }

    public function testNichtGenehmigteVorlageVerschicktNichts(): void
    {
        $this->feld($this->vorlage('nummer_geaendert', 'Hallo.', 'REJECTED'));

        $status = (new NummernwechselHinweisSender())->sende($this->personId, self::ALTE_NUMMER);

        $this->assertSame(NummernwechselHinweisSender::STATUS_FAILED, $status);
        $this->assertSame([], $this->meta->calls);
        $this->assertStringContainsString('nicht genehmigt', $this->logText());
        $this->assertStringContainsString('Mitarbeiterportal', $this->logText());
    }

    public function testAlteGleichNeueNummerBleibtVorDerVorlage(): void
    {
        $this->feld(null);

        $status = (new NummernwechselHinweisSender())->sende($this->personId, self::NEUE_NUMMER);

        $this->assertSame(NummernwechselHinweisSender::STATUS_FAILED, $status);
        $this->assertStringContainsString('dieselbe', $this->logText());
        $this->assertStringNotContainsString('Mitarbeiterportal', $this->logText());
    }

    private function logText(): string
    {
        return implode("\n", array_map(
            fn (array $z) => $z['nachricht'] . ' ' . json_encode($z['daten'], JSON_UNESCAPED_UNICODE),
            $this->log->zeilen,
        ));
    }

    private function vorlage(string $name, string $rumpf, string $status = 'APPROVED', array $knoepfe = []): int
    {
        $teile = [['type' => 'BODY', 'text' => $rumpf]];
        if ($knoepfe !== []) {
            $teile[] = ['type' => 'BUTTONS', 'buttons' => $knoepfe];
        }

        return (int) DB::table('integrations_whatsapp_templates')->insertGetId([
            'uuid' => 'tpl-' . uniqid(), 'external_id' => 'ext-' . uniqid(), 'name' => $name, 'language' => 'de',
            'status' => $status, 'category' => 'UTILITY', 'components' => json_encode($teile),
            'whatsapp_account_id' => $this->kontoId, 'user_id' => 1,
            'created_at' => self::ANGEFASST, 'updated_at' => self::ANGEFASST,
        ]);
    }

    private function feld(?int $vorlageId): void
    {
        DB::table('rec_applicant_settings')->where('team_id', self::TEAM)->delete();
        DB::table('rec_applicant_settings')->insert([
            'team_id'  => self::TEAM,
            'settings' => json_encode(['auto_pilot_wa_account_id' => $this->kontoId, PortalVorlagen::HINWEIS_ALTE_NUMMER => $vorlageId]),
        ]);
    }

    private function echteMigrationen(): void
    {
        $eigen        = dirname(__DIR__, 2);
        $crm          = dirname((new \ReflectionClass(CommsChannel::class))->getFileName(), 3);
        $integrations = dirname((new \ReflectionClass(IntegrationsWhatsAppTemplate::class))->getFileName(), 3);

        foreach ([
            [$eigen, 'database/migrations/2026_02_09_000008_create_rec_applicant_settings_table.php'],
            [$crm, 'database/migrations/2026_01_14_000003_create_comms_channels_table.php'],
            [$integrations, 'database/migrations/2026_01_17_150000_create_integrations_whatsapp_accounts_table.php'],
            [$integrations, 'database/migrations/2026_02_12_000001_create_integrations_whatsapp_templates_table.php'],
        ] as [$wurzel, $relativ]) {
            (require $wurzel . '/' . $relativ)->up();
        }
    }
}
