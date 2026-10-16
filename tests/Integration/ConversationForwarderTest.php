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
use Platform\Recruiting\Models\RecConversationForward;
use Platform\Recruiting\Services\Comms\Forward\ConversationForwarder;
use Platform\Recruiting\Services\Comms\Forward\DispoForwardChannels;
use Platform\Recruiting\Services\Comms\Forward\ForwardTargets;

class ConversationForwarderTest extends TestCase
{
    private const TEAM = 712;

    public static function setUpBeforeClass(): void
    {
        $container = Container::getInstance();
        // Support\Filialen liest config('recruiting.filialen') — Beschriftung der Dispo-Ziele.
        $container->instance('config', new ConfigRepository([
            'recruiting' => ['filialen' => [
                100 => ['code' => 'DUS & ES', 'name' => 'Düsseldorf & Essen'],
                400 => ['code' => 'CGN', 'name' => 'Köln'],
            ]],
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

        $own = dirname(__DIR__, 2);
        $crm = dirname((new \ReflectionClass(CommsChannel::class))->getFileName(), 3);
        foreach ([
            [$crm, 'database/migrations/2026_01_14_000003_create_comms_channels_table.php'],
            [$crm, 'database/migrations/2026_02_12_100001_create_comms_whatsapp_threads_table.php'],
            [$crm, 'database/migrations/2026_02_12_100002_create_comms_whatsapp_messages_table.php'],
            [$crm, 'database/migrations/2026_02_17_200002_add_conversation_thread_id_to_comms_whatsapp_messages.php'],
            [$crm, 'database/migrations/2026_07_10_000001_add_is_auto_reply_to_comms_whatsapp_messages.php'],
            [$own, 'database/migrations/2026_10_02_000001_create_rec_conversation_forwards_table.php'],
            [$own, 'database/migrations/2026_10_16_100001_add_target_channel_id_to_rec_conversation_forwards.php'],
            [$own, 'database/migrations/2026_08_24_000001_create_rec_dispo_filiale_settings_table.php'],
        ] as [$root, $rel]) {
            (require $root . '/' . $rel)->up();
        }
    }

    public static function tearDownAfterClass(): void
    {
        Container::getInstance()->forgetInstance('config');
        Facade::clearResolvedInstances();
    }

    protected function setUp(): void
    {
        Capsule::table('rec_conversation_forwards')->delete();
        Capsule::table('comms_whatsapp_messages')->delete();
        Capsule::table('comms_whatsapp_threads')->delete();
        Capsule::table('comms_channels')->delete();
        Capsule::table('rec_dispo_filiale_settings')->delete();
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

    // ---- HR -> Dispo (Spec 16.10.2026) ----

    /** @return array{0: int, 1: int, 2: int} Kanal-IDs DUS (Filiale 100), CGN (400), ohne Filiale */
    private function dispoKanaele(): array
    {
        $kanal = fn (string $name, string $nr) => (int) Capsule::table('comms_channels')->insertGetId([
            'team_id' => self::TEAM, 'type' => 'whatsapp', 'provider' => 'whatsapp_meta',
            'name' => $name, 'sender_identifier' => $nr, 'is_active' => true,
        ]);
        $dus = $kanal('RheinGedeck Disposition DUS', '+49 211 78177725');
        $cgn = $kanal('RheinGedeck Disposition CGN', '+49 211 78177729');
        $ohne = $kanal('Neue Dispo', '+49 211 78177799');
        Capsule::table('rec_dispo_filiale_settings')->insert([
            ['team_id' => self::TEAM, 'filial_nr' => 400, 'comms_channel_id' => $cgn],
            ['team_id' => self::TEAM, 'filial_nr' => 100, 'comms_channel_id' => $dus],
        ]);

        return [$dus, $cgn, $ohne];
    }

    private function zurDispo(array $kanaele): ConversationForwarder
    {
        return new ConversationForwarder(new DispoForwardChannels($kanaele));
    }

    public function test_dispo_ziele_tragen_den_filialnamen_und_kanaele_ohne_filiale_ihren_namen(): void
    {
        [$dus, $cgn, $ohne] = $this->dispoKanaele();

        $options = (new DispoForwardChannels([$dus, $cgn, $ohne]))->options(self::TEAM);

        $this->assertSame([
            ['channel_id' => $dus, 'filial_nr' => 100, 'label' => 'Dispo Düsseldorf & Essen'],
            ['channel_id' => $cgn, 'filial_nr' => 400, 'label' => 'Dispo Köln'],
            ['channel_id' => $ohne, 'filial_nr' => null, 'label' => 'Dispo Neue Dispo'],
        ], $options);
    }

    public function test_hr_an_dispo_legt_den_dispo_thread_an_und_setzt_ihn_ungelesen(): void
    {
        [$dus, $cgn, $ohne] = $this->dispoKanaele();
        $hr = $this->thread('+4917663854907');
        $m = $this->message($hr->id, 'inbound', 'Ich kann Samstag nicht', '2026-10-16 09:00:00');

        $r = $this->zurDispo([$dus, $cgn, $ohne])->forward(
            self::TEAM, $hr, [$m], 'Bitte umplanen', 42, 'Jonas Stein', (object) ['id' => 7, 'name' => 'Clara'],
            ForwardTargets::DISPO, ForwardTargets::SOURCE_HR, $cgn,
        );

        $this->assertTrue($r['ok'], (string) $r['error']);
        $f = RecConversationForward::findOrFail($r['forward']->id);
        $this->assertSame('hr', $f->source);
        $this->assertSame('dispo', $f->target);
        $this->assertSame($cgn, (int) $f->target_channel_id);

        $ziel = CommsWhatsAppThread::findOrFail($f->target_thread_id);
        $this->assertSame($cgn, (int) $ziel->comms_channel_id);
        $this->assertSame('+4917663854907', $ziel->remote_phone_number);
        $this->assertTrue((bool) $ziel->is_unread);
        $this->assertSame(self::TEAM, (int) $ziel->team_id);
    }

    public function test_hr_an_dispo_nimmt_den_vorhandenen_thread_auch_ohne_plus(): void
    {
        [$dus, $cgn] = $this->dispoKanaele();
        $hr = $this->thread('+4917663854907');
        $m = $this->message($hr->id, 'inbound', 'Hallo', '2026-10-16 09:00:00');
        $vorhanden = (int) Capsule::table('comms_whatsapp_threads')->insertGetId([
            'team_id' => self::TEAM, 'token' => 'tok-dispo', 'comms_channel_id' => $cgn,
            'remote_phone_number' => '4917663854907', 'is_unread' => false,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $r = $this->zurDispo([$dus, $cgn])->forward(
            self::TEAM, $hr, [$m], null, null, 'X', null, ForwardTargets::DISPO, ForwardTargets::SOURCE_HR, $cgn,
        );

        $this->assertSame($vorhanden, (int) $r['forward']->target_thread_id);
        $this->assertSame(1, Capsule::table('comms_whatsapp_threads')->where('comms_channel_id', $cgn)->count());
        $this->assertTrue((bool) CommsWhatsAppThread::findOrFail($vorhanden)->is_unread);
    }

    public function test_hr_an_dispo_ohne_oder_mit_fremdem_kanal_wird_abgewiesen(): void
    {
        [$dus, $cgn] = $this->dispoKanaele();
        $hr = $this->thread();
        $m = $this->message($hr->id, 'inbound', 'Hallo', '2026-10-16 09:00:00');
        $hrKanal = (int) Capsule::table('comms_channels')->insertGetId([
            'team_id' => self::TEAM, 'type' => 'whatsapp', 'provider' => 'whatsapp_meta',
            'name' => 'HR', 'sender_identifier' => '+49 211 78177722', 'is_active' => true,
        ]);

        foreach ([null, $hrKanal, 999999] as $kanal) {
            $r = $this->zurDispo([$dus, $cgn])->forward(
                self::TEAM, $hr, [$m], null, null, 'X', null, ForwardTargets::DISPO, ForwardTargets::SOURCE_HR, $kanal,
            );
            $this->assertFalse($r['ok']);
            $this->assertSame('Bitte eine Dispo auswählen.', $r['error']);
        }
        $this->assertSame(0, RecConversationForward::count());
        $this->assertSame(0, Capsule::table('comms_whatsapp_threads')->whereIn('comms_channel_id', [$dus, $cgn])->count());
    }

    public function test_falsche_richtung_wird_abgewiesen(): void
    {
        [$dus, $cgn] = $this->dispoKanaele();
        $t = $this->thread();
        $m = $this->message($t->id, 'inbound', 'Hallo', '2026-10-16 09:00:00');

        // Dispo -> Dispo und HR -> HR gibt es nicht.
        $a = $this->zurDispo([$dus, $cgn])->forward(self::TEAM, $t, [$m], null, null, 'X', null, ForwardTargets::DISPO, ForwardTargets::SOURCE_DISPO, $cgn);
        $b = (new ConversationForwarder())->forward(self::TEAM, $t, [$m], null, null, 'X', null, ForwardTargets::HR, ForwardTargets::SOURCE_HR);

        $this->assertFalse($a['ok']);
        $this->assertFalse($b['ok']);
        $this->assertSame(0, RecConversationForward::count());
    }
}
