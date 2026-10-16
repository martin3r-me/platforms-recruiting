<?php

namespace Platform\Recruiting\Tests\Integration;

use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Events\Dispatcher;
use Illuminate\Log\Logger;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use Platform\Crm\Models\CommsChannel;
use Platform\Crm\Models\CommsWhatsAppMessage;
use Platform\Crm\Models\CommsWhatsAppThread;
use Platform\Crm\Services\Comms\WhatsAppMetaService;
use Platform\Recruiting\Models\RecConversationForward;
use Platform\Recruiting\Models\RecConversationHandled;
use Platform\Recruiting\Models\RecConversationTopic;
use Platform\Recruiting\Models\RecEmployee;
use Platform\Recruiting\Services\Comms\EmployeeSenderResolver;
use Platform\Recruiting\Services\Comms\Forward\ConversationForwarder;
use Platform\Recruiting\Services\Comms\Forward\DispoForwardChannels;
use Platform\Recruiting\Services\Comms\InteraktivSender;
use Platform\Recruiting\Services\Comms\ThemenAuswahlEmpfaenger;
use Platform\Recruiting\Services\Comms\ThemenKlickHandler;
use Platform\Recruiting\Services\OffenePunkte;
use Psr\Log\NullLogger;

/**
 * Themen-Auswahl, Klicks (S-Themen §2.3–2.6): was nach einem Klick auf unsere
 * Knoepfe passiert. Versand ueber eine Meta-Attrappe, Personensuche und
 * offene Punkte ueber Attrappen — Schema echt (Capsule + Migrationen).
 */
final class ThemenKlickHandlerTest extends TestCase
{
    private const TEAM = 948;
    private const FREMDES_TEAM = 949;
    private const HR_KANAL = 1;
    private const MA_ID = 42;

    private static Capsule $capsule;

    /** Aufgezeichnete sendInteractive-Aufrufe. */
    private \ArrayObject $gesendet;
    /** Status je Send in Reihenfolge; fehlt ein Eintrag, gilt 'sent'. */
    private array $sendStatus = [];
    /** @var list<int> Dispo-Kanaele, die DispoForwardChannels sieht */
    private array $dispoKanaele = [7, 8, 9];
    /** @var list<array{code:string,label:string,status:string,ko:bool}> */
    private array $punkte = [];
    private CommsChannel $kanal;

    public static function setUpBeforeClass(): void
    {
        $container = Container::getInstance();
        self::$capsule = new Capsule();
        self::$capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        self::$capsule->setEventDispatcher(new Dispatcher($container));
        self::$capsule->setAsGlobal();
        self::$capsule->bootEloquent();
        Model::unguard();
        Model::clearBootedModels();
        self::bindeContainer();

        $own = dirname(__DIR__, 2);
        $crm = dirname((new \ReflectionClass(CommsChannel::class))->getFileName(), 3);
        foreach ([
            $own . '/database/migrations/2026_02_09_000008_create_rec_applicant_settings_table.php',
            $own . '/database/migrations/2026_10_17_000001_create_rec_conversation_topics_table.php',
            $own . '/database/migrations/2026_09_15_000002_create_rec_conversation_handled_table.php',
            $own . '/database/migrations/2026_10_02_000001_create_rec_conversation_forwards_table.php',
            $own . '/database/migrations/2026_10_16_100001_add_target_channel_id_to_rec_conversation_forwards.php',
            $own . '/database/migrations/2026_08_24_000001_create_rec_dispo_filiale_settings_table.php',
            $crm . '/database/migrations/2026_01_14_000003_create_comms_channels_table.php',
            $crm . '/database/migrations/2026_02_12_100001_create_comms_whatsapp_threads_table.php',
            $crm . '/database/migrations/2026_02_12_100002_create_comms_whatsapp_messages_table.php',
            $crm . '/database/migrations/2026_02_17_200002_add_conversation_thread_id_to_comms_whatsapp_messages.php',
            $crm . '/database/migrations/2026_07_10_000001_add_is_auto_reply_to_comms_whatsapp_messages.php',
        ] as $path) {
            (require $path)->up();
        }

        // Nur die Spalten, die der Handler liest (Muster KontoAnlegenTest).
        Capsule::schema()->create('rec_employees', function (Blueprint $t) {
            $t->increments('id');
            $t->integer('team_id')->nullable();
            $t->string('first_name')->nullable();
            $t->string('last_name')->nullable();
            $t->string('portal_token')->nullable();
            $t->timestamp('portal_v2_since')->nullable();
            $t->boolean('is_active')->nullable();
            $t->timestamps();
        });

        $kanal = fn (int $id, int $team, string $nummer, string $name) => Capsule::table('comms_channels')->insert([
            'id' => $id, 'team_id' => $team, 'type' => 'whatsapp', 'provider' => 'whatsapp_meta',
            'name' => $name, 'sender_identifier' => $nummer, 'is_active' => true,
        ]);
        $kanal(self::HR_KANAL, self::TEAM, '+49 160 5559001', 'HR');
        $kanal(7, self::TEAM, '+49 211 78177723', 'Disposition DUS');
        $kanal(8, self::TEAM, '+49 221 5550008', 'Disposition CGN');
        $kanal(9, self::TEAM, '+49 201 5550009', 'Disposition ES');
        $kanal(10, self::TEAM, '+49 228 5550010', 'Disposition BN');
        // Nationale Schreibweise und eine ungueltige Nummer (Review Task 4, M4).
        $kanal(11, self::TEAM, '0211 78177724', 'Disposition National');
        $kanal(12, self::TEAM, '123', 'Disposition Kaputt');
        // Gleiche Kanal-Art, aber ein anderes Team.
        $kanal(999, self::FREMDES_TEAM, '+49 30 5550999', 'Fremde Dispo');
        Capsule::table('rec_dispo_filiale_settings')->insert([
            ['team_id' => self::TEAM, 'filial_nr' => 100, 'comms_channel_id' => 7],
            ['team_id' => self::TEAM, 'filial_nr' => 400, 'comms_channel_id' => 8],
        ]);
    }

    public static function tearDownAfterClass(): void
    {
        $c = Container::getInstance();
        $c->forgetInstance('config');
        $c->forgetInstance('url');
        Facade::clearResolvedInstances();
    }

    private static function bindeContainer(): void
    {
        $c = Container::getInstance();
        $c->instance('db', self::$capsule->getDatabaseManager());
        $c->instance('db.schema', self::$capsule->getConnection()->getSchemaBuilder());
        // Log-Attrappe VOR clearResolvedInstances (reference_log_facade_test_stub.md).
        $c->instance('log', new Logger(new NullLogger()));
        $c->instance('config', new \Illuminate\Config\Repository([
            'activity-log' => ['events' => []],
            'recruiting' => ['filialen' => [
                100 => ['code' => 'DUS', 'name' => 'Düsseldorf'],
                400 => ['code' => 'CGN', 'name' => 'Köln'],
            ]],
        ]));
        // route()-Helfer: liefert eine erkennbare URL mit dem Token.
        $c->instance('url', new class {
            public function route($name, $parameters = [], $absolute = true): string
            {
                return 'https://app.test/' . $name . '/' . ($parameters['token'] ?? '');
            }
        });
        Facade::setFacadeApplication($c);
        Facade::clearResolvedInstances();
    }

    protected function setUp(): void
    {
        self::bindeContainer();
        foreach (['rec_conversation_topics', 'rec_conversation_handled', 'rec_conversation_forwards', 'rec_employees',
                  'comms_whatsapp_messages', 'comms_whatsapp_threads'] as $t) {
            Capsule::table($t)->delete();
        }
        $this->gesendet = new \ArrayObject();
        $this->sendStatus = [];
        $this->dispoKanaele = [7, 8, 9];
        $this->punkte = [];
        $this->einstellungen(['comms_topic_enabled' => true, 'use_informal_address' => true]);
        $this->kanal = CommsChannel::query()->findOrFail(self::HR_KANAL);
        $this->mitarbeiter(portalV2: true);
    }

    // ------------------------------------------------------------ Disposition

    public function test_topic_dispo_schickt_die_filialen_als_knoepfe(): void
    {
        [$thread, , $topic] = $this->gefragt();
        $klick = $this->klick($thread, 'topic_dispo');

        $this->assertSame('verarbeitet:topic_dispo', $this->handler()->beiEingang($this->kanal, $thread, $klick));

        $this->assertCount(1, $this->gesendet);
        $i = $this->gesendet[0]['interactive'];
        $this->assertSame('button', $i['type']);
        $this->assertSame(['dispo_7', 'dispo_8', 'dispo_9'], array_map(fn ($k) => $k['reply']['id'], $i['action']['buttons']));
        $this->assertSame('Dispo Düsseldorf', $i['action']['buttons'][0]['reply']['title']);
        $this->assertSame('Dispo Köln', $i['action']['buttons'][1]['reply']['title']);
        $this->assertTrue($this->gesendet[0]['isAutoReply']);
        $this->assertSame((string) $thread->remote_phone_number, $this->gesendet[0]['to']);

        $topic->refresh();
        $this->assertSame(RecConversationTopic::TOPIC_DISPO, $topic->topic);
        $this->assertSame(RecConversationTopic::STATE_CHOSEN, $topic->state);
        $this->assertNotNull($topic->chosen_at);
        $this->assertSame((int) $klick->id, (int) $topic->last_click_message_id);
        $this->assertSame(0, RecConversationHandled::query()->count(), 'Filialwahl allein stempelt nicht.');
    }

    public function test_mehr_als_drei_filialen_als_liste(): void
    {
        $this->dispoKanaele = [7, 8, 9, 10];
        [$thread] = $this->gefragt();

        $this->assertSame('verarbeitet:topic_dispo', $this->handler()->beiEingang($this->kanal, $thread, $this->klick($thread, 'topic_dispo')));

        $i = $this->gesendet[0]['interactive'];
        $this->assertSame('list', $i['type']);
        $zeilen = $i['action']['sections'][0]['rows'];
        $this->assertSame(['dispo_7', 'dispo_8', 'dispo_9', 'dispo_10'], array_column($zeilen, 'id'));
        $this->assertLessThanOrEqual(20, mb_strlen($i['action']['button']));
    }

    public function test_topic_dispo_ohne_filialen_wird_ignoriert(): void
    {
        $this->dispoKanaele = [];
        [$thread, , $topic] = $this->gefragt();

        $this->assertSame('ignoriert:keine_filialen', $this->handler()->beiEingang($this->kanal, $thread, $this->klick($thread, 'topic_dispo')));
        $this->assertCount(0, $this->gesendet);
        $this->assertSame(RecConversationTopic::STATE_ASKED, $topic->fresh()->state);
        $this->assertNull($topic->fresh()->last_click_message_id);
    }

    public function test_dispo_klick_schickt_link_leitet_weiter_und_stempelt(): void
    {
        [$thread, $ursprung, $topic] = $this->gefragt('Komme morgen 30 min später');
        $this->handler()->beiEingang($this->kanal, $thread, $this->klick($thread, 'topic_dispo', -60));
        $this->gesendet->exchangeArray([]);
        $klick = $this->klick($thread, 'dispo_7', -10);

        $this->assertSame('verarbeitet:dispo_7', $this->handler()->beiEingang($this->kanal, $thread, $klick));

        $this->assertCount(1, $this->gesendet);
        $i = $this->gesendet[0]['interactive'];
        $this->assertSame('cta_url', $i['type']);
        $this->assertSame(
            'https://wa.me/4921178177723?text=' . rawurlencode('Komme morgen 30 min später'),
            $i['action']['parameters']['url'],
        );
        $this->assertSame('Zur Dispo Düsseldorf', $i['action']['parameters']['display_text']);
        $this->assertStringContainsString('Dispo Düsseldorf', $i['body']['text']);
        $this->assertTrue($this->gesendet[0]['isAutoReply']);

        $f = RecConversationForward::query()->sole();
        $this->assertSame('dispo', $f->target);
        $this->assertSame('hr', $f->source);
        $this->assertSame(7, (int) $f->target_channel_id);
        $this->assertNull($f->forwarded_by_user_id);
        $this->assertSame(self::MA_ID, (int) $f->rec_employee_id);
        $this->assertSame('Erika Muster', $f->display_name);
        $this->assertSame('Automatisch: Mitarbeiter hat Thema Disposition gewählt', $f->comment);
        $this->assertSame((int) $ursprung->id, (int) $f->messages[0]['message_id']);
        $this->assertCount(1, $f->messages);

        $h = RecConversationHandled::query()->sole();
        $this->assertSame(RecConversationHandled::REASON_ROUTED_DISPO, $h->handled_reason);
        $this->assertNull($h->handled_by_user_id);
        $this->assertSame(self::TEAM, (int) $h->team_id);
        $this->assertSame($klick->sent_at->format('Y-m-d H:i:s'), $h->handled_at->format('Y-m-d H:i:s'));

        $topic->refresh();
        $this->assertSame(RecConversationTopic::STATE_ROUTED, $topic->state);
        $this->assertSame(RecConversationTopic::TOPIC_DISPO, $topic->topic);
        $this->assertSame(7, (int) $topic->dispo_channel_id);
        $this->assertSame((int) $klick->id, (int) $topic->last_click_message_id);
    }

    public function test_dispo_klick_ohne_text_im_ursprung_traegt_vorbelegung(): void
    {
        [$thread] = $this->gefragt('', typ: 'image');
        $this->handler()->beiEingang($this->kanal, $thread, $this->klick($thread, 'topic_dispo', -60));
        $this->gesendet->exchangeArray([]);

        $this->handler()->beiEingang($this->kanal, $thread, $this->klick($thread, 'dispo_8'));

        $this->assertSame(
            'https://wa.me/492215550008?text=' . rawurlencode('Hallo, ich habe eine Frage zu meinem Einsatz.'),
            $this->gesendet[0]['interactive']['action']['parameters']['url'],
        );
    }

    public function test_fremde_kanal_id_wird_ignoriert(): void
    {
        foreach (['dispo_999', 'dispo_10', 'dispo_1', 'dispo_abc', 'dispo_'] as $id) {
            $this->setUp();
            [$thread, , $topic] = $this->gefragt();
            $this->handler()->beiEingang($this->kanal, $thread, $this->klick($thread, 'topic_dispo', -60));
            $vorher = $topic->fresh()->only(['state', 'topic', 'dispo_channel_id', 'last_click_message_id']);
            $this->gesendet->exchangeArray([]);

            $this->assertSame('ignoriert:unbekannte_filiale', $this->handler()->beiEingang($this->kanal, $thread, $this->klick($thread, $id)), $id);

            $this->assertCount(0, $this->gesendet, $id);
            $this->assertSame(0, RecConversationForward::query()->count(), $id);
            $this->assertSame(0, RecConversationHandled::query()->count(), $id);
            $this->assertSame($vorher, $topic->fresh()->only(['state', 'topic', 'dispo_channel_id', 'last_click_message_id']), $id);
        }
    }

    public function test_dispo_klick_ohne_gewaehltes_thema_dispo_wird_ignoriert(): void
    {
        [$thread] = $this->gefragt();

        $this->assertSame('ignoriert:falsches_thema', $this->handler()->beiEingang($this->kanal, $thread, $this->klick($thread, 'dispo_7')));
        $this->assertCount(0, $this->gesendet);
        $this->assertSame(0, RecConversationForward::query()->count());
    }

    public function test_doppelter_webhook_verarbeitet_den_klick_nur_einmal(): void
    {
        [$thread] = $this->gefragt();
        $this->handler()->beiEingang($this->kanal, $thread, $this->klick($thread, 'topic_dispo', -60));
        $klick = $this->klick($thread, 'dispo_7');

        $this->assertSame('verarbeitet:dispo_7', $this->handler()->beiEingang($this->kanal, $thread, $klick));
        $this->assertSame(ThemenKlickHandler::SCHON_VERARBEITET, $this->handler()->beiEingang($this->kanal, $thread, $klick));

        $this->assertCount(2, $this->gesendet, 'Filialen + EIN Link.');
        $this->assertSame(1, RecConversationForward::query()->count());
    }

    public function test_doppelter_webhook_des_themen_klicks_schickt_filialen_einmal(): void
    {
        [$thread] = $this->gefragt();
        $klick = $this->klick($thread, 'topic_dispo');

        $this->handler()->beiEingang($this->kanal, $thread, $klick);
        $this->assertSame('ignoriert:schon_verarbeitet', $this->handler()->beiEingang($this->kanal, $thread, $klick));

        $this->assertCount(1, $this->gesendet);
    }

    public function test_meta_lehnt_link_ab_dann_kein_stempel_aber_weiterleitung(): void
    {
        // Review Task 4, M3: die Weiterleitung ist das Sicherheitsnetz — sie geht
        // auch ohne Link raus, nur der Stempel unterbleibt (Chat bleibt bei HR).
        [$thread, , $topic] = $this->gefragt();
        $this->handler()->beiEingang($this->kanal, $thread, $this->klick($thread, 'topic_dispo', -60));
        $vorher = $topic->fresh()->only(['state', 'topic', 'last_click_message_id', 'chosen_at']);

        foreach (['failed', 'error'] as $status) {
            $this->sendStatus = [$status];
            $klick = $this->klick($thread, 'dispo_7');

            $this->assertSame('ignoriert:send_' . $status, $this->handler()->beiEingang($this->kanal, $thread, $klick), $status);

            $this->assertSame(1, RecConversationForward::query()->count(), $status . ': genau EINE Karte, auch beim zweiten Versuch.');
            $this->assertSame(0, RecConversationHandled::query()->count(), $status);
            $this->assertEquals($vorher, $topic->fresh()->only(['state', 'topic', 'last_click_message_id', 'chosen_at']), $status);
            $this->assertSame(7, (int) $topic->fresh()->dispo_channel_id, $status);
        }

        // Wiederholt Meta den Webhook, darf der Link dann noch rausgehen — ohne zweite Karte.
        $this->sendStatus = [];
        $this->assertSame('verarbeitet:dispo_7', $this->handler()->beiEingang($this->kanal, $thread, $klick));
        $this->assertSame(1, RecConversationForward::query()->count());
        $this->assertSame(RecConversationHandled::REASON_ROUTED_DISPO, RecConversationHandled::query()->sole()->handled_reason);
        $this->assertSame(RecConversationTopic::STATE_ROUTED, $topic->fresh()->state);
    }

    public function test_zwei_getippte_nachrichten_landen_beide_in_karte_und_link(): void
    {
        [$thread, $ursprung] = $this->gefragt('Komme morgen 30 min später');
        // Zweite Nachricht in der Ruhezeit — loest keine neue Frage aus.
        $zweite = $this->nachricht($thread, 'inbound', -200, body: 'und meine Abrechnung fehlt');
        $this->handler()->beiEingang($this->kanal, $thread, $this->klick($thread, 'topic_dispo', -60));
        $this->gesendet->exchangeArray([]);

        $this->assertSame('verarbeitet:dispo_7', $this->handler()->beiEingang($this->kanal, $thread, $this->klick($thread, 'dispo_7')));

        $this->assertSame(
            'https://wa.me/4921178177723?text=' . rawurlencode("Komme morgen 30 min später\nund meine Abrechnung fehlt"),
            $this->gesendet[0]['interactive']['action']['parameters']['url'],
        );
        $f = RecConversationForward::query()->sole();
        $this->assertSame([(int) $ursprung->id, (int) $zweite->id], array_map(fn ($m) => (int) $m['message_id'], $f->messages));
    }

    public function test_neue_frage_nach_frueherer_runde_nimmt_nur_nachrichten_seit_der_letzten_antwort(): void
    {
        $thread = $this->thread();
        $this->nachricht($thread, 'inbound', -5000, body: 'Alte Frage');
        $this->nachricht($thread, 'outbound', -4000, body: 'Antwort von HR');
        [, $neu] = $this->gefragt('Neue Frage', thread: $thread);
        $this->handler()->beiEingang($this->kanal, $thread, $this->klick($thread, 'topic_dispo', -60));
        $this->gesendet->exchangeArray([]);

        $this->handler()->beiEingang($this->kanal, $thread, $this->klick($thread, 'dispo_7'));

        $this->assertSame([(int) $neu->id], array_map(fn ($m) => (int) $m['message_id'], RecConversationForward::query()->sole()->messages));
        $this->assertSame(
            'https://wa.me/4921178177723?text=' . rawurlencode('Neue Frage'),
            $this->gesendet[0]['interactive']['action']['parameters']['url'],
        );
    }

    public function test_zweiter_filial_klick_nach_der_umleitung_wird_ignoriert(): void
    {
        [$thread, , $topic] = $this->gefragt();
        $this->handler()->beiEingang($this->kanal, $thread, $this->klick($thread, 'topic_dispo', -60));
        $this->handler()->beiEingang($this->kanal, $thread, $this->klick($thread, 'dispo_7', -30));
        $stempel = RecConversationHandled::query()->sole()->handled_at->format('Y-m-d H:i:s');

        foreach (['dispo_7', 'dispo_8'] as $id) {
            $this->assertSame('ignoriert:schon_weitergeleitet', $this->handler()->beiEingang($this->kanal, $thread, $this->klick($thread, $id)), $id);
        }

        $this->assertCount(2, $this->gesendet, 'Filialen + EIN Link.');
        $this->assertSame(1, RecConversationForward::query()->count());
        $this->assertSame(7, (int) $topic->fresh()->dispo_channel_id);
        $this->assertSame($stempel, RecConversationHandled::query()->sole()->handled_at->format('Y-m-d H:i:s'));
    }

    public function test_langer_text_wird_gekappt(): void
    {
        $lang = str_repeat('Äöü 😀 Einsatz ', 200);
        [$thread] = $this->gefragt($lang);
        $this->handler()->beiEingang($this->kanal, $thread, $this->klick($thread, 'topic_dispo', -60));
        $this->gesendet->exchangeArray([]);

        $this->handler()->beiEingang($this->kanal, $thread, $this->klick($thread, 'dispo_7'));

        $url = $this->gesendet[0]['interactive']['action']['parameters']['url'];
        $this->assertLessThanOrEqual(2000, strlen($url));
        $this->assertStringStartsWith('https://wa.me/4921178177723?text=' . rawurlencode('Äöü 😀 Einsatz'), $url);
        $text = rawurldecode(substr($url, strlen('https://wa.me/4921178177723?text=')));
        $this->assertTrue(mb_check_encoding($text, 'UTF-8'), 'Nie mitten im Zeichen abschneiden.');

        // Reiner ASCII-Text: 1000 Zeichen Obergrenze greift vor der URL-Grenze.
        $ascii = ThemenKlickHandler::waMeUrl('49211', str_repeat('a', 1500));
        $this->assertSame(1000, strlen(substr($ascii, strlen('https://wa.me/49211?text='))));
    }

    public function test_dispo_nummer_wird_normalisiert_ungueltige_filiale_uebersprungen(): void
    {
        $this->dispoKanaele = [7, 11, 12];
        [$thread] = $this->gefragt('Frage');
        $this->handler()->beiEingang($this->kanal, $thread, $this->klick($thread, 'topic_dispo', -60));

        $ids = array_map(fn ($k) => $k['reply']['id'], $this->gesendet[0]['interactive']['action']['buttons']);
        $this->assertSame(['dispo_7', 'dispo_11'], $ids, 'Kanal mit ungueltiger Nummer wird nicht angeboten.');

        // Manipulierter Klick auf die ungueltige Filiale → nichts.
        $this->assertSame('ignoriert:kanal_ohne_nummer', $this->handler()->beiEingang($this->kanal, $thread, $this->klick($thread, 'dispo_12', -30)));
        $this->assertSame(0, RecConversationForward::query()->count());

        $this->gesendet->exchangeArray([]);
        $this->assertSame('verarbeitet:dispo_11', $this->handler()->beiEingang($this->kanal, $thread, $this->klick($thread, 'dispo_11')));
        $this->assertSame(
            'https://wa.me/4921178177724?text=' . rawurlencode('Frage'),
            $this->gesendet[0]['interactive']['action']['parameters']['url'],
        );
    }

    public function test_weiterleitung_scheitert_stempel_kommt_trotzdem(): void
    {
        // a) Forwarder wirft (Tabelle weg), b) Forwarder lehnt ab (Kanal nicht in SEINER Auswahl).
        foreach (['wirft', 'lehnt_ab'] as $fall) {
            $this->setUp();
            [$thread] = $this->gefragt();
            $this->handler()->beiEingang($this->kanal, $thread, $this->klick($thread, 'topic_dispo', -60));
            $forwarder = $fall === 'lehnt_ab' ? new ConversationForwarder(new DispoForwardChannels([8])) : null;
            if ($fall === 'wirft') {
                Capsule::schema()->rename('rec_conversation_forwards', 'rec_conversation_forwards_weg');
            }

            try {
                $status = $this->handler($forwarder)->beiEingang($this->kanal, $thread, $this->klick($thread, 'dispo_7'));
            } finally {
                if ($fall === 'wirft') {
                    Capsule::schema()->rename('rec_conversation_forwards_weg', 'rec_conversation_forwards');
                }
            }

            $this->assertSame('verarbeitet:dispo_7', $status, $fall);
            $this->assertSame(0, RecConversationForward::query()->count(), $fall);
            $this->assertSame(RecConversationHandled::REASON_ROUTED_DISPO, RecConversationHandled::query()->sole()->handled_reason, $fall);
            $this->assertSame(RecConversationTopic::STATE_ROUTED, RecConversationTopic::query()->sole()->state, $fall);
        }
    }

    // ------------------------------------------------------------ Lohn

    public function test_topic_lohn_mit_offenen_punkten_nennt_sie_und_verlinkt_das_portal(): void
    {
        $this->punkte = [
            ['code' => 'ausweis', 'label' => 'Personalausweis', 'status' => 'fehlt', 'ko' => true],
            ['code' => 'steuer_id', 'label' => 'Steuer-ID', 'status' => 'fehlt', 'ko' => false],
        ];
        [$thread, , $topic] = $this->gefragt();

        $this->assertSame('verarbeitet:topic_lohn', $this->handler()->beiEingang($this->kanal, $thread, $this->klick($thread, 'topic_lohn')));

        $this->assertCount(1, $this->gesendet);
        $i = $this->gesendet[0]['interactive'];
        $this->assertSame('cta_url', $i['type']);
        $this->assertSame('https://app.test/recruiting.public.employee-portal/tok-erika', $i['action']['parameters']['url']);
        $this->assertStringContainsString('• Personalausweis', $i['body']['text']);
        $this->assertStringContainsString('• Steuer-ID', $i['body']['text']);
        $this->assertTrue($this->gesendet[0]['isAutoReply']);

        $topic->refresh();
        $this->assertSame(RecConversationTopic::TOPIC_LOHN, $topic->topic);
        $this->assertSame(RecConversationTopic::STATE_AKTE_HINT, $topic->state);
        $this->assertSame(0, RecConversationHandled::query()->count(), 'Lohn bleibt offen bei HR.');
    }

    public function test_topic_lohn_ohne_portal_v2_nur_text(): void
    {
        $this->mitarbeiter(portalV2: false);
        $this->punkte = [['code' => 'ausweis', 'label' => 'Personalausweis', 'status' => 'fehlt', 'ko' => true]];
        [$thread, , $topic] = $this->gefragt();

        $this->assertSame('verarbeitet:topic_lohn', $this->handler()->beiEingang($this->kanal, $thread, $this->klick($thread, 'topic_lohn')));

        // sendText kennt kein Auto-Antwort-Kennzeichen → ein Quittungsknopf statt Link.
        $i = $this->gesendet[0]['interactive'];
        $this->assertSame('button', $i['type']);
        $this->assertSame([['type' => 'reply', 'reply' => ['id' => 'topic_ack', 'title' => 'Alles klar']]], $i['action']['buttons']);
        $this->assertStringContainsString('• Personalausweis', $i['body']['text']);
        $this->assertStringNotContainsString('tok-erika', json_encode($i));
        $this->assertSame(RecConversationTopic::STATE_AKTE_HINT, $topic->fresh()->state);
    }

    public function test_topic_lohn_akte_vollstaendig_mit_login_url(): void
    {
        $this->einstellungen(['comms_topic_enabled' => true, 'use_informal_address' => true, 'comms_topic_lohn_url' => 'https://app.test/anleitungen/abrechnung']);
        [$thread, , $topic] = $this->gefragt();

        $this->assertSame('verarbeitet:topic_lohn', $this->handler()->beiEingang($this->kanal, $thread, $this->klick($thread, 'topic_lohn')));

        $i = $this->gesendet[0]['interactive'];
        $this->assertSame('cta_url', $i['type']);
        $this->assertSame('https://app.test/anleitungen/abrechnung', $i['action']['parameters']['url']);
        $this->assertStringContainsString('Stundenübersicht', $i['body']['text']);
        $this->assertSame(RecConversationTopic::STATE_LOHN_INFO, $topic->fresh()->state);
        $this->assertSame(0, RecConversationHandled::query()->count());
    }

    public function test_topic_lohn_akte_vollstaendig_ohne_url_nur_quittungsknopf(): void
    {
        [$thread, , $topic] = $this->gefragt();

        $this->handler()->beiEingang($this->kanal, $thread, $this->klick($thread, 'topic_lohn'));

        $i = $this->gesendet[0]['interactive'];
        $this->assertSame('button', $i['type']);
        $this->assertSame('topic_ack', $i['action']['buttons'][0]['reply']['id']);
        $this->assertSame(RecConversationTopic::STATE_LOHN_INFO, $topic->fresh()->state);
    }

    public function test_topic_lohn_send_scheitert_zeile_unveraendert(): void
    {
        $this->sendStatus = ['failed'];
        [$thread, , $topic] = $this->gefragt();

        $this->assertSame('ignoriert:send_failed', $this->handler()->beiEingang($this->kanal, $thread, $this->klick($thread, 'topic_lohn')));

        $this->assertSame(RecConversationTopic::STATE_ASKED, $topic->fresh()->state);
        $this->assertNull($topic->fresh()->topic);
        $this->assertNull($topic->fresh()->last_click_message_id);
    }

    public function test_quittungsklick_ist_ein_no_op(): void
    {
        [$thread, , $topic] = $this->gefragt();
        $this->handler()->beiEingang($this->kanal, $thread, $this->klick($thread, 'topic_lohn', -60));
        $vorher = $topic->fresh()->toArray();
        $this->gesendet->exchangeArray([]);

        $this->assertSame('verarbeitet:topic_ack', $this->handler()->beiEingang($this->kanal, $thread, $this->klick($thread, 'topic_ack')));

        $this->assertCount(0, $this->gesendet);
        $this->assertSame($vorher, $topic->fresh()->toArray());
        $this->assertSame(0, RecConversationHandled::query()->count());
    }

    // ------------------------------------------------------------ HR / Allgemein

    public function test_topic_hr_setzt_nur_das_thema(): void
    {
        [$thread, , $topic] = $this->gefragt();

        $this->assertSame('verarbeitet:topic_hr', $this->handler()->beiEingang($this->kanal, $thread, $this->klick($thread, 'topic_hr')));

        $this->assertCount(0, $this->gesendet);
        $topic->refresh();
        $this->assertSame(RecConversationTopic::TOPIC_HR, $topic->topic);
        $this->assertSame(RecConversationTopic::STATE_CHOSEN, $topic->state);
        $this->assertSame(0, RecConversationHandled::query()->count());
    }

    public function test_klick_ohne_offene_frage_wird_ignoriert(): void
    {
        $thread = $this->thread();
        $this->assertSame('ignoriert:keine_frage', $this->handler()->beiEingang($this->kanal, $thread, $this->klick($thread, 'topic_hr')));

        // Frage aelter als die Ruhezeit (8h) zaehlt nicht mehr.
        [$thread2, , $alt] = $this->gefragt();
        $alt->forceFill(['asked_at' => date('Y-m-d H:i:s', time() - 9 * 3600)])->save();
        $this->assertSame('ignoriert:keine_frage', $this->handler()->beiEingang($this->kanal, $thread2, $this->klick($thread2, 'topic_hr')));
        $this->assertNull($alt->fresh()->topic);
        $this->assertCount(0, $this->gesendet);
    }

    public function test_frage_eines_anderen_threads_zaehlt_nicht(): void
    {
        $this->gefragt();
        $anderer = $this->thread();

        $this->assertSame('ignoriert:keine_frage', $this->handler()->beiEingang($this->kanal, $anderer, $this->klick($anderer, 'topic_hr')));
    }

    public function test_kein_themen_klick_wird_ignoriert(): void
    {
        [$thread, $ursprung] = $this->gefragt();
        $this->assertSame('ignoriert:kein_themen_klick', $this->handler()->beiEingang($this->kanal, $thread, $ursprung));

        $ja = $this->nachricht($thread, 'inbound', -1, payload: [
            'type' => 'interactive', 'interactive' => ['type' => 'button_reply', 'button_reply' => ['id' => 'ja', 'title' => 'Ja']],
        ]);
        $this->assertSame('ignoriert:kein_themen_klick', $this->handler()->beiEingang($this->kanal, $thread, $ja));
    }

    public function test_listener_ruft_den_klick_handler_vor_stamper_und_ooo(): void
    {
        // Der Listener braucht den vollen App-Boot; Vertrag an der Quelle
        // (Muster QuickReplyStamperTest::test_inbound_listener_ruft_den_stamper_auf).
        $quelle = file_get_contents(dirname(__DIR__, 2) . '/src/Listeners/HandleWhatsAppInboundForRecruiting.php');

        $this->assertMatchesRegularExpression(
            '/try\s*\{\s*\$klickStatus = app\(ThemenKlickHandler::class\)->beiEingang\(\$channel, \$thread, \$message\);\s*\}\s*catch \(\\\\Throwable \$e\)/',
            $quelle,
        );
        $klickPos = strpos($quelle, 'ThemenKlickHandler::class)->beiEingang');
        $this->assertLessThan(strpos($quelle, '$this->oooAutoReply->handle('), $klickPos);
        $this->assertLessThan(strpos($quelle, 'QuickReplyStamper::class'), $klickPos);
        $this->assertMatchesRegularExpression(
            '/if \(!ThemenAuswahlHandler::abwesenheitMitgeteilt\(\$themenStatus\)\s*&&\s*!ThemenKlickHandler::beantwortet\(\$klickStatus\)\)\s*\{\s*try\s*\{\s*\$this->oooAutoReply->handle\(/',
            $quelle,
        );
        $this->assertTrue(ThemenKlickHandler::beantwortet('verarbeitet:topic_hr'));
        $this->assertTrue(ThemenKlickHandler::beantwortet(ThemenKlickHandler::SCHON_VERARBEITET));
        $this->assertFalse(ThemenKlickHandler::beantwortet('ignoriert:kein_themen_klick'));
        $this->assertFalse(ThemenKlickHandler::beantwortet('ignoriert:send_failed'));
    }

    // ---------------------------------------------------------------- Hilfen

    private function handler(?ConversationForwarder $forwarder = null): ThemenKlickHandler
    {
        $gesendet = $this->gesendet;
        $status = $this->sendStatus;
        $meta = new class($gesendet, $status) extends WhatsAppMetaService {
            private int $n = 0;

            public function __construct(private \ArrayObject $calls, private array $status) {}

            public function sendInteractive(CommsChannel $channel, string $to, array $interactive, string $displayBody, ?\Platform\Core\Models\User $sender = null, bool $isAutoReply = false): CommsWhatsAppMessage
            {
                $s = array_shift($this->status) ?? 'sent';
                if ($s === 'error') {
                    throw new \RuntimeException('Netz weg');
                }
                $this->calls[] = compact('to', 'interactive', 'displayBody', 'isAutoReply');
                $m = new CommsWhatsAppMessage();
                $m->id = 800 + (++$this->n);
                $m->status = $s;
                $m->meta_payload = [];

                return $m;
            }
        };

        $resolver = new class(self::MA_ID) extends EmployeeSenderResolver {
            public function __construct(private int $id) {}

            public function resolve(string $senderPhone, int $teamId): array
            {
                return ['status' => self::EMPLOYEE, 'employee_id' => $this->id, 'employee_ids' => [$this->id]];
            }
        };

        $punkte = $this->punkte;
        $offen = new class($punkte) extends OffenePunkte {
            public function __construct(private array $p) {}

            public function fuer(RecEmployee $employee, ?string $heute = null): array
            {
                return ['punkte' => $this->p, 'einsatz' => null, 'gesperrt' => false];
            }
        };

        $kanaele = new DispoForwardChannels($this->dispoKanaele);

        return new ThemenKlickHandler(
            new InteraktivSender($meta),
            new ThemenAuswahlEmpfaenger($resolver),
            $kanaele,
            $forwarder ?? new ConversationForwarder($kanaele),
            $offen,
        );
    }

    private function einstellungen(array $settings): void
    {
        Capsule::table('rec_applicant_settings')->where('team_id', self::TEAM)->delete();
        Capsule::table('rec_applicant_settings')->insert(['team_id' => self::TEAM, 'settings' => json_encode($settings)]);
    }

    private function mitarbeiter(bool $portalV2): void
    {
        Capsule::table('rec_employees')->delete();
        Capsule::table('rec_employees')->insert([
            'id' => self::MA_ID, 'team_id' => self::TEAM, 'first_name' => 'Erika', 'last_name' => 'Muster',
            'portal_token' => 'tok-erika', 'portal_v2_since' => $portalV2 ? date('Y-m-d H:i:s', time() - 86400) : null,
            'is_active' => true,
        ]);
    }

    /**
     * Thread mit getipptem Ursprung und offener Frage (state=asked).
     *
     * @return array{0: CommsWhatsAppThread, 1: CommsWhatsAppMessage, 2: RecConversationTopic}
     */
    private function gefragt(string $text = 'Hallo', string $typ = 'text', ?CommsWhatsAppThread $thread = null): array
    {
        $thread ??= $this->thread();
        $ursprung = $this->nachricht($thread, 'inbound', -300, body: $text, typ: $typ, payload: ['type' => $typ]);
        $frage = $this->nachricht($thread, 'outbound', -290, autoReply: true);
        $topic = RecConversationTopic::create([
            'team_id' => self::TEAM,
            'comms_whatsapp_thread_id' => (int) $thread->id,
            'state' => RecConversationTopic::STATE_ASKED,
            'origin_message_id' => (int) $ursprung->id,
            'ask_message_id' => (int) $frage->id,
            'asked_at' => date('Y-m-d H:i:s', time() - 290),
        ]);

        return [$thread, $ursprung, $topic];
    }

    private function klick(CommsWhatsAppThread $thread, string $id, int $sekundenRelativ = -5): CommsWhatsAppMessage
    {
        $art = str_starts_with($id, 'dispo_') && count($this->dispoKanaele) > 3 ? 'list_reply' : 'button_reply';

        return $this->nachricht($thread, 'inbound', $sekundenRelativ, body: 'Klick', payload: [
            'type' => 'interactive',
            'interactive' => ['type' => $art, $art => ['id' => $id, 'title' => 'x']],
        ]);
    }

    private function thread(): CommsWhatsAppThread
    {
        return CommsWhatsAppThread::create([
            'team_id' => self::TEAM,
            'comms_channel_id' => self::HR_KANAL,
            'token' => bin2hex(random_bytes(8)),
            'remote_phone_number' => '+4915170000' . random_int(100, 999),
            'is_unread' => true,
            'last_inbound_at' => date('Y-m-d H:i:s', time() - 5),
        ]);
    }

    private function nachricht(
        CommsWhatsAppThread $thread,
        string $direction,
        int $sekundenRelativ,
        bool $autoReply = false,
        string $body = 'Hallo',
        string $typ = 'text',
        ?array $payload = null,
    ): CommsWhatsAppMessage {
        return CommsWhatsAppMessage::create([
            'comms_whatsapp_thread_id' => $thread->id,
            'direction' => $direction,
            'is_auto_reply' => $autoReply,
            'body' => $body,
            'message_type' => $typ,
            'meta_payload' => $payload,
            'sent_at' => date('Y-m-d H:i:s', time() + $sekundenRelativ),
        ]);
    }
}
