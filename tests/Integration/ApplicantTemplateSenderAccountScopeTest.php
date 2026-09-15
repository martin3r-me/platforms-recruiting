<?php

namespace Platform\Recruiting\Tests\Integration;

use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use Platform\Crm\Models\CommsChannel;
use Platform\Crm\Models\CommsWhatsAppThread;
use Platform\Crm\Services\Comms\WhatsAppMetaService;
use Platform\Integrations\Models\IntegrationsWhatsAppTemplate;
use Platform\Recruiting\Models\RecApplicantSettings;
use Platform\Recruiting\Services\Comms\ApplicantTemplateSender;

/**
 * Abschluss-Durchsicht, Befund 6 (letzter Punkt): die Knopfleiste
 * (Inbox::chatTemplates()) zeigt nur Vorlagen des im Team konfigurierten
 * WABA-Kontos (auto_pilot_wa_account_id) an — ApplicantTemplateSender::send()
 * pruefte das bisher NICHT, obwohl ein direkter Aufruf mit der ID einer
 * Vorlage eines FREMDEN Kontos moeglich ist (Livewire-Methoden sind mit
 * beliebigen Parametern aufrufbar, nicht nur mit dem, was gerendert wurde).
 *
 * Muster fuer die WhatsAppMetaService-Attrappe: DispoChatTemplateSenderTest.
 */
class ApplicantTemplateSenderAccountScopeTest extends TestCase
{
    private const TEAM = 892;
    private const TEAM_OHNE_KONTO = 893;

    private static int $channelId = 0;
    private static int $channelIdOhneKonto = 0;

    /** @var object{calls:int} */
    private object $stub;

    public static function setUpBeforeClass(): void
    {
        $container = Container::getInstance();
        $container->instance('config', new ConfigRepository([
            'activity-log' => ['events' => []],
        ]));

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

        self::runMigrations();

        self::$channelId = (int) Capsule::table('comms_channels')->insertGetId([
            'team_id' => self::TEAM, 'type' => 'whatsapp', 'provider' => 'whatsapp_meta',
            'sender_identifier' => '+49 160 5559100', 'is_active' => true,
            'meta' => json_encode([]),
        ]);
        RecApplicantSettings::create([
            'team_id' => self::TEAM,
            'settings' => ['auto_pilot_wa_account_id' => 501],
        ]);

        self::$channelIdOhneKonto = (int) Capsule::table('comms_channels')->insertGetId([
            'team_id' => self::TEAM_OHNE_KONTO, 'type' => 'whatsapp', 'provider' => 'whatsapp_meta',
            'sender_identifier' => '+49 160 5559200', 'is_active' => true,
            'meta' => json_encode([]),
        ]);
        // Bewusst KEINE RecApplicantSettings fuer TEAM_OHNE_KONTO — Rueckfall-Fall.
    }

    public static function tearDownAfterClass(): void
    {
        Container::getInstance()->forgetInstance('config');
        Container::getInstance()->forgetInstance(WhatsAppMetaService::class);
        Facade::clearResolvedInstances();
    }

    protected function setUp(): void
    {
        Capsule::table('integrations_whatsapp_templates')->delete();
        Capsule::table('comms_whatsapp_threads')->delete();

        $this->stub = new class {
            public int $calls = 0;

            public function sendTemplate($channel, string $to, string $templateName, array $components = [], string $languageCode = 'de', $sender = null): object
            {
                $this->calls++;

                return (object) ['id' => 9000 + $this->calls, 'status' => 'sent'];
            }
        };
        Container::getInstance()->instance(WhatsAppMetaService::class, $this->stub);
    }

    public function test_vorlage_eines_fremden_kontos_wird_abgelehnt_und_nicht_gesendet(): void
    {
        $templateId = $this->template(999); // fremdes Konto, TEAM hat 501 konfiguriert
        $thread = $this->thread(self::TEAM, self::$channelId);

        $result = (new ApplicantTemplateSender())->send($thread, $templateId, null, null);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('gehört nicht zum WhatsApp-Konto', (string) $result['error']);
        $this->assertSame(0, $this->stub->calls, 'Bei abgelehnter Vorlage darf kein Sendeversuch stattfinden.');
    }

    public function test_vorlage_des_eigenen_kontos_wird_gesendet(): void
    {
        $templateId = $this->template(501); // passt zum konfigurierten Konto des Teams
        $thread = $this->thread(self::TEAM, self::$channelId);

        $result = (new ApplicantTemplateSender())->send($thread, $templateId, null, null);

        $this->assertTrue($result['ok'], (string) $result['error']);
        $this->assertSame(1, $this->stub->calls);
    }

    public function test_ohne_konfiguriertes_konto_gilt_der_rueckfall_ohne_einschraenkung(): void
    {
        $templateId = $this->template(777); // irgendein Konto — Team hat gar keins konfiguriert
        $thread = $this->thread(self::TEAM_OHNE_KONTO, self::$channelIdOhneKonto);

        $result = (new ApplicantTemplateSender())->send($thread, $templateId, null, null);

        $this->assertTrue($result['ok'], (string) $result['error']);
        $this->assertSame(1, $this->stub->calls);
    }

    private function template(int $whatsappAccountId): int
    {
        return (int) Capsule::table('integrations_whatsapp_templates')->insertGetId([
            'uuid' => 'tpl-' . bin2hex(random_bytes(6)), 'external_id' => 'ext-' . bin2hex(random_bytes(6)),
            'whatsapp_account_id' => $whatsappAccountId, 'user_id' => 1,
            'name' => 'einfache_vorlage', 'language' => 'de', 'status' => 'APPROVED',
            'category' => 'UTILITY',
            'components' => json_encode([['type' => 'BODY', 'text' => 'Hallo, wir melden uns.']]),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function thread(int $teamId, int $channelId): CommsWhatsAppThread
    {
        $id = (int) Capsule::table('comms_whatsapp_threads')->insertGetId([
            'team_id' => $teamId, 'token' => 'tok-' . bin2hex(random_bytes(6)), 'comms_channel_id' => $channelId,
            'remote_phone_number' => '+4917612340000', 'is_unread' => false,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return CommsWhatsAppThread::findOrFail($id);
    }

    private static function runMigrations(): void
    {
        $own = dirname(__DIR__, 2);
        $crm = self::packageRootOf(CommsChannel::class);
        $integrations = self::packageRootOf(IntegrationsWhatsAppTemplate::class);

        $files = [
            [$own, 'database/migrations/2026_02_09_000008_create_rec_applicant_settings_table.php'],
            [$crm, 'database/migrations/2026_01_14_000003_create_comms_channels_table.php'],
            [$crm, 'database/migrations/2026_02_12_100001_create_comms_whatsapp_threads_table.php'],
            [$integrations, 'database/migrations/2026_01_17_150000_create_integrations_whatsapp_accounts_table.php'],
            [$integrations, 'database/migrations/2026_02_12_000001_create_integrations_whatsapp_templates_table.php'],
        ];

        foreach ($files as [$root, $relative]) {
            $path = $root . '/' . $relative;
            if (!file_exists($path)) {
                throw new \RuntimeException("Migration fehlt: {$path}");
            }
            $migration = require $path;
            $migration->up();
        }
    }

    private static function packageRootOf(string $class): string
    {
        return dirname((new \ReflectionClass($class))->getFileName(), 3);
    }
}
