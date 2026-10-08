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
use Platform\Recruiting\Models\RecEmployee;
use Platform\Recruiting\Services\Comms\DokumentHinweisSender;
use Platform\Recruiting\Services\Comms\HoldingTemplateSender;

/**
 * Der Hinweis "im Portal liegt ein Dokument" — Muster AufgabenSenderTest und
 * HoldingTemplateSenderResolveTargetTest: echte Tabellen fuer Einstellungen,
 * Konto, Kanal und Vorlage; Meta als Attrappe; Log als Attrappe.
 */
final class DokumentHinweisSenderTest extends TestCase
{
    private const TEAM = 3;
    private const NUMMER = '+4915111111111';
    private const TOKEN = 'portal-token-dok';
    private const VORLAGE = 'dokument_hinweis';
    private const ANGEFASST = '2026-10-09 09:00:00';

    private Capsule $capsule;
    private object $meta;
    private int $kontoId;
    private int $vorlageId;

    protected function setUp(): void
    {
        parent::setUp();
        $container = Container::getInstance();
        Container::setInstance($container);
        if (!class_exists('Log')) {
            class_alias(\Illuminate\Support\Facades\Log::class, 'Log');
        }
        $container->instance('log', new class { public function __call($m, $a) {} });
        $container->instance('config', new ConfigRepository(['recruiting' => ['zas' => ['company_prefix' => 'RG']]]));
        $this->meta = $this->metaAttrappe();
        $container->instance(WhatsAppMetaService::class, $this->meta);
        // Der Aufloeser verlangt im Konstruktor einen echten WhatsAppMetaService; die Attrappe darf nur sendTemplate() ersetzen.
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

        $this->capsule->schema()->create('rec_employees', function ($t) {
            $t->increments('id');
            $t->integer('team_id')->nullable();
            $t->string('first_name')->nullable();
            $t->string('last_name')->nullable();
            $t->string('phone')->nullable();
            $t->string('portal_token', 64)->nullable();
            $t->boolean('is_active')->nullable();
            $t->timestamp('portal_v2_since')->nullable();
            $t->timestamps();
        });
        $this->echteMigrationen();
        $this->kontoUndKanal();
        $this->vorlageId = $this->vorlageAnlegen();
        $this->einstellungen([DokumentHinweisSender::SETTINGS_KEY => $this->vorlageId]);
    }

    protected function tearDown(): void
    {
        Model::unsetConnectionResolver();
        Model::clearBootedModels();
        $c = Container::getInstance();
        foreach (['log', 'config', 'db', 'db.schema', WhatsAppMetaService::class, HoldingTemplateSender::class] as $n) {
            $c->forgetInstance($n);
        }
        Facade::clearResolvedInstances();
        parent::tearDown();
    }

    public function test_erfolg_schickt_vorname_und_token_knopf(): void
    {
        $ma = $this->mitarbeiter();

        $status = (new DokumentHinweisSender())->sende($ma);

        $this->assertSame(DokumentHinweisSender::STATUS_SENT, $status);
        $this->assertCount(1, $this->meta->calls);
        $call = $this->meta->calls[0];
        $this->assertSame(self::NUMMER, $call['to']);
        $this->assertSame(self::VORLAGE, $call['templateName']);
        $this->assertSame('de', $call['languageCode']);
        $this->assertSame([['type' => 'text', 'parameter_name' => 'vorname', 'text' => 'Gregor']], $call['components'][0]['parameters']);
        $this->assertSame('button', $call['components'][1]['type']);
        $this->assertSame(self::TOKEN, $call['components'][1]['parameters'][0]['text']);
    }

    public function test_altes_portal_bekommt_keine_nachricht(): void
    {
        $ma = $this->mitarbeiter(['portal_v2_since' => null]);

        $this->assertSame(DokumentHinweisSender::STATUS_ALTES_PORTAL, (new DokumentHinweisSender())->sende($ma));
        $this->assertCount(0, $this->meta->calls);
    }

    public function test_ohne_nummer_kein_versand(): void
    {
        $ma = $this->mitarbeiter(['phone' => 'keine']);

        $this->assertSame(DokumentHinweisSender::STATUS_NO_PHONE, (new DokumentHinweisSender())->sende($ma));
        $this->assertCount(0, $this->meta->calls);
    }

    public function test_ohne_einstellung_nicht_konfiguriert(): void
    {
        $this->einstellungen([]);

        $this->assertSame(DokumentHinweisSender::STATUS_NICHT_KONFIGURIERT, (new DokumentHinweisSender())->sende($this->mitarbeiter()));
        $this->assertCount(0, $this->meta->calls);
    }

    public function test_nicht_genehmigte_vorlage_ist_nicht_konfiguriert(): void
    {
        DB::table('integrations_whatsapp_templates')->where('id', $this->vorlageId)->update(['status' => 'PENDING']);

        $this->assertSame(DokumentHinweisSender::STATUS_NICHT_KONFIGURIERT, (new DokumentHinweisSender())->sende($this->mitarbeiter()));
    }

    public function test_vorlage_ohne_url_knopf_ist_untauglich(): void
    {
        DB::table('integrations_whatsapp_templates')->where('id', $this->vorlageId)->update([
            'components' => json_encode([['type' => 'BODY', 'text' => 'Hallo {{vorname}}']]),
        ]);

        $this->assertSame(DokumentHinweisSender::STATUS_VORLAGE_UNTAUGLICH, (new DokumentHinweisSender())->sende($this->mitarbeiter()));
        $this->assertCount(0, $this->meta->calls);
    }

    public function test_unbekannter_platzhalter_wird_nicht_mit_vorname_befuellt(): void
    {
        DB::table('integrations_whatsapp_templates')->where('id', $this->vorlageId)->update([
            'components' => json_encode([
                ['type' => 'BODY', 'text' => 'Hallo {{vorname}}, {{titel}} wartet.'],
                ['type' => 'BUTTONS', 'buttons' => [['type' => 'URL', 'text' => 'Zum Portal', 'url' => 'https://meingedeck.de/recruiting/mitarbeiter/neu/{{1}}']]],
            ]),
        ]);

        $this->assertSame(DokumentHinweisSender::STATUS_VORLAGE_UNTAUGLICH, (new DokumentHinweisSender())->sende($this->mitarbeiter()));
        $this->assertCount(0, $this->meta->calls);
    }

    public function test_meta_ablehnung_ist_failed_nicht_sent(): void
    {
        $this->meta->lehntAb();

        $this->assertSame(DokumentHinweisSender::STATUS_FAILED, (new DokumentHinweisSender())->sende($this->mitarbeiter()));
    }

    public function test_meta_ausnahme_wird_gefangen(): void
    {
        $this->meta->wirft(new \RuntimeException('Meta down'));

        $this->assertSame(DokumentHinweisSender::STATUS_FAILED, (new DokumentHinweisSender())->sende($this->mitarbeiter()));
    }

    private function mitarbeiter(array $set = []): RecEmployee
    {
        $id = (int) DB::table('rec_employees')->insertGetId(array_merge([
            'team_id' => self::TEAM, 'first_name' => 'Gregor', 'last_name' => 'Erste',
            'phone' => self::NUMMER, 'portal_token' => self::TOKEN, 'is_active' => 1,
            'portal_v2_since' => self::ANGEFASST,
            'created_at' => self::ANGEFASST, 'updated_at' => self::ANGEFASST,
        ], $set));

        return RecEmployee::find($id);
    }

    private function metaAttrappe(): object
    {
        return new class(self::VORLAGE) {
            public array $calls = [];
            private bool $lehntAb = false;
            private ?\Throwable $wirft = null;
            public function __construct(private string $vorlage) {}
            public function lehntAb(): void { $this->lehntAb = true; }
            public function wirft(\Throwable $e): void { $this->wirft = $e; }
            public function sendTemplate($channel, string $to, string $templateName, array $components = [], string $languageCode = 'de', $sender = null, bool $isAutoReply = false)
            {
                $this->calls[] = compact('channel', 'to', 'templateName', 'components', 'languageCode');
                if ($this->wirft !== null) {
                    throw $this->wirft;
                }
                if ($this->lehntAb || $templateName !== $this->vorlage) {
                    return new class { public string $status = 'failed'; public array $meta_payload = ['error' => ['message' => 'abgelehnt']]; };
                }
                return new class { public int $id = 4712; public string $status = 'sent'; public array $meta_payload = []; };
            }
        };
    }

    private function echteMigrationen(): void
    {
        $eigen = dirname(__DIR__, 2);
        $crm = $this->paketWurzel(CommsChannel::class);
        $integrations = $this->paketWurzel(IntegrationsWhatsAppTemplate::class);
        foreach ([
            [$eigen, 'database/migrations/2026_02_09_000008_create_rec_applicant_settings_table.php'],
            [$crm, 'database/migrations/2026_01_14_000003_create_comms_channels_table.php'],
            [$integrations, 'database/migrations/2026_01_17_150000_create_integrations_whatsapp_accounts_table.php'],
            [$integrations, 'database/migrations/2026_02_12_000001_create_integrations_whatsapp_templates_table.php'],
        ] as [$wurzel, $relativ]) {
            (require $wurzel . '/' . $relativ)->up();
        }
    }

    private function paketWurzel(string $klasse): string
    {
        return dirname((new \ReflectionClass($klasse))->getFileName(), 3);
    }

    private function kontoUndKanal(): void
    {
        $this->kontoId = (int) DB::table('integrations_whatsapp_accounts')->insertGetId([
            'uuid' => 'acc-dok', 'phone_number' => '+49 160 5552002', 'title' => 'Recruiting-WABA', 'active' => true, 'user_id' => 1,
        ]);
        DB::table('comms_channels')->insert([
            'team_id' => self::TEAM, 'name' => 'Recruiting WhatsApp', 'type' => 'whatsapp', 'provider' => 'whatsapp_meta',
            'sender_identifier' => '+49 160 5552002', 'is_active' => true,
            'meta' => json_encode(['integrations_whatsapp_account_id' => $this->kontoId]),
            'created_at' => self::ANGEFASST, 'updated_at' => self::ANGEFASST,
        ]);
    }

    /** Team-Einstellungen wie das Einstellungs-Fenster sie schreibt; auto_pilot_wa_account_id ist der Kanal-Anker. */
    private function einstellungen(array $weitere): void
    {
        DB::table('rec_applicant_settings')->where('team_id', self::TEAM)->delete();
        DB::table('rec_applicant_settings')->insert([
            'team_id'  => self::TEAM,
            'settings' => json_encode(array_merge(['auto_pilot_wa_account_id' => $this->kontoId], $weitere)),
        ]);
    }

    private function vorlageAnlegen(): int
    {
        return (int) DB::table('integrations_whatsapp_templates')->insertGetId([
            'uuid' => 'tpl-dok', 'external_id' => 'ext-dok', 'name' => self::VORLAGE, 'language' => 'de',
            'status' => 'APPROVED', 'category' => 'UTILITY',
            'components' => json_encode([
                ['type' => 'BODY', 'text' => 'Hallo {{vorname}}, im Portal liegt etwas für dich.'],
                ['type' => 'BUTTONS', 'buttons' => [['type' => 'URL', 'text' => 'Zum Portal', 'url' => 'https://meingedeck.de/recruiting/mitarbeiter/neu/{{1}}']]],
            ]),
            'whatsapp_account_id' => $this->kontoId, 'user_id' => 1,
            'created_at' => self::ANGEFASST, 'updated_at' => self::ANGEFASST,
        ]);
    }
}
