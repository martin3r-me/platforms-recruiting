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
use Platform\Recruiting\Livewire\Conversations\Inbox;
use Platform\Recruiting\Models\RecApplicantSettings;
use Platform\Recruiting\Models\RecConversationHandled;

/**
 * Abschluss-Durchsicht, Befund 5 (IMPORTANT): Inbox::threadForTeam() pruefte
 * bisher NUR team_id. Ein praeparierter Aufruf (z.B. markHandled()) mit der
 * ID eines Dispo-Threads DESSELBEN Teams rendert damit einen Dispo-Chat im
 * Recruiting-Postfach, samt Antwortfeld und Erledigt-Knopf. Getestet ueber
 * markHandled() — die einzige oeffentliche Methode, die threadForTeam()
 * direkt exponiert und einen beobachtbaren Seiteneffekt hat (Stempel-Zeile).
 *
 * Die Einschraenkung darf NUR bei konfiguriertem Kanal-Set greifen — ein Team
 * OHNE konfiguriertes WABA-Konto (Rueckfall-Modus) muss weiterhin jeden
 * eigenen Thread oeffnen/abhaken koennen (sonst waere threadForTeam() dort
 * fuer JEDE ID leer und die Seite im Rueckfall komplett tot).
 */
class InboxThreadForTeamChannelScopeTest extends TestCase
{
    private const TEAM = 894;
    private const TEAM_OHNE_KONTO = 895;

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
    }

    public static function tearDownAfterClass(): void
    {
        Container::getInstance()->forgetInstance('config');
        Facade::clearResolvedInstances();
    }

    protected function tearDown(): void
    {
        // Gleiches Muster wie UnassignedThreadLinkingTest: 'auth'/'session'
        // nur je Test binden, danach wieder loesen.
        Container::getInstance()->forgetInstance('auth');
        Container::getInstance()->forgetInstance('session');
        Facade::clearResolvedInstance('auth');
        Facade::clearResolvedInstance('session');
    }

    public function test_dispo_thread_desselben_teams_wird_nicht_geoeffnet_oder_abgehakt(): void
    {
        $accountId = (int) Capsule::table('integrations_whatsapp_accounts')->insertGetId([
            'uuid' => 'acc-scope-recruiting', 'phone_number' => '+49 160 5559301',
            'title' => 'Recruiting', 'active' => true, 'user_id' => 1,
        ]);
        $recruitingChannelId = (int) Capsule::table('comms_channels')->insertGetId([
            'team_id' => self::TEAM, 'type' => 'whatsapp', 'provider' => 'whatsapp_meta',
            'sender_identifier' => '+49 160 5559301', 'is_active' => true,
            'meta' => json_encode(['integrations_whatsapp_account_id' => $accountId]),
        ]);
        // Dispo-Kanal DESSELBEN Teams — NICHT das konfigurierte Recruiting-Konto.
        $dispoChannelId = (int) Capsule::table('comms_channels')->insertGetId([
            'team_id' => self::TEAM, 'type' => 'whatsapp', 'provider' => 'whatsapp_meta',
            'sender_identifier' => '+49 160 5559302', 'is_active' => true,
            'meta' => json_encode(['integrations_whatsapp_account_id' => 999999]),
        ]);
        RecApplicantSettings::create([
            'team_id' => self::TEAM,
            'settings' => ['auto_pilot_wa_account_id' => $accountId],
        ]);

        $dispoThreadId = (int) CommsWhatsAppThread::create([
            'team_id' => self::TEAM, 'comms_channel_id' => $dispoChannelId,
            'token' => bin2hex(random_bytes(8)), 'remote_phone_number' => '+49 151 89003001',
            'is_unread' => false, 'last_inbound_at' => date('Y-m-d H:i:s', time() - 3600),
        ])->id;
        $recruitingThreadId = (int) CommsWhatsAppThread::create([
            'team_id' => self::TEAM, 'comms_channel_id' => $recruitingChannelId,
            'token' => bin2hex(random_bytes(8)), 'remote_phone_number' => '+49 151 89003002',
            'is_unread' => false, 'last_inbound_at' => date('Y-m-d H:i:s', time() - 3600),
        ])->id;

        self::bindFakeAuthAndSession(self::TEAM);

        $inbox = new Inbox();
        $inbox->markHandled($dispoThreadId);
        $this->assertNull(
            RecConversationHandled::query()->where('comms_whatsapp_thread_id', $dispoThreadId)->first(),
            'Ein Dispo-Thread desselben Teams darf ueber die Kommunikations-Seite nicht abgehakt werden koennen.',
        );

        $inbox->markHandled($recruitingThreadId);
        $this->assertNotNull(
            RecConversationHandled::query()->where('comms_whatsapp_thread_id', $recruitingThreadId)->first(),
            'Ein echter Recruiting-Thread desselben Teams muss weiterhin abhakbar sein.',
        );
    }

    public function test_ohne_konfiguriertes_konto_bleibt_der_rueckfall_modus_funktionsfaehig(): void
    {
        $channelId = (int) Capsule::table('comms_channels')->insertGetId([
            'team_id' => self::TEAM_OHNE_KONTO, 'type' => 'whatsapp', 'provider' => 'whatsapp_meta',
            'sender_identifier' => '+49 160 5559303', 'is_active' => true,
            'meta' => json_encode([]),
        ]);
        // Bewusst KEINE RecApplicantSettings fuer dieses Team.

        $threadId = (int) CommsWhatsAppThread::create([
            'team_id' => self::TEAM_OHNE_KONTO, 'comms_channel_id' => $channelId,
            'token' => bin2hex(random_bytes(8)), 'remote_phone_number' => '+49 151 89003003',
            'is_unread' => false, 'last_inbound_at' => date('Y-m-d H:i:s', time() - 3600),
        ])->id;

        self::bindFakeAuthAndSession(self::TEAM_OHNE_KONTO);

        $inbox = new Inbox();
        $inbox->markHandled($threadId);

        $this->assertNotNull(
            RecConversationHandled::query()->where('comms_whatsapp_thread_id', $threadId)->first(),
            'Ohne konfiguriertes WABA-Konto (Rueckfall) darf threadForTeam() weiterhin jeden Thread des Teams finden.',
        );
    }

    private static function bindFakeAuthAndSession(int $teamId): void
    {
        $user = new class {
            public int $id = 1;
            public $currentTeam;
        };
        $user->currentTeam = (object) ['id' => $teamId];

        $authStub = new class($user) {
            public function __construct(private object $user) {}
            public function user(): object
            {
                return $this->user;
            }
            public function id(): int
            {
                return (int) $this->user->id;
            }
        };

        $sessionStub = new class {
            public function flash(string $key, $value): void {}
        };

        Container::getInstance()->instance('auth', $authStub);
        Container::getInstance()->instance('session', $sessionStub);
    }

    private static function runMigrations(): void
    {
        $own = dirname(__DIR__, 2);
        $crm = self::packageRootOf(CommsChannel::class);
        $integrations = self::packageRootOf(\Platform\Integrations\Models\IntegrationsWhatsAppTemplate::class);

        $files = [
            [$own, 'database/migrations/2026_02_09_000008_create_rec_applicant_settings_table.php'],
            [$own, 'database/migrations/2026_09_15_000002_create_rec_conversation_handled_table.php'],
            [$crm, 'database/migrations/2026_01_14_000003_create_comms_channels_table.php'],
            [$crm, 'database/migrations/2026_02_12_100001_create_comms_whatsapp_threads_table.php'],
            [$integrations, 'database/migrations/2026_01_17_150000_create_integrations_whatsapp_accounts_table.php'],
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
