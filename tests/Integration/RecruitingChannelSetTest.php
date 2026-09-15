<?php

namespace Platform\Recruiting\Tests\Integration;

use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use Platform\Crm\Models\CommsChannel;
use Platform\Integrations\Models\IntegrationsWhatsAppTemplate;
use Platform\Recruiting\Models\RecApplicantSettings;
use Platform\Recruiting\Services\Comms\RecruitingChannelResolver;

/**
 * Task 3: Die neue Kommunikation waehlt ihre Threads ueber das KANAL-SET aus,
 * nicht ueber den Thread-Kontext (Spec: Lueckenlosigkeit, Fall 2474). Dieser
 * Test haelt fest, was zum Set gehoert: alle aktiven WhatsApp-Kanaele des in
 * den Einstellungen gewaehlten Kontos — keine fremden, keine inaktiven.
 * Aufbau nach dem Muster von DispoConversationChannelSetTest.
 */
class RecruitingChannelSetTest extends TestCase
{
    private const TEAM = 702;

    private static int $accountId = 0;
    private static int $c1 = 0;
    private static int $c2 = 0;
    private static int $fremd = 0;
    private static int $inaktiv = 0;

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

        self::runMigrations();
        self::seedFixtures();
    }

    public static function tearDownAfterClass(): void
    {
        Facade::clearResolvedInstances();
    }

    public function test_set_enthaelt_alle_aktiven_kanaele_des_kontos(): void
    {
        $ids = RecruitingChannelResolver::channelIds(self::TEAM);
        sort($ids);

        $expected = [self::$c1, self::$c2];
        sort($expected);

        $this->assertSame($expected, $ids);
        $this->assertNotContains(self::$fremd, $ids);
        $this->assertNotContains(self::$inaktiv, $ids);
    }

    public function test_ohne_konfiguriertes_konto_ist_das_set_leer(): void
    {
        $this->assertSame([], RecruitingChannelResolver::channelIds(999));
        $this->assertFalse(RecruitingChannelResolver::isConfigured(999));
        $this->assertTrue(RecruitingChannelResolver::isConfigured(self::TEAM));
    }

    private static function seedFixtures(): void
    {
        self::$accountId = (int) Capsule::table('integrations_whatsapp_accounts')->insertGetId([
            'uuid' => 'acc-recruiting-waba',
            'phone_number' => '+49 160 5552001',
            'title' => 'Recruiting-WABA',
            'active' => true,
            'user_id' => 1,
        ]);

        self::$c1 = self::createChannel('+49 160 5552001', self::$accountId, true);
        self::$c2 = self::createChannel('+49 160 5552002', self::$accountId, true);
        self::$fremd = self::createChannel('+49 160 5559999', 999999, true);
        self::$inaktiv = self::createChannel('+49 160 5552003', self::$accountId, false);

        RecApplicantSettings::create([
            'team_id' => self::TEAM,
            'settings' => ['auto_pilot_wa_account_id' => self::$accountId],
        ]);
    }

    private static function createChannel(string $sender, int $accountId, bool $active): int
    {
        return (int) Capsule::table('comms_channels')->insertGetId([
            'team_id' => self::TEAM,
            'type' => 'whatsapp',
            'provider' => 'whatsapp_meta',
            'sender_identifier' => $sender,
            'is_active' => $active,
            'meta' => json_encode(['integrations_whatsapp_account_id' => $accountId]),
        ]);
    }

    private static function runMigrations(): void
    {
        $own = dirname(__DIR__, 2);
        $crm = self::packageRootOf(CommsChannel::class);
        $integrations = self::packageRootOf(IntegrationsWhatsAppTemplate::class);

        $files = [
            [$own, 'database/migrations/2026_02_09_000008_create_rec_applicant_settings_table.php'],
            [$crm, 'database/migrations/2026_01_14_000003_create_comms_channels_table.php'],
            [$integrations, 'database/migrations/2026_01_17_150000_create_integrations_whatsapp_accounts_table.php'],
        ];

        foreach ($files as [$root, $relative]) {
            $migration = require $root . '/' . $relative;
            $migration->up();
        }
    }

    private static function packageRootOf(string $class): string
    {
        $file = (new \ReflectionClass($class))->getFileName();

        return dirname($file, 3);
    }
}
