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
use Platform\Recruiting\Console\Commands\ArchiveOldConversations;
use Platform\Recruiting\Models\RecApplicantSettings;
use Platform\Recruiting\Models\RecConversationHandled;

/**
 * Task 12 — Backfill-Kommando fuer die Altlast (411 dauerhaft "verpasste"
 * Chats auf Prod). Das Kommando laeuft NIE automatisch, sondern genau einmal
 * von Hand, zuerst mit --dry-run. Deshalb ist der wichtigste Vertrag: der
 * Probelauf (planFor()) muss EXAKT dieselbe Menge melden, die stamp() ohne
 * das Flag schreiben wuerde — die beiden duerfen nie auseinanderlaufen, weil
 * es nur diesen einen scharfen Lauf gibt.
 */
class ArchiveOldConversationsCommandTest extends TestCase
{
    private const TEAM = 850;
    private const FREMDES_TEAM = 851;
    private const JETZT = 1_757_930_000;

    private static int $channelId = 0;
    private static int $fremdChannelId = 0;
    private static int $threadAlt = 0;
    private static int $threadFrisch = 0;
    private static int $threadAltFremdesTeam = 0;
    private static int $threadAltBereitsErledigt = 0;

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
        self::seedFixtures();
    }

    public static function tearDownAfterClass(): void
    {
        Container::getInstance()->forgetInstance('config');
        Facade::clearResolvedInstances();
    }

    public function test_dry_run_schreibt_nichts_und_meldet_dieselbe_menge(): void
    {
        $command = new ArchiveOldConversations();

        $plan = $command->planFor(self::TEAM, 30, self::JETZT);

        $this->assertContains(self::$threadAlt, $plan);
        $this->assertNotContains(self::$threadFrisch, $plan);

        // planFor() darf selbst nichts schreiben — die Fixtures legen genau
        // eine bereits gestempelte Zeile an ($threadAltBereitsErledigt), und
        // die Anzahl der Zeilen in rec_conversation_handled darf sich durch
        // den Aufruf oben nicht veraendert haben.
        $this->assertSame(1, RecConversationHandled::count());
    }

    public function test_stempel_traegt_backfill_als_grund_und_keinen_nutzer(): void
    {
        $command = new ArchiveOldConversations();
        $plan = $command->planFor(self::TEAM, 30, self::JETZT);

        $command->stamp(self::TEAM, $plan);

        $row = RecConversationHandled::query()
            ->where('comms_whatsapp_thread_id', self::$threadAlt)
            ->first();

        $this->assertNotNull($row);
        $this->assertSame('backfill', $row->handled_reason);
        $this->assertNull($row->handled_by_user_id);

        // Aufraeumen fuer die folgenden Tests, die von einer sauberen
        // rec_conversation_handled-Tabelle ausgehen (bis auf den bewusst
        // vorbelegten $threadAltBereitsErledigt aus den Fixtures).
        RecConversationHandled::query()
            ->where('comms_whatsapp_thread_id', self::$threadAlt)
            ->delete();
    }

    public function test_fremdes_team_wird_nicht_mit_erfasst(): void
    {
        $command = new ArchiveOldConversations();

        $plan = $command->planFor(self::TEAM, 30, self::JETZT);

        $this->assertNotContains(
            self::$threadAltFremdesTeam,
            $plan,
            'Ein alter Chat eines anderen Teams darf nie im Plan dieses Teams auftauchen.',
        );
    }

    public function test_bereits_gestempelte_threads_werden_nicht_doppelt_aufgenommen(): void
    {
        $command = new ArchiveOldConversations();

        $plan = $command->planFor(self::TEAM, 30, self::JETZT);

        $this->assertNotContains(
            self::$threadAltBereitsErledigt,
            $plan,
            'Ein Chat, der bereits einen Stempel traegt (egal aus welchem Grund), darf nicht erneut aufgenommen werden.',
        );
    }

    public function test_planfor_und_stamp_bleiben_deckungsgleich(): void
    {
        $command = new ArchiveOldConversations();

        $vorherigerPlan = $command->planFor(self::TEAM, 30, self::JETZT);
        $command->stamp(self::TEAM, $vorherigerPlan);

        // Ein zweiter Probelauf unmittelbar danach darf keinen der gerade
        // gestempelten Threads mehr melden — sonst waeren Probelauf und
        // Ernstfall bei wiederholtem Aufruf nicht deckungsgleich.
        $zweiterPlan = $command->planFor(self::TEAM, 30, self::JETZT);
        $this->assertNotContains(self::$threadAlt, $zweiterPlan);

        RecConversationHandled::query()
            ->where('comms_whatsapp_thread_id', self::$threadAlt)
            ->delete();
    }

    private static function seedFixtures(): void
    {
        $accountId = (int) Capsule::table('integrations_whatsapp_accounts')->insertGetId([
            'uuid' => 'acc-rec-archive', 'phone_number' => '+49 160 5554001',
            'title' => 'Recruiting', 'active' => true, 'user_id' => 1,
        ]);

        self::$channelId = self::createChannel(self::TEAM, '+49 160 5554001', $accountId);
        self::$fremdChannelId = self::createChannel(self::FREMDES_TEAM, '+49 160 5559001', 999999);

        RecApplicantSettings::create([
            'team_id' => self::TEAM,
            'settings' => ['auto_pilot_wa_account_id' => $accountId],
        ]);

        // Alt: letzter Eingang vor 100 Tagen — muss im Plan auftauchen.
        self::$threadAlt = self::createThread(
            self::TEAM, self::$channelId, '+49 151 80000001', self::JETZT - 100 * 86_400,
        );

        // Frisch: letzter Eingang vor 2 Tagen — darf nicht auftauchen.
        self::$threadFrisch = self::createThread(
            self::TEAM, self::$channelId, '+49 151 80000002', self::JETZT - 2 * 86_400,
        );

        // Alt, aber fremdes Team — Team-Grenze.
        self::$threadAltFremdesTeam = self::createThread(
            self::FREMDES_TEAM, self::$fremdChannelId, '+49 151 80000003', self::JETZT - 100 * 86_400,
        );

        // Alt, aber schon manuell abgehakt — darf nicht doppelt aufgenommen werden.
        self::$threadAltBereitsErledigt = self::createThread(
            self::TEAM, self::$channelId, '+49 151 80000004', self::JETZT - 100 * 86_400,
        );
        RecConversationHandled::create([
            'team_id' => self::TEAM,
            'comms_whatsapp_thread_id' => self::$threadAltBereitsErledigt,
            'handled_at' => date('Y-m-d H:i:s', self::JETZT - 1_000),
            'handled_by_user_id' => 1,
            'handled_reason' => RecConversationHandled::REASON_MANUAL,
        ]);
    }

    private static function createChannel(int $teamId, string $sender, int $accountId): int
    {
        return (int) Capsule::table('comms_channels')->insertGetId([
            'team_id' => $teamId, 'type' => 'whatsapp', 'provider' => 'whatsapp_meta',
            'sender_identifier' => $sender, 'is_active' => true,
            'meta' => json_encode(['integrations_whatsapp_account_id' => $accountId]),
        ]);
    }

    private static function createThread(int $teamId, int $channelId, string $phone, int $lastInboundAt): int
    {
        return (int) CommsWhatsAppThread::create([
            'team_id' => $teamId,
            'comms_channel_id' => $channelId,
            'token' => bin2hex(random_bytes(8)),
            'remote_phone_number' => $phone,
            'context_model' => 'Platform\\Crm\\Models\\CrmContact',
            'context_model_id' => 1,
            'is_unread' => false,
            'last_inbound_at' => date('Y-m-d H:i:s', $lastInboundAt),
            'last_message_preview' => 'Dankeschoen',
        ])->id;
    }

    private static function runMigrations(): void
    {
        $own = dirname(__DIR__, 2);
        $crm = self::packageRootOf(CommsChannel::class);
        $integrations = self::packageRootOf(IntegrationsWhatsAppTemplate::class);

        $files = [
            [$own, 'database/migrations/2026_02_09_000008_create_rec_applicant_settings_table.php'],
            [$own, 'database/migrations/2026_09_15_000002_create_rec_conversation_handled_table.php'],
            [$crm, 'database/migrations/2026_01_14_000003_create_comms_channels_table.php'],
            [$crm, 'database/migrations/2026_02_12_100001_create_comms_whatsapp_threads_table.php'],
            [$crm, 'database/migrations/2026_02_12_100002_create_comms_whatsapp_messages_table.php'],
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
