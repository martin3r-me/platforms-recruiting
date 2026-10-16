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
    private static int $nummer = 0;

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
            $crm . '/database/migrations/2026_07_10_000001_add_is_auto_reply_to_comms_whatsapp_messages.php',
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

    public function test_stempel_traegt_den_zeitpunkt_des_klicks(): void
    {
        [$thread, $message] = $this->eingang(['type' => 'button', 'button' => ['text' => 'Ja']]);

        (new QuickReplyStamper())->stampIfQuickReply($thread, $message);

        $row = RecConversationHandled::query()->where('comms_whatsapp_thread_id', $thread->id)->first();
        $this->assertSame(
            $message->sent_at->getTimestamp(),
            $row->handled_at->getTimestamp(),
            'Verarbeitungszeit statt Klickzeit wuerde jeden spaeter eingehenden Text mit ablegen.',
        );
    }

    public function test_wiederholter_alter_klick_legt_neueren_text_nicht_ab(): void
    {
        // Meta wiederholt Webhooks; der Core feuert das Event auch fuer die Dublette.
        $thread = $this->thread();
        $klick = $this->nachricht($thread, ['type' => 'button', 'button' => ['text' => 'Ja']], -120);
        $this->nachricht($thread, ['type' => 'text', 'text' => ['body' => 'Wann genau?']], -60);

        $this->assertFalse((new QuickReplyStamper())->stampIfQuickReply($thread, $klick));
        $this->assertSame(0, RecConversationHandled::query()->count());
    }

    public function test_erst_getippt_dann_geklickt_bleibt_offen(): void
    {
        $thread = $this->thread();
        $this->nachricht($thread, ['type' => 'text', 'text' => ['body' => 'Ich komme 30 min spaeter']], -120);
        $klick = $this->nachricht($thread, ['type' => 'button', 'button' => ['text' => 'Ja']], -60);

        $this->assertFalse((new QuickReplyStamper())->stampIfQuickReply($thread, $klick));
    }

    public function test_getippt_beantwortet_dann_geklickt_wird_gestempelt(): void
    {
        $thread = $this->thread();
        $this->nachricht($thread, ['type' => 'text', 'text' => ['body' => 'Hallo']], -300);
        $this->nachricht($thread, null, -200, 'outbound');
        $klick = $this->nachricht($thread, ['type' => 'button', 'button' => ['text' => 'Ja']], -60);

        $this->assertTrue((new QuickReplyStamper())->stampIfQuickReply($thread, $klick));
    }

    public function test_auto_quittung_zaehlt_nicht_als_antwort(): void
    {
        $thread = $this->thread();
        $this->nachricht($thread, ['type' => 'text', 'text' => ['body' => 'Hallo?']], -300);
        $this->nachricht($thread, null, -200, 'outbound', autoReply: true);
        $klick = $this->nachricht($thread, ['type' => 'button', 'button' => ['text' => 'Ja']], -60);

        $this->assertFalse((new QuickReplyStamper())->stampIfQuickReply($thread, $klick));
    }

    public function test_text_vor_einem_gueltigen_stempel_zaehlt_nicht(): void
    {
        // Mehrfachauswahl hat den Text schon als gelesen abgelegt.
        $thread = $this->thread();
        $this->nachricht($thread, ['type' => 'text', 'text' => ['body' => 'Hallo']], -300);
        RecConversationHandled::create([
            'team_id' => self::TEAM,
            'comms_whatsapp_thread_id' => $thread->id,
            'handled_at' => date('Y-m-d H:i:s', time() - 200),
            'handled_reason' => RecConversationHandled::REASON_MANUAL,
        ]);
        $klick = $this->nachricht($thread, ['type' => 'button', 'button' => ['text' => 'Ja']], -60);

        $this->assertTrue((new QuickReplyStamper())->stampIfQuickReply($thread, $klick));
    }

    public function test_text_nach_einem_stempel_zaehlt(): void
    {
        $thread = $this->thread();
        RecConversationHandled::create([
            'team_id' => self::TEAM,
            'comms_whatsapp_thread_id' => $thread->id,
            'handled_at' => date('Y-m-d H:i:s', time() - 300),
            'handled_reason' => RecConversationHandled::REASON_QUICK_REPLY,
        ]);
        $this->nachricht($thread, ['type' => 'text', 'text' => ['body' => 'Noch eine Frage']], -200);
        $klick = $this->nachricht($thread, ['type' => 'button', 'button' => ['text' => 'Ja']], -60);

        $this->assertFalse((new QuickReplyStamper())->stampIfQuickReply($thread, $klick));
    }

    public function test_themen_klick_stempelt_nicht(): void
    {
        // S-Themen §2.6: den Zustand nach unseren Themen-Knoepfen setzt allein
        // der ThemenKlickHandler — Lohn/HR muessen offen bleiben.
        foreach (['topic_lohn', 'topic_hr', 'topic_dispo', 'dispo_7'] as $id) {
            [$thread, $message] = $this->eingang([
                'type' => 'interactive',
                'interactive' => ['type' => 'button_reply', 'button_reply' => ['id' => $id, 'title' => 'x']],
            ]);

            $this->assertFalse((new QuickReplyStamper())->qualifies($thread, $message), $id);
            $this->assertFalse((new QuickReplyStamper())->stampIfQuickReply($thread, $message), $id);
            $this->assertFalse(RecConversationHandled::query()->where('comms_whatsapp_thread_id', $thread->id)->exists(), $id);
        }
    }

    public function test_fremder_interaktiver_knopf_stempelt_weiter(): void
    {
        [$thread, $message] = $this->eingang([
            'type' => 'interactive',
            'interactive' => ['type' => 'button_reply', 'button_reply' => ['id' => 'ja', 'title' => 'Ja']],
        ]);

        $this->assertTrue((new QuickReplyStamper())->stampIfQuickReply($thread, $message));
    }

    public function test_inbound_listener_ruft_den_stamper_auf(): void
    {
        // Der Listener braucht den vollen App-Boot (Matching, Intake …) und
        // laesst sich in dieser Suite nicht ausfuehren. Ohne diesen Vertrag
        // fiele ein entfernter Aufruf nirgends auf — alle Stamper-Tests
        // blieben gruen, waehrend auf Prod kein Button-Klick mehr gestempelt wird.
        $quelle = file_get_contents(dirname(__DIR__, 2) . '/src/Listeners/HandleWhatsAppInboundForRecruiting.php');

        $this->assertMatchesRegularExpression(
            '/app\(QuickReplyStamper::class\)->stampIfQuickReply\(\$thread, \$message\)/',
            $quelle,
        );
        // Vor dem Kontext-Gate: auch Mitarbeiter-Threads stehen in der Liste.
        $this->assertLessThan(
            strpos($quelle, 'ThreadContextGate::blocksIntakeAny'),
            strpos($quelle, 'QuickReplyStamper::class'),
        );
    }

    /** @return array{0: CommsWhatsAppThread, 1: CommsWhatsAppMessage} */
    private function eingang(array $payload, string $direction = 'inbound'): array
    {
        $thread = $this->thread();

        return [$thread, $this->nachricht($thread, $payload, -5, $direction)];
    }

    private function thread(): CommsWhatsAppThread
    {
        return CommsWhatsAppThread::create([
            'team_id' => self::TEAM,
            'comms_channel_id' => 1,
            'token' => bin2hex(random_bytes(8)),
            'remote_phone_number' => '+491517' . str_pad((string) ++self::$nummer, 7, '0', STR_PAD_LEFT), // eindeutig je Lauf — Zufall aus 900 Werten kollidierte am Unique-Index (Kanal, Nummer)
            'is_unread' => true,
            'last_inbound_at' => date('Y-m-d H:i:s', time() - 5),
        ]);
    }

    private function nachricht(
        CommsWhatsAppThread $thread,
        ?array $payload,
        int $sekundenRelativ,
        string $direction = 'inbound',
        bool $autoReply = false,
    ): CommsWhatsAppMessage {
        return CommsWhatsAppMessage::create([
            'comms_whatsapp_thread_id' => $thread->id,
            'direction' => $direction,
            'is_auto_reply' => $autoReply,
            'body' => 'x',
            'message_type' => 'text',
            'meta_payload' => $payload,
            'sent_at' => date('Y-m-d H:i:s', time() + $sekundenRelativ),
        ]);
    }
}
