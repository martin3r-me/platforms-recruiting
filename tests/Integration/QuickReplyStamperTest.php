<?php

namespace Platform\Recruiting\Tests\Integration;

use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use Platform\Crm\Models\CommsChannel;
use Platform\Crm\Models\CommsWhatsAppMessage;
use Platform\Crm\Models\CommsWhatsAppThread;
use Platform\Recruiting\Models\RecConversationHandled;
use Platform\Recruiting\Services\Comms\QuickReplyStamper;

/**
 * Ein Button-Klick legt den Chat sofort als gelesen ab (Spec 16.10.2026) —
 * ueber den eigenen Stempel, nicht ueber is_unread im Core.
 */
class QuickReplyStamperTest extends TestCase
{
    private const TEAM = 731;

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
            $own . '/database/migrations/2026_09_15_000002_create_rec_conversation_handled_table.php',
            $crm . '/database/migrations/2026_02_12_100001_create_comms_whatsapp_threads_table.php',
            $crm . '/database/migrations/2026_02_12_100002_create_comms_whatsapp_messages_table.php',
        ] as $path) {
            (require $path)->up();
        }
    }

    public static function tearDownAfterClass(): void
    {
        Facade::clearResolvedInstances();
    }

    protected function setUp(): void
    {
        Capsule::table('rec_conversation_handled')->delete();
    }

    public function test_button_klick_stempelt_den_chat(): void
    {
        [$thread, $message] = $this->eingang(['type' => 'button', 'button' => ['text' => 'Ja']]);

        $this->assertTrue((new QuickReplyStamper())->stampIfQuickReply($thread, $message));

        $row = RecConversationHandled::query()->where('comms_whatsapp_thread_id', $thread->id)->first();
        $this->assertNotNull($row);
        $this->assertSame(self::TEAM, (int) $row->team_id);
        $this->assertSame(RecConversationHandled::REASON_QUICK_REPLY, $row->handled_reason);
        $this->assertNull($row->handled_by_user_id);
    }

    public function test_getippter_text_stempelt_nicht(): void
    {
        [$thread, $message] = $this->eingang(['type' => 'text', 'text' => ['body' => 'Ja bin dabei']]);

        $this->assertFalse((new QuickReplyStamper())->stampIfQuickReply($thread, $message));
        $this->assertSame(0, RecConversationHandled::query()->count());
    }

    public function test_alter_stempel_wird_ueberschrieben(): void
    {
        [$thread, $message] = $this->eingang(['type' => 'button', 'button' => ['text' => 'Nein']]);
        RecConversationHandled::create([
            'team_id' => self::TEAM,
            'comms_whatsapp_thread_id' => $thread->id,
            'handled_at' => '2026-05-01 10:00:00',
            'handled_by_user_id' => 5,
            'handled_reason' => RecConversationHandled::REASON_MANUAL,
        ]);

        (new QuickReplyStamper())->stampIfQuickReply($thread, $message);

        $rows = RecConversationHandled::query()->where('comms_whatsapp_thread_id', $thread->id)->get();
        $this->assertCount(1, $rows);
        $this->assertSame(RecConversationHandled::REASON_QUICK_REPLY, $rows[0]->handled_reason);
        $this->assertGreaterThan(
            strtotime('2026-05-01 10:00:00'),
            $rows[0]->handled_at->getTimestamp(),
            'Der neue Stempel muss den Eingang abdecken, sonst gilt er sofort als veraltet.',
        );
    }

    public function test_ausgehende_nachricht_stempelt_nicht(): void
    {
        [$thread, $message] = $this->eingang(['type' => 'button'], 'outbound');

        $this->assertFalse((new QuickReplyStamper())->stampIfQuickReply($thread, $message));
    }

    /** @return array{0: CommsWhatsAppThread, 1: CommsWhatsAppMessage} */
    private function eingang(array $payload, string $direction = 'inbound'): array
    {
        $thread = CommsWhatsAppThread::create([
            'team_id' => self::TEAM,
            'comms_channel_id' => 1,
            'token' => bin2hex(random_bytes(8)),
            'remote_phone_number' => '+4915170000' . random_int(100, 999),
            'is_unread' => true,
            'last_inbound_at' => date('Y-m-d H:i:s', time() - 5),
        ]);
        $message = CommsWhatsAppMessage::create([
            'comms_whatsapp_thread_id' => $thread->id,
            'direction' => $direction,
            'body' => 'Ja',
            'message_type' => 'text',
            'meta_payload' => $payload,
        ]);

        return [$thread, $message];
    }
}
