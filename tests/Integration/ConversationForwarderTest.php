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
use Platform\Recruiting\Models\RecConversationForward;
use Platform\Recruiting\Services\Comms\Forward\ConversationForwarder;

class ConversationForwarderTest extends TestCase
{
    private const TEAM = 712;

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
            [$crm, 'database/migrations/2026_01_14_000003_create_comms_channels_table.php'],
            [$crm, 'database/migrations/2026_02_12_100001_create_comms_whatsapp_threads_table.php'],
            [$crm, 'database/migrations/2026_02_12_100002_create_comms_whatsapp_messages_table.php'],
            [$crm, 'database/migrations/2026_02_17_200002_add_conversation_thread_id_to_comms_whatsapp_messages.php'],
            [$crm, 'database/migrations/2026_07_10_000001_add_is_auto_reply_to_comms_whatsapp_messages.php'],
            [$own, 'database/migrations/2026_10_02_000001_create_rec_conversation_forwards_table.php'],
        ] as [$root, $rel]) {
            (require $root . '/' . $rel)->up();
        }
    }

    public static function tearDownAfterClass(): void
    {
        Facade::clearResolvedInstances();
    }

    protected function setUp(): void
    {
        Capsule::table('rec_conversation_forwards')->delete();
        Capsule::table('comms_whatsapp_messages')->delete();
        Capsule::table('comms_whatsapp_threads')->delete();
    }

    private function thread(string $phone = '+4917663854907'): CommsWhatsAppThread
    {
        $id = (int) Capsule::table('comms_whatsapp_threads')->insertGetId([
            'team_id' => self::TEAM, 'token' => 'tok-' . bin2hex(random_bytes(6)), 'comms_channel_id' => 1,
            'remote_phone_number' => $phone, 'is_unread' => false,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return CommsWhatsAppThread::findOrFail($id);
    }

    private function message(int $threadId, string $direction, string $body, string $at, string $type = 'text'): int
    {
        return (int) Capsule::table('comms_whatsapp_messages')->insertGetId([
            'comms_whatsapp_thread_id' => $threadId, 'direction' => $direction, 'body' => $body,
            'message_type' => $type, 'created_at' => $at, 'updated_at' => $at,
        ]);
    }

    public function test_legt_weiterleitung_mit_kopie_und_kommentar_an(): void
    {
        $t = $this->thread();
        $a = $this->message($t->id, 'inbound', 'Guten Abend', '2026-10-01 19:20:00');
        $b = $this->message($t->id, 'inbound', 'Wann kommt das Gehalt?', '2026-10-01 19:24:00');
        $user = (object) ['id' => 7, 'name' => 'Sebastian'];

        $r = (new ConversationForwarder())->forward(self::TEAM, $t, [$b, $a], '  Gehalt September fehlt ', 42, 'Jonas Stein', $user);

        $this->assertTrue($r['ok'], (string) $r['error']);
        $f = RecConversationForward::findOrFail($r['forward']->id);
        $this->assertSame([$a, $b], array_column($f->messages, 'message_id'), 'chronologisch');
        $this->assertSame('Wann kommt das Gehalt?', $f->messages[1]['body']);
        $this->assertSame('Gehalt September fehlt', $f->comment);
        $this->assertSame(42, (int) $f->rec_employee_id);
        $this->assertSame('+4917663854907', $f->phone);
        $this->assertSame('Sebastian', $f->forwarded_by_name);
        $this->assertSame(self::TEAM, (int) $f->team_id);
        $this->assertNull($f->done_at);
    }

    public function test_fremde_und_ausgehende_ids_werden_abgewiesen(): void
    {
        $t = $this->thread();
        $other = $this->thread('+4915100000000');
        $out = $this->message($t->id, 'outbound', 'ok', '2026-10-01 10:53:00');
        $foreign = $this->message($other->id, 'inbound', 'fremd', '2026-10-01 10:00:00');

        $r = (new ConversationForwarder())->forward(self::TEAM, $t, [$out, $foreign], null, null, '+4917663854907', null);

        $this->assertFalse($r['ok']);
        $this->assertSame('Keine eingehende Nachricht ausgewählt.', $r['error']);
        $this->assertSame(0, RecConversationForward::count());
    }

    public function test_unbekanntes_ziel_wird_abgewiesen(): void
    {
        $t = $this->thread();
        $m = $this->message($t->id, 'inbound', 'Hallo', '2026-10-01 10:00:00');

        $r = (new ConversationForwarder())->forward(self::TEAM, $t, [$m], null, null, 'X', null, 'lohn');

        $this->assertFalse($r['ok']);
        $this->assertSame('Unbekanntes Weiterleitungsziel.', $r['error']);
        $this->assertSame(0, RecConversationForward::count());
    }

    public function test_leerer_kommentar_wird_null(): void
    {
        $t = $this->thread();
        $m = $this->message($t->id, 'inbound', 'Hallo', '2026-10-01 10:00:00');

        $r = (new ConversationForwarder())->forward(self::TEAM, $t, [$m], '   ', null, 'X', null);

        $this->assertNull(RecConversationForward::findOrFail($r['forward']->id)->comment);
    }

    public function test_medien_nachricht_behaelt_typ(): void
    {
        $t = $this->thread();
        $m = $this->message($t->id, 'inbound', '', '2026-10-01 10:00:00', 'image');

        $r = (new ConversationForwarder())->forward(self::TEAM, $t, [$m], null, null, 'X', null);

        $this->assertSame('image', RecConversationForward::findOrFail($r['forward']->id)->messages[0]['media_type']);
    }
}
