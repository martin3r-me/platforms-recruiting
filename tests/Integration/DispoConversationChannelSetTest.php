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
use Platform\Integrations\Models\IntegrationsWhatsAppTemplate;
use Platform\Recruiting\Models\RecApplicantSettings;
use Platform\Recruiting\Services\Zas\Dispo\DispoChannelResolver;
use Platform\Recruiting\Services\Zas\Dispo\DispoUnreadCounter;

/**
 * Task 1 (Dispo-Kommunikation Multi-Kanal): dispoChannelIds() muss ALLE
 * aktiven WhatsApp-Kanaele des Dispo-WABA-Accounts liefern (nicht nur den
 * einen Default-Kanal, der zufaellig am Bestaetigungs-Template haengt) —
 * eine WABA-Nummer kann mehrere phone_number_ids/Kanaele haben. Der
 * Sidebar-Zaehler (DispoUnreadCounter) muss ueber genau dieses Set zaehlen.
 * Aufbau/Migrations-Muster wie DispoConfirmationSenderChannelTest.
 */
class DispoConversationChannelSetTest extends TestCase
{
    private const TEAM = 602;
    private const DISPO_ACCOUNT_UUID = 'acc-dispo-waba';
    private const DISPO_ACCOUNT_NUMMER = '+49 160 5551001';

    private static int $dispoAccountId = 0;
    private static int $c1 = 0;
    private static int $c2 = 0;
    private static int $c3 = 0;
    private static int $fremdChannelId = 0;
    private static int $inaktivChannelId = 0;
    private static int $templateId = 0;

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
            'recruiting' => ['zas' => ['inbound_team_id' => self::TEAM]],
        ]));

        self::runMigrations();
        self::seedFixtures();
    }

    public static function tearDownAfterClass(): void
    {
        Container::getInstance()->forgetInstance('config');
        Facade::clearResolvedInstances();
    }

    public function test_dispo_channel_ids_returns_only_active_channels_of_dispo_account(): void
    {
        $ids = DispoChannelResolver::dispoChannelIds();
        sort($ids);

        $expected = [self::$c1, self::$c2, self::$c3];
        sort($expected);

        $this->assertSame($expected, $ids);
        $this->assertNotContains(self::$fremdChannelId, $ids);
        $this->assertNotContains(self::$inaktivChannelId, $ids);
    }

    public function test_unread_counter_sums_across_the_whole_channel_set(): void
    {
        // Cache aus vorherigem Test (falls Facade-Cache gebunden) nicht relevant,
        // da kein Cache-Treiber in diesem Bootstrap gebunden ist -> Cache::remember
        // wuerde ohne 'cache'-Binding werfen; DispoUnreadCounter faengt das ab
        // ("wirft nie"), faellt aber dann auf 0 zurueck. Daher binden wir hier
        // einen einfachen Array-Cache-Stub.
        $container = Container::getInstance();
        $container->instance('cache', new class {
            private array $store = [];

            public function remember(string $key, $ttl, \Closure $callback)
            {
                if (!array_key_exists($key, $this->store)) {
                    $this->store[$key] = $callback();
                }
                return $this->store[$key];
            }
        });

        $this->assertSame(3, DispoUnreadCounter::count());

        Container::getInstance()->forgetInstance('cache');
    }

    private static function seedFixtures(): void
    {
        self::$dispoAccountId = (int) Capsule::table('integrations_whatsapp_accounts')->insertGetId([
            'uuid'         => self::DISPO_ACCOUNT_UUID,
            'phone_number' => self::DISPO_ACCOUNT_NUMMER,
            'title'        => 'Dispo-WABA-Account',
            'active'       => true,
            'user_id'      => 1,
        ]);

        // 3 aktive Kanaele desselben Dispo-WABA-Accounts (unterschiedliche phone_number_id).
        self::$c1 = self::createChannel('+49 160 5551001', 'pnid-1', self::$dispoAccountId);
        self::$c2 = self::createChannel('+49 160 5551002', 'pnid-2', self::$dispoAccountId);
        self::$c3 = self::createChannel('+49 160 5551003', 'pnid-3', self::$dispoAccountId);

        // Fremder Kanal: anderer WhatsApp-Account.
        self::$fremdChannelId = self::createChannel('+49 160 5559999', 'pnid-fremd', 999999);

        // Inaktiver Kanal desselben Dispo-Accounts -> darf nicht mitgezaehlt werden.
        self::$inaktivChannelId = (int) Capsule::table('comms_channels')->insertGetId([
            'team_id'           => self::TEAM,
            'type'              => 'whatsapp',
            'provider'          => 'whatsapp_meta',
            'sender_identifier' => '+49 160 5551004',
            'is_active'         => false,
            'meta'              => json_encode(['integrations_whatsapp_account_id' => self::$dispoAccountId, 'phone_number_id' => 'pnid-4']),
        ]);

        self::$templateId = (int) IntegrationsWhatsAppTemplate::create([
            'external_id' => 'ext-conv-set-1', 'name' => 'dispo_einsatz_bestaetigung', 'language' => 'de', 'status' => 'APPROVED',
            'components' => [['type' => 'BODY', 'text' => 'Bestaetigung {{1}}']], 'whatsapp_account_id' => self::$dispoAccountId, 'user_id' => 1,
        ])->id;

        RecApplicantSettings::create([
            'team_id' => self::TEAM,
            'settings' => ['dispo_confirmation_template_id' => self::$templateId],
        ]);

        // Unread/read-Threads ueber das Kanal-Set:
        // 2 unread auf c1, 1 unread auf c3, 1 read auf c2 -> Summe 3.
        self::createThread(self::$c1, '+49 151 61000001', true);
        self::createThread(self::$c1, '+49 151 61000002', true);
        self::createThread(self::$c2, '+49 151 61000003', false);
        self::createThread(self::$c3, '+49 151 61000004', true);
    }

    private static function createChannel(string $senderIdentifier, string $phoneNumberId, int $accountId): int
    {
        return (int) Capsule::table('comms_channels')->insertGetId([
            'team_id'           => self::TEAM,
            'type'              => 'whatsapp',
            'provider'          => 'whatsapp_meta',
            'sender_identifier' => $senderIdentifier,
            'is_active'         => true,
            'meta'              => json_encode(['integrations_whatsapp_account_id' => $accountId, 'phone_number_id' => $phoneNumberId]),
        ]);
    }

    private static function createThread(int $channelId, string $remotePhone, bool $isUnread): void
    {
        CommsWhatsAppThread::create([
            'team_id'              => self::TEAM,
            'comms_channel_id'     => $channelId,
            'token'                => bin2hex(random_bytes(8)),
            'remote_phone_number'  => $remotePhone,
            'is_unread'            => $isUnread,
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
            [$crm, 'database/migrations/2026_02_12_100001_create_comms_whatsapp_threads_table.php'],
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

    /** Wurzel des Composer-Pakets einer geladenen Klasse (Modulmuster). */
    private static function packageRootOf(string $class): string
    {
        $file = (new \ReflectionClass($class))->getFileName();
        $dir = dirname((string) $file);

        while ($dir !== '/' && !file_exists($dir . '/composer.json')) {
            $dir = dirname($dir);
        }

        return $dir;
    }
}
