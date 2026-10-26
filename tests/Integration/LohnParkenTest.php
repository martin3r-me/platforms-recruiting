<?php

namespace Platform\Recruiting\Tests\Integration;

use Carbon\Carbon;
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
use Platform\Recruiting\Console\Commands\LohnParkenFreigeben;
use Platform\Recruiting\Models\RecConversationTopic;
use Platform\Recruiting\Models\RecEmployee;
use Platform\Recruiting\Services\Comms\EmployeeSenderResolver;
use Platform\Recruiting\Services\Comms\InteraktivSender;
use Platform\Recruiting\Services\Comms\LohnLuecken;
use Platform\Recruiting\Services\Comms\LohnParken;
use Platform\Recruiting\Services\Comms\ThemenAuswahlEmpfaenger;
use Psr\Log\NullLogger;

/**
 * Lohn-Chat parken (Variante B, 26.10.2026): Erinnerung beim Weiterschreiben
 * (hoechstens stuendlich), Freigabe beim Weiterschreiben und durch den Lauf.
 */
final class LohnParkenTest extends TestCase
{
    private const TEAM = 951;
    private const HR_KANAL = 1;
    private const MA_ID = 42;

    private static Capsule $capsule;

    private \ArrayObject $gesendet;
    private array $sendStatus = [];
    /** @var list<string> */
    private array $luecken = ['IBAN'];
    private ?int $maId = self::MA_ID;
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
            $crm . '/database/migrations/2026_01_14_000003_create_comms_channels_table.php',
            $crm . '/database/migrations/2026_02_12_100001_create_comms_whatsapp_threads_table.php',
            $crm . '/database/migrations/2026_02_12_100002_create_comms_whatsapp_messages_table.php',
            $crm . '/database/migrations/2026_07_10_000001_add_is_auto_reply_to_comms_whatsapp_messages.php',
        ] as $path) {
            (require $path)->up();
        }
        Capsule::schema()->create('rec_employees', function (Blueprint $t) {
            $t->increments('id');
            $t->integer('team_id')->nullable();
            $t->string('portal_token')->nullable();
            $t->timestamp('portal_v2_since')->nullable();
            $t->boolean('is_active')->nullable();
            $t->timestamps();
        });
        Capsule::table('comms_channels')->insert([
            'id' => self::HR_KANAL, 'team_id' => self::TEAM, 'type' => 'whatsapp', 'provider' => 'whatsapp_meta',
            'name' => 'HR', 'sender_identifier' => '+49 160 5559001', 'is_active' => true,
        ]);
    }

    public static function tearDownAfterClass(): void
    {
        $c = Container::getInstance();
        $c->forgetInstance('url');
        Facade::clearResolvedInstances();
    }

    private static function bindeContainer(): void
    {
        $c = Container::getInstance();
        $c->instance('db', self::$capsule->getDatabaseManager());
        $c->instance('db.schema', self::$capsule->getConnection()->getSchemaBuilder());
        $c->instance('log', new Logger(new NullLogger()));
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
        foreach (['rec_conversation_topics', 'rec_conversation_handled', 'rec_employees', 'comms_whatsapp_messages', 'comms_whatsapp_threads', 'rec_applicant_settings'] as $t) {
            Capsule::table($t)->delete();
        }
        $this->gesendet = new \ArrayObject();
        $this->sendStatus = [];
        $this->luecken = ['IBAN'];
        $this->maId = self::MA_ID;
        Capsule::table('rec_applicant_settings')->insert(['team_id' => self::TEAM, 'settings' => json_encode(['comms_topic_enabled' => true, 'use_informal_address' => true])]);
        Capsule::table('rec_employees')->insert([
            'id' => self::MA_ID, 'team_id' => self::TEAM, 'portal_token' => 'tok-erika',
            'portal_v2_since' => date('Y-m-d H:i:s', time() - 86400), 'is_active' => true,
        ]);
        $this->kanal = CommsChannel::query()->findOrFail(self::HR_KANAL);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
    }

    // ------------------------------------------------------------ Weiterschreiben

    public function test_weiterschreiben_akte_unvollstaendig_schickt_die_liste_noch_einmal(): void
    {
        [$thread, $topic] = $this->geparkt(vorSekunden: 2 * 3600);

        $this->assertSame(LohnParken::ERINNERT, $this->parken()->beiEingang($this->kanal, $thread, $this->getippt($thread)));

        $this->assertCount(1, $this->gesendet);
        $i = $this->gesendet[0]['interactive'];
        $this->assertSame('cta_url', $i['type']);
        $this->assertStringContainsString('• IBAN', $i['body']['text']);
        $this->assertSame('https://app.test/recruiting.public.employee-portal/tok-erika', $i['action']['parameters']['url']);
        $this->assertTrue($this->gesendet[0]['isAutoReply']);
        $topic->refresh();
        $this->assertSame(RecConversationTopic::STATE_GEPARKT, $topic->state);
        $this->assertGreaterThan(time() - 60, $topic->updated_at->getTimestamp(), 'Erinnerung startet die Stunde neu.');
    }

    public function test_innerhalb_der_stunde_keine_zweite_liste(): void
    {
        [$thread] = $this->geparkt(vorSekunden: 600);

        $status = $this->parken()->beiEingang($this->kanal, $thread, $this->getippt($thread));

        $this->assertSame(LohnParken::STILL, $status);
        $this->assertTrue(LohnParken::antwortet($status), 'auch still: keine Abwesenheitsnotiz dazu');
        $this->assertCount(0, $this->gesendet);
    }

    public function test_weiterschreiben_akte_vollstaendig_gibt_frei_ohne_nachricht(): void
    {
        $this->luecken = [];
        [$thread, $topic] = $this->geparkt(vorSekunden: 2 * 3600);

        $status = $this->parken()->beiEingang($this->kanal, $thread, $this->getippt($thread));

        $this->assertSame(LohnParken::FREIGEGEBEN, $status);
        $this->assertFalse(LohnParken::antwortet($status), 'die Nachricht geht normal an HR');
        $this->assertCount(0, $this->gesendet);
        $this->assertSame(RecConversationTopic::STATE_FREIGEGEBEN, $topic->fresh()->state);
    }

    public function test_weiterschreiben_ohne_aktiven_ma_gibt_frei(): void
    {
        $this->maId = null;
        [$thread, $topic] = $this->geparkt(vorSekunden: 2 * 3600);

        $this->assertSame(LohnParken::FREIGEGEBEN, $this->parken()->beiEingang($this->kanal, $thread, $this->getippt($thread)));
        $this->assertSame(RecConversationTopic::STATE_FREIGEGEBEN, $topic->fresh()->state);
    }

    public function test_nicht_geparkt_klick_und_schalter_aus_werden_ignoriert(): void
    {
        [$thread] = $this->geparkt(vorSekunden: 2 * 3600, state: RecConversationTopic::STATE_LOHN_INFO);
        $this->assertSame('ignoriert:nicht_geparkt', $this->parken()->beiEingang($this->kanal, $thread, $this->getippt($thread)));

        [$thread] = $this->geparkt(vorSekunden: 2 * 3600);
        $klick = $this->nachricht($thread, ['type' => 'interactive', 'interactive' => ['type' => 'button_reply', 'button_reply' => ['id' => 'topic_ack', 'title' => 'x']]]);
        $this->assertSame('ignoriert:kein_getippter_eingang', $this->parken()->beiEingang($this->kanal, $thread, $klick));

        Capsule::table('rec_applicant_settings')->update(['settings' => json_encode(['comms_topic_enabled' => false])]);
        $this->assertSame('ignoriert:schalter_aus', $this->parken()->beiEingang($this->kanal, $thread, $this->getippt($thread)));
        $this->assertCount(0, $this->gesendet);
    }

    public function test_neuere_frage_loest_das_parken_ab(): void
    {
        [$thread] = $this->geparkt(vorSekunden: 2 * 3600);
        RecConversationTopic::create([
            'team_id' => self::TEAM, 'comms_whatsapp_thread_id' => (int) $thread->id,
            'state' => RecConversationTopic::STATE_ASKED, 'asked_at' => date('Y-m-d H:i:s'),
        ]);

        $this->assertSame('ignoriert:nicht_geparkt', $this->parken()->beiEingang($this->kanal, $thread, $this->getippt($thread)));
    }

    // ------------------------------------------------------------ Lauf

    public function test_lauf_laesst_unvollstaendige_akte_geparkt(): void
    {
        [, $topic] = $this->geparkt(vorSekunden: 2 * 3600);

        $this->assertSame('geparkt', $this->parken()->freigebenWennVollstaendig($topic));
        $this->assertCount(0, $this->gesendet);
        $this->assertSame(RecConversationTopic::STATE_GEPARKT, $topic->fresh()->state);
    }

    public function test_lauf_akte_vollstaendig_dankt_und_gibt_frei_einmal(): void
    {
        $this->luecken = [];
        [, $topic] = $this->geparkt(vorSekunden: 2 * 3600);

        $this->assertSame(LohnParken::FREIGEGEBEN, $this->parken()->freigebenWennVollstaendig($topic));
        $this->assertCount(1, $this->gesendet);
        $this->assertStringContainsString('Akte ist jetzt vollständig', $this->gesendet[0]['interactive']['body']['text']);
        $this->assertTrue($this->gesendet[0]['isAutoReply']);
        $this->assertSame(RecConversationTopic::STATE_FREIGEGEBEN, $topic->fresh()->state);

        // Zweiter Lauf auf derselben (veralteten) Instanz: kein zweiter Dank.
        $alt = RecConversationTopic::query()->find($topic->id);
        $alt->state = RecConversationTopic::STATE_GEPARKT;
        $this->assertSame('geparkt', $this->parken()->freigebenWennVollstaendig($alt));
        $this->assertCount(1, $this->gesendet);
    }

    public function test_lauf_gibt_frei_auch_wenn_meta_ablehnt(): void
    {
        // Ausserhalb des 24-h-Fensters lehnt Meta freie Nachrichten ab — der Chat muss trotzdem zu HR.
        $this->luecken = [];
        $this->sendStatus = ['failed'];
        [, $topic] = $this->geparkt(vorSekunden: 3 * 86400);

        $this->assertSame('freigegeben:ohne_nachricht', $this->parken()->freigebenWennVollstaendig($topic));
        $this->assertSame(RecConversationTopic::STATE_FREIGEGEBEN, $topic->fresh()->state);
    }

    public function test_lauf_schalter_aus_gibt_still_frei(): void
    {
        [, $topic] = $this->geparkt(vorSekunden: 2 * 3600);
        Capsule::table('rec_applicant_settings')->update(['settings' => json_encode(['comms_topic_enabled' => false])]);

        $this->assertSame('freigegeben:schalter_aus', $this->parken()->freigebenWennVollstaendig($topic));
        $this->assertCount(0, $this->gesendet);
    }

    public function test_kommando_nimmt_nur_die_juengste_geparkte_zeile_je_chat(): void
    {
        $this->luecken = [];
        [$thread, $alt] = $this->geparkt(vorSekunden: 2 * 3600);
        // Danach neue Frage: die alte geparkte Zeile ist abgeloest und bleibt liegen.
        RecConversationTopic::create([
            'team_id' => self::TEAM, 'comms_whatsapp_thread_id' => (int) $thread->id,
            'state' => RecConversationTopic::STATE_ASKED, 'asked_at' => date('Y-m-d H:i:s'),
        ]);
        [, $aktuell] = $this->geparkt(vorSekunden: 2 * 3600);

        $zeilen = LohnParkenFreigeben::kandidaten();

        $this->assertSame([(int) $aktuell->id], $zeilen->pluck('id')->map(fn ($id) => (int) $id)->all());
        $this->assertNotSame((int) $alt->id, (int) $aktuell->id);
    }

    // ------------------------------------------------------------ Review 26.10.

    public function test_hr_antwort_beendet_das_parken_kein_bot_ins_gespraech(): void
    {
        [$thread, $topic] = $this->geparkt(vorSekunden: 2 * 3600);
        $this->hrAntwort($thread, vorSekunden: 3600);

        $status = $this->parken()->beiEingang($this->kanal, $thread, $this->getippt($thread));

        $this->assertSame('freigegeben:hr_hat_geantwortet', $status);
        $this->assertFalse(LohnParken::antwortet($status));
        $this->assertCount(0, $this->gesendet);
        $this->assertSame(RecConversationTopic::STATE_FREIGEGEBEN, $topic->fresh()->state);
    }

    public function test_auto_antwort_zaehlt_nicht_als_hr_antwort(): void
    {
        [$thread] = $this->geparkt(vorSekunden: 2 * 3600);
        $this->hrAntwort($thread, vorSekunden: 3600, auto: true);

        $this->assertSame(LohnParken::ERINNERT, $this->parken()->beiEingang($this->kanal, $thread, $this->getippt($thread)));
    }

    public function test_lauf_hr_hat_geantwortet_gibt_frei_ohne_dank_und_ohne_ungelesen(): void
    {
        $this->luecken = [];
        [$thread, $topic] = $this->geparkt(vorSekunden: 2 * 3600);
        $this->hrAntwort($thread, vorSekunden: 3600);
        $thread->forceFill(['is_unread' => false])->save();

        $this->assertSame('freigegeben:hr_hat_geantwortet', $this->parken()->freigebenWennVollstaendig($topic));
        $this->assertCount(0, $this->gesendet);
        $this->assertFalse((bool) $thread->fresh()->is_unread);
    }

    public function test_lauf_freigabe_macht_den_chat_wieder_ungelesen(): void
    {
        // HR hat den Chat im Filter „Wartet auf Akte“ geoeffnet (gelesen) oder abgehakt.
        $this->luecken = [];
        [$thread, $topic] = $this->geparkt(vorSekunden: 2 * 3600);
        $thread->forceFill(['is_unread' => false])->save();
        Capsule::table('rec_conversation_handled')->insert([
            'team_id' => self::TEAM, 'comms_whatsapp_thread_id' => (int) $thread->id,
            'handled_at' => date('Y-m-d H:i:s'), 'handled_reason' => 'manual',
            'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
        ]);

        $this->assertSame(LohnParken::FREIGEGEBEN, $this->parken()->freigebenWennVollstaendig($topic));
        $this->assertTrue((bool) $thread->fresh()->is_unread);
        $this->assertSame(0, Capsule::table('rec_conversation_handled')->count());
    }

    public function test_erinnerung_scheitert_dann_darf_die_abwesenheitsnotiz_laufen(): void
    {
        $this->sendStatus = ['failed'];
        [$thread] = $this->geparkt(vorSekunden: 2 * 3600);

        $status = $this->parken()->beiEingang($this->kanal, $thread, $this->getippt($thread));

        $this->assertSame('ignoriert:send_failed', $status);
        $this->assertFalse(LohnParken::antwortet($status));
    }

    public function test_kommando_ueber_zwei_teams(): void
    {
        [, $hier] = $this->geparkt(vorSekunden: 2 * 3600);
        [, $dort] = $this->geparkt(vorSekunden: 2 * 3600, team: 952);

        $ids = LohnParkenFreigeben::kandidaten()->pluck('id')->map(fn ($id) => (int) $id)->all();

        $this->assertSame([(int) $hier->id, (int) $dort->id], $ids);
    }

    private function hrAntwort(CommsWhatsAppThread $thread, int $vorSekunden, bool $auto = false): void
    {
        $zeit = date('Y-m-d H:i:s', time() - $vorSekunden);
        Capsule::table('comms_whatsapp_messages')->insert([
            'comms_whatsapp_thread_id' => $thread->id, 'direction' => 'outbound', 'is_auto_reply' => $auto,
            'body' => 'Wir schauen nach.', 'message_type' => 'text', 'sent_at' => $zeit, 'created_at' => $zeit, 'updated_at' => $zeit,
        ]);
    }

    // ------------------------------------------------------------ Hilfen

    private function parken(): LohnParken
    {
        $gesendet = $this->gesendet;
        $meta = new class($gesendet, $this->sendStatus) extends WhatsAppMetaService {
            private int $n = 0;

            public function __construct(private \ArrayObject $calls, private array $status) {}

            public function sendInteractive(CommsChannel $channel, string $to, array $interactive, string $displayBody, ?\Platform\Core\Models\User $sender = null, bool $isAutoReply = false): CommsWhatsAppMessage
            {
                $s = array_shift($this->status) ?? 'sent';
                $this->calls[] = compact('to', 'interactive', 'displayBody', 'isAutoReply');
                $m = new CommsWhatsAppMessage();
                $m->id = 900 + (++$this->n);
                $m->status = $s;
                $m->meta_payload = [];

                return $m;
            }
        };
        $resolver = new class($this->maId) extends EmployeeSenderResolver {
            public function __construct(private ?int $id) {}

            public function resolve(string $senderPhone, int $teamId): array
            {
                return $this->id === null
                    ? ['status' => self::NONE, 'employee_id' => null, 'employee_ids' => []]
                    : ['status' => self::EMPLOYEE, 'employee_id' => $this->id, 'employee_ids' => [$this->id]];
            }
        };
        $luecken = new class($this->luecken) extends LohnLuecken {
            public function __construct(private array $l) {}

            public function fuer(RecEmployee $employee): array
            {
                return $this->l;
            }
        };

        return new LohnParken(new InteraktivSender($meta), new ThemenAuswahlEmpfaenger($resolver), $luecken);
    }

    /** @return array{0: CommsWhatsAppThread, 1: RecConversationTopic} */
    private function geparkt(int $vorSekunden, string $state = RecConversationTopic::STATE_GEPARKT, int $team = self::TEAM): array
    {
        $thread = CommsWhatsAppThread::create([
            'team_id' => $team, 'comms_channel_id' => self::HR_KANAL, 'token' => bin2hex(random_bytes(8)),
            'remote_phone_number' => '+4915170000' . random_int(100, 999), 'is_unread' => true,
            'last_inbound_at' => date('Y-m-d H:i:s', time() - 5),
        ]);
        $zeit = date('Y-m-d H:i:s', time() - $vorSekunden);
        $id = (int) Capsule::table('rec_conversation_topics')->insertGetId([
            'team_id' => $team, 'comms_whatsapp_thread_id' => (int) $thread->id,
            'topic' => RecConversationTopic::TOPIC_LOHN, 'state' => $state,
            'asked_at' => $zeit, 'chosen_at' => $zeit, 'created_at' => $zeit, 'updated_at' => $zeit,
        ]);

        return [$thread, RecConversationTopic::query()->findOrFail($id)];
    }

    private function getippt(CommsWhatsAppThread $thread): CommsWhatsAppMessage
    {
        return $this->nachricht($thread, ['type' => 'text']);
    }

    private function nachricht(CommsWhatsAppThread $thread, array $payload): CommsWhatsAppMessage
    {
        return CommsWhatsAppMessage::create([
            'comms_whatsapp_thread_id' => $thread->id, 'direction' => 'inbound', 'is_auto_reply' => false,
            'body' => 'Wo ist mein Geld?', 'message_type' => 'text', 'meta_payload' => $payload,
            'sent_at' => date('Y-m-d H:i:s'),
        ]);
    }
}
