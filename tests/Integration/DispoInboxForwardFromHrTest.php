<?php

namespace Platform\Recruiting\Tests\Integration;

use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use Platform\Crm\Models\CommsChannel;
use Platform\Crm\Models\CommsWhatsAppThread;
use Platform\Recruiting\Livewire\Dispo\Conversations\Index;
use Platform\Recruiting\Models\RecConversationForward;

/**
 * Weiterleitung HR -> Dispo, Seite Dispo-Postfach (Spec 16.10.2026): Oeffnen
 * erledigt die Weiterleitung, „ungelesen schliessen“ holt genau diese zurueck —
 * dieselbe Regel wie im HR-Postfach. Eine Weiterleitung AN HR auf demselben
 * Thread-Wert darf das Dispo-Postfach nie anfassen.
 */
class DispoInboxForwardFromHrTest extends TestCase
{
    private const TEAM = 896;

    private static int $channelId = 0;

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

        $own = dirname(__DIR__, 2);
        $crm = dirname((new \ReflectionClass(CommsChannel::class))->getFileName(), 3);
        foreach ([
            $crm . '/database/migrations/2026_01_14_000003_create_comms_channels_table.php',
            $crm . '/database/migrations/2026_02_12_100001_create_comms_whatsapp_threads_table.php',
            $own . '/database/migrations/2026_10_02_000001_create_rec_conversation_forwards_table.php',
            $own . '/database/migrations/2026_10_16_100001_add_target_channel_id_to_rec_conversation_forwards.php',
        ] as $path) {
            (require $path)->up();
        }

        self::$channelId = (int) Capsule::table('comms_channels')->insertGetId([
            'team_id' => self::TEAM, 'type' => 'whatsapp', 'provider' => 'whatsapp_meta',
            'name' => 'RheinGedeck Disposition CGN', 'sender_identifier' => '+49 211 78177729', 'is_active' => true,
        ]);
    }

    public static function tearDownAfterClass(): void
    {
        Container::getInstance()->forgetInstance('auth');
        Facade::clearResolvedInstances();
    }

    public function test_oeffnen_erledigt_die_hr_weiterleitung_und_ungelesen_schliessen_holt_sie_zurueck(): void
    {
        $threadId = (int) CommsWhatsAppThread::create([
            'team_id' => self::TEAM, 'comms_channel_id' => self::$channelId,
            'token' => bin2hex(random_bytes(8)), 'remote_phone_number' => '+4917663854907',
            'is_unread' => true,
        ])->id;
        $vonHr = $this->forward(['source' => 'hr', 'target' => 'dispo', 'target_channel_id' => self::$channelId, 'target_thread_id' => $threadId]);
        $vorherErledigt = $this->forward([
            'source' => 'hr', 'target' => 'dispo', 'target_channel_id' => self::$channelId, 'target_thread_id' => $threadId,
            'done_at' => date('Y-m-d H:i:s', time() - 800), 'done_by_user_id' => 1,
        ]);
        // Gleicher Thread-Wert, aber Ziel HR — gehoert nicht der Dispo.
        $anHr = $this->forward(['target_thread_id' => $threadId]);

        self::bindFakeAuth();
        $inbox = new DispoInboxMitFestemKanal();

        $inbox->select($threadId);

        $this->assertFalse((bool) CommsWhatsAppThread::query()->whereKey($threadId)->value('is_unread'));
        $this->assertNotNull($vonHr->fresh()->done_at, 'Oeffnen muss die Weiterleitung von HR erledigen.');
        $this->assertNull($anHr->fresh()->done_at, 'Eine Weiterleitung AN HR darf das Dispo-Postfach nicht erledigen.');
        $this->assertSame([(int) $vonHr->id], $inbox->openedForwardIds);

        $inbox->select($threadId);
        $this->assertSame([(int) $vonHr->id], $inbox->openedForwardIds, 'Erneutes Oeffnen verliert die gemerkte ID nicht.');

        $inbox->markUnreadAndClose();

        $this->assertTrue((bool) CommsWhatsAppThread::query()->whereKey($threadId)->value('is_unread'));
        $this->assertNull($vonHr->fresh()->done_at, '„ungelesen schliessen“ muss die Weiterleitung zurueckholen.');
        $this->assertNotNull($vorherErledigt->fresh()->done_at);
        $this->assertSame([], $inbox->openedForwardIds);
    }

    private function forward(array $attrs): RecConversationForward
    {
        return RecConversationForward::create(array_merge([
            'team_id' => self::TEAM, 'source_thread_id' => 1, 'phone' => '+4917663854907',
            'display_name' => 'Jonas Stein', 'messages' => [], 'forwarded_at' => date('Y-m-d H:i:s', time() - 600),
        ], $attrs));
    }

    private static function bindFakeAuth(): void
    {
        $user = new class {
            public int $id = 1;
            public $currentTeam;
        };
        $user->currentTeam = (object) ['id' => self::TEAM];

        Container::getInstance()->instance('auth', new class($user) {
            public function __construct(private object $user) {}
            public function user(): object
            {
                return $this->user;
            }
            public function id(): int
            {
                return (int) $this->user->id;
            }
        });
    }

    public static function kanal(): int
    {
        return self::$channelId;
    }
}

/** Kanal-Set fest statt ueber DispoChannelResolver (braucht Vorlagen-Einstellung + Konto). */
class DispoInboxMitFestemKanal extends Index
{
    public function channelIds(): array
    {
        return [DispoInboxForwardFromHrTest::kanal()];
    }
}
