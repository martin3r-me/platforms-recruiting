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
use Platform\Recruiting\Models\RecApplicantSettings;
use Platform\Recruiting\Models\RecEmployee;
use Platform\Recruiting\Services\Comms\DokumentHinweisSender;
use Platform\Recruiting\Services\Comms\HoldingTemplateSender;
use Platform\Recruiting\Services\Comms\VertragHinweisSender;

/**
 * Spec Vertrag aus der Akte §2.4 — Muster DokumentHinweisSenderTest: echte
 * Tabellen fuer Einstellungen, Konto, Kanal und Vorlagen; Meta als Attrappe.
 */
final class VertragHinweisSenderTest extends TestCase
{
    private const TEAM = 3;
    private const NUMMER = '+4915111111111';
    private const TOKEN = 'portal-token-vertrag';
    private const ANGEFASST = '2026-10-09 09:00:00';

    private Capsule $capsule;
    private object $meta;
    private int $kontoId;
    private int $dokumentVorlage;
    private int $vertragVorlage;

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
        $this->dokumentVorlage = $this->vorlageAnlegen('dokument_hinweis');
        $this->vertragVorlage = $this->vorlageAnlegen('vertrag_hinweis');
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

    public function test_eigene_vertragsvorlage_gewinnt(): void
    {
        $this->einstellungen([
            DokumentHinweisSender::SETTINGS_KEY => $this->dokumentVorlage,
            VertragHinweisSender::SETTINGS_KEY  => $this->vertragVorlage,
        ]);

        $this->assertSame(DokumentHinweisSender::STATUS_SENT, (new VertragHinweisSender())->sende($this->mitarbeiter()));
        $this->assertSame('vertrag_hinweis', $this->meta->calls[0]['templateName']);
        $this->assertSame(self::TOKEN, $this->meta->calls[0]['components'][1]['parameters'][0]['text']);
    }

    /** Probe: Rueckfall in vorlagenSchluessel() entfernen → nicht_konfiguriert, rot. */
    public function test_ohne_eigene_vorlage_faellt_er_auf_die_dokumentvorlage_zurueck(): void
    {
        $this->einstellungen([DokumentHinweisSender::SETTINGS_KEY => $this->dokumentVorlage]);

        $this->assertSame(DokumentHinweisSender::STATUS_SENT, (new VertragHinweisSender())->sende($this->mitarbeiter()));
        $this->assertSame('dokument_hinweis', $this->meta->calls[0]['templateName']);
    }

    public function test_ohne_jede_vorlage_nicht_konfiguriert(): void
    {
        $this->einstellungen([]);

        $this->assertSame(DokumentHinweisSender::STATUS_NICHT_KONFIGURIERT, (new VertragHinweisSender())->sende($this->mitarbeiter()));
        $this->assertCount(0, $this->meta->calls);
    }

    public function test_meta_ablehnung_ist_failed(): void
    {
        $this->einstellungen([VertragHinweisSender::SETTINGS_KEY => $this->vertragVorlage]);
        $this->meta->lehntAb();

        $status = (new VertragHinweisSender())->sende($this->mitarbeiter());

        $this->assertSame(DokumentHinweisSender::STATUS_FAILED, $status);
        $this->assertFalse(DokumentHinweisSender::istErfolg($status));
    }

    public function test_dokument_sender_bleibt_bei_seinem_schluessel(): void
    {
        $this->einstellungen([VertragHinweisSender::SETTINGS_KEY => $this->vertragVorlage]);

        $this->assertSame(DokumentHinweisSender::STATUS_NICHT_KONFIGURIERT, (new DokumentHinweisSender())->sende($this->mitarbeiter()),
            'Der Rueckfall gilt nur in eine Richtung: der Dokument-Hinweis nimmt nie die Vertragsvorlage');
    }

    public function test_einstellung_hat_default_und_steht_im_fenster(): void
    {
        $this->assertArrayHasKey(VertragHinweisSender::SETTINGS_KEY, RecApplicantSettings::DEFAULT_SETTINGS);
        $this->assertNull(RecApplicantSettings::DEFAULT_SETTINGS[VertragHinweisSender::SETTINGS_KEY]);

        $blade = (string) file_get_contents(dirname(__DIR__, 2) . '/resources/views/livewire/applicant/applicant-settings-modal.blade.php');
        $select = strpos($blade, 'name="settings.employee_contract_wa_template_id"');
        $anker = strpos($blade, 'wire:model.live="settings.default_contact_user_id"');
        $this->assertIsInt($select);
        $this->assertIsInt($anker);
        $this->assertGreaterThan($anker, $select, 'Hausregel: Neues kommt unter das Ansprechpartner-Select');
        $this->assertStringContainsString('Vertrag zur Unterschrift — WhatsApp-Template mit Portal-Link', $blade);
    }

    private function mitarbeiter(): RecEmployee
    {
        $id = (int) DB::table('rec_employees')->insertGetId([
            'team_id' => self::TEAM, 'first_name' => 'Gregor', 'last_name' => 'Erste',
            'phone' => self::NUMMER, 'portal_token' => self::TOKEN, 'is_active' => 1,
            'portal_v2_since' => self::ANGEFASST, 'created_at' => self::ANGEFASST, 'updated_at' => self::ANGEFASST,
        ]);

        return RecEmployee::find($id);
    }

    private function metaAttrappe(): object
    {
        return new class {
            public array $calls = [];
            private bool $lehntAb = false;
            public function lehntAb(): void { $this->lehntAb = true; }
            public function sendTemplate($channel, string $to, string $templateName, array $components = [], string $languageCode = 'de', $sender = null, bool $isAutoReply = false)
            {
                $this->calls[] = compact('channel', 'to', 'templateName', 'components', 'languageCode');
                if ($this->lehntAb) {
                    return new class { public string $status = 'failed'; public array $meta_payload = ['error' => ['message' => 'abgelehnt']]; };
                }
                return new class { public int $id = 4713; public string $status = 'sent'; public array $meta_payload = []; };
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
            'uuid' => 'acc-vertrag', 'phone_number' => '+49 160 5552002', 'title' => 'Recruiting-WABA', 'active' => true, 'user_id' => 1,
        ]);
        DB::table('comms_channels')->insert([
            'team_id' => self::TEAM, 'name' => 'Recruiting WhatsApp', 'type' => 'whatsapp', 'provider' => 'whatsapp_meta',
            'sender_identifier' => '+49 160 5552002', 'is_active' => true,
            'meta' => json_encode(['integrations_whatsapp_account_id' => $this->kontoId]),
            'created_at' => self::ANGEFASST, 'updated_at' => self::ANGEFASST,
        ]);
    }

    private function einstellungen(array $weitere): void
    {
        DB::table('rec_applicant_settings')->where('team_id', self::TEAM)->delete();
        DB::table('rec_applicant_settings')->insert([
            'team_id'  => self::TEAM,
            'settings' => json_encode(array_merge(['auto_pilot_wa_account_id' => $this->kontoId], $weitere)),
        ]);
    }

    private function vorlageAnlegen(string $name): int
    {
        return (int) DB::table('integrations_whatsapp_templates')->insertGetId([
            'uuid' => 'tpl-' . $name, 'external_id' => 'ext-' . $name, 'name' => $name, 'language' => 'de',
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
