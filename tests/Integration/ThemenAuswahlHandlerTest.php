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
use Platform\Crm\Models\CrmContact;
use Platform\Crm\Services\Comms\WhatsAppMetaService;
use Platform\Integrations\Models\IntegrationsWhatsAppTemplate;
use Platform\Recruiting\Models\RecConversationTopic;
use Platform\Recruiting\Models\RecEmployee;
use Platform\Recruiting\Services\Comms\EmployeeSenderResolver;
use Platform\Recruiting\Services\Comms\HoldingTemplateSender;
use Platform\Recruiting\Services\Comms\InteraktivSender;
use Platform\Recruiting\Services\Comms\OooAutoReplyHandler;
use Platform\Recruiting\Services\Comms\ThemenAuswahlEmpfaenger;
use Platform\Recruiting\Services\Comms\ThemenAuswahlHandler;
use Psr\Log\NullLogger;

/**
 * Themen-Auswahl (S-Themen §2.1/§2.2): wann die Frage „Worum geht es?“ an einen
 * Mitarbeiter rausgeht, und dass bei aktiver Abwesenheit nur EINE Nachricht
 * entsteht. Versand ueber eine Meta-Attrappe, Empfaenger ueber eine
 * Resolver-Attrappe — Schema echt (Capsule + Migrationen).
 */
final class ThemenAuswahlHandlerTest extends TestCase
{
    private const TEAM = 947;

    private static Capsule $capsule;
    private static int $hrKanalId = 0;
    private static int $dispoKanalId = 0;
    private static int $kontoId = 0;
    private static int $vorlageId = 0;

    /** Aufgezeichnete sendInteractive-Aufrufe. */
    private \ArrayObject $gesendet;
    private string $sendStatus = 'sent';
    private ?int $mitarbeiterId = 42;
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
        $integrations = dirname((new \ReflectionClass(IntegrationsWhatsAppTemplate::class))->getFileName(), 3);
        foreach ([
            $own . '/database/migrations/2026_02_09_000008_create_rec_applicant_settings_table.php',
            $own . '/database/migrations/2026_10_17_000001_create_rec_conversation_topics_table.php',
            $crm . '/database/migrations/2026_01_14_000003_create_comms_channels_table.php',
            $crm . '/database/migrations/2026_02_12_100001_create_comms_whatsapp_threads_table.php',
            $crm . '/database/migrations/2026_02_12_100002_create_comms_whatsapp_messages_table.php',
            $crm . '/database/migrations/2026_07_10_000001_add_is_auto_reply_to_comms_whatsapp_messages.php',
            $integrations . '/database/migrations/2026_01_17_150000_create_integrations_whatsapp_accounts_table.php',
            // OOO-Vorlagenweg + Kontakt-Sperre (Review Task 3, I1/M3).
            $integrations . '/database/migrations/2026_02_12_000001_create_integrations_whatsapp_templates_table.php',
            $crm . '/database/migrations/2026_03_23_000001_create_comms_logs_table.php',
            $crm . '/database/migrations/2024_01_01_000009_create_crm_contact_statuses_table.php',
            $crm . '/database/migrations/2024_01_01_000016_create_crm_contacts_table.php',
            $crm . '/database/migrations/2026_03_19_000001_add_is_blacklisted_to_crm_contacts_table.php',
            $crm . '/database/migrations/2024_01_01_000014_create_crm_phone_numbers_table.php',
        ] as $path) {
            (require $path)->up();
        }

        // Pivot von Hand: die CRM-Migration kopiert Altbestand aus Tabellen, die hier fehlen.
        Capsule::schema()->create('comms_thread_contexts', function (Blueprint $table) {
            $table->id();
            $table->string('thread_type');
            $table->unsignedBigInteger('thread_id');
            $table->string('context_model');
            $table->unsignedBigInteger('context_model_id');
            $table->string('source')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        self::$kontoId = (int) Capsule::table('integrations_whatsapp_accounts')->insertGetId([
            'uuid' => 'acc-themen', 'phone_number' => '+49 160 5559001',
            'title' => 'HR', 'active' => true, 'user_id' => 1,
        ]);
        self::$vorlageId = (int) Capsule::table('integrations_whatsapp_templates')->insertGetId([
            'uuid' => 'tpl-ooo-themen', 'external_id' => 'ext-ooo-themen', 'name' => 'hr_abwesend', 'language' => 'de',
            'status' => 'APPROVED', 'category' => 'UTILITY',
            'components' => json_encode([['type' => 'BODY', 'text' => 'Wir sind gerade nicht erreichbar.']]),
            'whatsapp_account_id' => self::$kontoId, 'user_id' => 1,
        ]);
        self::$hrKanalId = (int) Capsule::table('comms_channels')->insertGetId([
            'team_id' => self::TEAM, 'type' => 'whatsapp', 'provider' => 'whatsapp_meta',
            'sender_identifier' => '+49 160 5559001', 'is_active' => true,
            'meta' => json_encode(['integrations_whatsapp_account_id' => self::$kontoId]),
        ]);
        // Dispo-Kanal: eigenes Konto, gehoert NICHT zum HR-Set.
        self::$dispoKanalId = (int) Capsule::table('comms_channels')->insertGetId([
            'team_id' => self::TEAM, 'type' => 'whatsapp', 'provider' => 'whatsapp_meta',
            'sender_identifier' => '+49 160 5559002', 'is_active' => true,
            'meta' => json_encode(['integrations_whatsapp_account_id' => 9999]),
        ]);
    }

    public static function tearDownAfterClass(): void
    {
        Container::getInstance()->forgetInstance('config');
        Container::getInstance()->forgetInstance(\Illuminate\Contracts\Auth\Factory::class);
        Facade::clearResolvedInstances();
    }

    private static function bindeContainer(): void
    {
        $c = Container::getInstance();
        $c->instance('db', self::$capsule->getDatabaseManager());
        $c->instance('db.schema', self::$capsule->getConnection()->getSchemaBuilder());
        // Log-Attrappe VOR clearResolvedInstances (reference_log_facade_test_stub.md).
        $c->instance('log', new Logger(new NullLogger()));
        // LogsActivity (Activity-Log-Trait an CRM-Modellen) liest die Config.
        $c->instance('config', new \Illuminate\Config\Repository(['activity-log' => ['events' => []]]));
        // HoldingTemplateSender reicht auth()->user() als Absender durch.
        $c->instance(\Illuminate\Contracts\Auth\Factory::class, new class implements \Illuminate\Contracts\Auth\Factory {
            public function guard($name = null)
            {
                return $this;
            }

            public function shouldUse($name)
            {
            }

            public function user(): mixed
            {
                return null;
            }
        });
        Facade::setFacadeApplication($c);
        Facade::clearResolvedInstances();
    }

    protected function setUp(): void
    {
        self::bindeContainer();
        foreach (['rec_conversation_topics', 'comms_whatsapp_messages', 'comms_whatsapp_threads', 'comms_thread_contexts',
                  'comms_logs', 'crm_contacts', 'crm_phone_numbers'] as $t) {
            Capsule::table($t)->delete();
        }
        $this->gesendet = new \ArrayObject();
        $this->sendStatus = 'sent';
        $this->mitarbeiterId = 42;
        $this->einstellungen(['comms_topic_enabled' => true, 'use_informal_address' => true]);
        $this->kanal = CommsChannel::query()->findOrFail(self::$hrKanalId);
    }

    public function test_erster_eingang_eines_aktiven_mitarbeiters_bekommt_die_themen_frage(): void
    {
        [$thread, $msg] = $this->eingang();

        $status = $this->handler()->beiEingang($this->kanal, $thread, $msg);

        $this->assertSame('gesendet', $status);
        $this->assertCount(1, $this->gesendet);
        $knoepfe = $this->gesendet[0]['interactive']['action']['buttons'];
        $this->assertSame(['topic_dispo', 'topic_lohn', 'topic_hr'], array_map(fn ($k) => $k['reply']['id'], $knoepfe));
        $this->assertTrue($this->gesendet[0]['isAutoReply']);
        $this->assertSame((string) $thread->remote_phone_number, $this->gesendet[0]['to']);

        $zeile = RecConversationTopic::query()->sole();
        $this->assertSame(RecConversationTopic::STATE_ASKED, $zeile->state);
        $this->assertNull($zeile->topic);
        $this->assertSame(self::TEAM, (int) $zeile->team_id);
        $this->assertSame((int) $thread->id, (int) $zeile->comms_whatsapp_thread_id);
        $this->assertSame((int) $msg->id, (int) $zeile->origin_message_id);
        $this->assertSame(777, (int) $zeile->ask_message_id);
        $this->assertNotNull($zeile->asked_at);
    }

    public function test_antwort_innerhalb_der_ruhezeit_loest_nichts_aus(): void
    {
        $thread = $this->thread();
        // Unsere Erinnerung vor 2h — auch eine Auto-Antwort zaehlt als Aktivitaet.
        $this->nachricht($thread, 'outbound', -2 * 3600, autoReply: true);
        $msg = $this->nachricht($thread, 'inbound', -5);

        $this->assertSame('uebersprungen:ruhezeit', $this->handler()->beiEingang($this->kanal, $thread, $msg));
        $this->assertCount(0, $this->gesendet);
        $this->assertSame(0, RecConversationTopic::query()->count());
    }

    public function test_aktivitaet_vor_mehr_als_der_ruhezeit_loest_aus(): void
    {
        $thread = $this->thread();
        $this->nachricht($thread, 'outbound', -9 * 3600);
        $msg = $this->nachricht($thread, 'inbound', -5);

        $this->assertSame('gesendet', $this->handler()->beiEingang($this->kanal, $thread, $msg));
    }

    public function test_ruhezeit_kommt_aus_der_team_einstellung(): void
    {
        $this->einstellungen(['comms_topic_enabled' => true, 'comms_topic_quiet_hours' => 1]);
        $thread = $this->thread();
        $this->nachricht($thread, 'outbound', -2 * 3600);
        $msg = $this->nachricht($thread, 'inbound', -5);

        $this->assertSame('gesendet', $this->handler()->beiEingang($this->kanal, $thread, $msg));
    }

    public function test_bewerber_loest_nicht_aus(): void
    {
        $this->mitarbeiterId = null;
        [$thread, $msg] = $this->eingang();

        $this->assertSame('uebersprungen:kein_aktiver_ma', $this->handler()->beiEingang($this->kanal, $thread, $msg));
        $this->assertCount(0, $this->gesendet);
    }

    public function test_dispo_kanal_loest_nicht_aus(): void
    {
        [$thread, $msg] = $this->eingang();
        $dispo = CommsChannel::query()->findOrFail(self::$dispoKanalId);

        $this->assertSame('uebersprungen:kein_hr_kanal', $this->handler()->beiEingang($dispo, $thread, $msg));
    }

    public function test_button_klick_loest_nicht_aus(): void
    {
        [$thread, $msg] = $this->eingang(payload: ['type' => 'interactive', 'interactive' => ['type' => 'button_reply', 'button_reply' => ['id' => 'topic_hr']]]);

        $this->assertSame('uebersprungen:button_klick', $this->handler()->beiEingang($this->kanal, $thread, $msg));
    }

    public function test_sprachnachricht_loest_nicht_aus(): void
    {
        [$thread, $msg] = $this->eingang(typ: 'audio');

        $this->assertSame('uebersprungen:sprachnachricht', $this->handler()->beiEingang($this->kanal, $thread, $msg));
    }

    public function test_schalter_aus_loest_nicht_aus(): void
    {
        $this->einstellungen(['comms_topic_enabled' => false]);
        [$thread, $msg] = $this->eingang();

        $this->assertSame('uebersprungen:schalter_aus', $this->handler()->beiEingang($this->kanal, $thread, $msg));
        $this->assertCount(0, $this->gesendet);
    }

    public function test_fremder_kontext_loest_nicht_aus(): void
    {
        [$thread, $msg] = $this->eingang();
        $thread->update(['context_model' => 'Platform\\Helpdesk\\Models\\HelpdeskTicket', 'context_model_id' => 5]);

        $this->assertSame('uebersprungen:fremder_kontext', $this->handler()->beiEingang($this->kanal, $thread->fresh(), $msg));
    }

    public function test_thread_am_mitarbeiter_loest_aus(): void
    {
        // Der Listener haengt Mitarbeiter-Threads an RecEmployee — das ist
        // kein fremder Fachprozess, sonst bekaeme ein bekannter MA nie die Frage.
        [$thread, $msg] = $this->eingang();
        $thread->update(['context_model' => RecEmployee::class, 'context_model_id' => 42]);
        Capsule::table('comms_thread_contexts')->insert([
            'thread_type' => CommsWhatsAppThread::class, 'thread_id' => $thread->id,
            'context_model' => RecEmployee::class, 'context_model_id' => 42,
        ]);

        $this->assertSame('gesendet', $this->handler()->beiEingang($this->kanal, $thread->fresh(), $msg));
    }

    public function test_bild_loest_aus(): void
    {
        [$thread, $msg] = $this->eingang(typ: 'image');

        $this->assertSame('gesendet', $this->handler()->beiEingang($this->kanal, $thread, $msg));
    }

    public function test_wiederholter_webhook_fragt_nicht_doppelt(): void
    {
        // Meta wiederholt Webhooks, der Core feuert das Event auch fuer die Dublette.
        [$thread, $msg] = $this->eingang();
        $this->handler()->beiEingang($this->kanal, $thread, $msg);

        $this->assertSame('uebersprungen:schon_gefragt', $this->handler()->beiEingang($this->kanal, $thread, $msg));
        $this->assertCount(1, $this->gesendet);
        $this->assertSame(1, RecConversationTopic::query()->count());
    }

    public function test_abwesenheit_aktiv_ergibt_eine_kombinierte_nachricht(): void
    {
        $tag = fn (int $d) => date('Y-m-d', strtotime("{$d} days"));
        $this->einstellungen([
            'comms_topic_enabled' => true,
            'use_informal_address' => true,
            'comms_ooo_enabled' => true,
            'comms_ooo_from' => $tag(-1),
            'comms_ooo_until' => $tag(1),
            'comms_ooo_back_at' => $tag(2),
        ]);
        [$thread, $msg] = $this->eingang();

        $this->assertSame('gesendet', $this->handler()->beiEingang($this->kanal, $thread, $msg));

        $this->assertCount(1, $this->gesendet, 'Nur EINE Nachricht — Abwesenheit steht in der Themen-Frage.');
        $body = $this->gesendet[0]['interactive']['body']['text'];
        $vorspann = sprintf(
            'Wir sind vom %s bis %s nicht erreichbar und ab %s wieder da.',
            date('d.m.', strtotime('-1 days')),
            date('d.m.', strtotime('1 days')),
            date('d.m.', strtotime('2 days')),
        );
        $this->assertStringStartsWith($vorspann . "\n\n", $body);
        $this->assertStringContainsString('Worum geht es?', $body);
        $this->assertCount(3, $this->gesendet[0]['interactive']['action']['buttons']);
    }

    public function test_vorspann_ist_null_ohne_aktive_abwesenheit(): void
    {
        $this->einstellungen(['comms_ooo_enabled' => false, 'comms_ooo_from' => '2026-01-01', 'comms_ooo_back_at' => '2099-01-01']);

        $this->assertNull($this->ooo()->vorspann(self::TEAM));
    }

    public function test_vorspann_ohne_bis_datum_laesst_den_teil_weg(): void
    {
        $this->einstellungen([
            'comms_ooo_enabled' => true,
            'comms_ooo_from' => date('Y-m-d', strtotime('-1 days')),
            'comms_ooo_back_at' => date('Y-m-d', strtotime('3 days')),
        ]);

        $this->assertSame(
            'Wir sind derzeit nicht erreichbar und ab ' . date('d.m.', strtotime('3 days')) . ' wieder da.',
            $this->ooo()->vorspann(self::TEAM),
        );
    }

    public function test_meta_lehnt_ab_dann_keine_topic_zeile(): void
    {
        $this->sendStatus = 'failed';
        [$thread, $msg] = $this->eingang();

        $this->assertSame('uebersprungen:send_failed', $this->handler()->beiEingang($this->kanal, $thread, $msg));
        $this->assertSame(0, RecConversationTopic::query()->count());
    }

    public function test_sie_form_wenn_team_nicht_duzt(): void
    {
        $this->einstellungen(['comms_topic_enabled' => true, 'use_informal_address' => false]);
        [$thread, $msg] = $this->eingang();

        $this->handler()->beiEingang($this->kanal, $thread, $msg);

        $body = $this->gesendet[0]['interactive']['body']['text'];
        $this->assertStringContainsString('Ihre Nachricht', $body);
        $this->assertStringNotContainsString('deine', $body);
    }

    public function test_listener_fragt_vor_dem_ooo_handler_und_ueberspringt_ihn_nach_dem_senden(): void
    {
        // Der Listener braucht den vollen App-Boot; der Vertrag wird an der Quelle geprueft
        // (Muster QuickReplyStamperTest::test_inbound_listener_ruft_den_stamper_auf).
        $quelle = file_get_contents(dirname(__DIR__, 2) . '/src/Listeners/HandleWhatsAppInboundForRecruiting.php');

        $this->assertMatchesRegularExpression('/app\(ThemenAuswahlHandler::class\)->beiEingang\(\$channel, \$thread, \$message\)/', $quelle);
        $this->assertLessThan(strpos($quelle, '$this->oooAutoReply->handle('), strpos($quelle, 'ThemenAuswahlHandler::class)->beiEingang'));
        $this->assertMatchesRegularExpression(
            '/if \(!ThemenAuswahlHandler::abwesenheitMitgeteilt\(\$themenStatus\)\)\s*\{\s*try\s*\{\s*\$this->oooAutoReply->handle\(/',
            $quelle,
        );
        $this->assertTrue(ThemenAuswahlHandler::abwesenheitMitgeteilt('gesendet'));
        $this->assertTrue(ThemenAuswahlHandler::abwesenheitMitgeteilt('uebersprungen:schon_gefragt'));
        $this->assertFalse(ThemenAuswahlHandler::abwesenheitMitgeteilt('uebersprungen:send_failed'));
        $this->assertFalse(ThemenAuswahlHandler::abwesenheitMitgeteilt('uebersprungen:ruhezeit'));
        // Ein Fehler in der Themen-Auswahl darf den Eingang nie stoppen.
        $this->assertMatchesRegularExpression(
            '/try\s*\{\s*\$themenStatus = app\(ThemenAuswahlHandler::class\)->beiEingang\(\$channel, \$thread, \$message\);\s*\}\s*catch \(\\\\Throwable \$e\)/',
            $quelle,
        );
    }

    // ------------------------------------------------ Review Task 3 (Fix-Runde 1)

    public function test_fruehere_eingehende_nachricht_innerhalb_der_ruhezeit_loest_nichts_aus(): void
    {
        // „In keine Richtung“: auch ein eigener Eingang vor 2h ist Aktivitaet.
        $thread = $this->thread();
        $this->nachricht($thread, 'inbound', -2 * 3600);
        $msg = $this->nachricht($thread, 'inbound', -5);

        $this->assertSame('uebersprungen:ruhezeit', $this->handler()->beiEingang($this->kanal, $thread, $msg));
    }

    public function test_doppelter_webhook_bei_aktiver_abwesenheit_ergibt_genau_einen_send(): void
    {
        $this->abwesenheitAn();
        [$thread, $msg] = $this->eingang();

        $this->wieImListener($thread, $msg);
        $this->wieImListener($thread, $msg);

        $this->assertCount(1, $this->gesendet, 'Die Wiederholung darf weder fragen noch die OOO-Vorlage schicken.');
        $this->assertArrayHasKey('interactive', $this->gesendet[0]);
    }

    public function test_zweite_nachricht_in_der_ruhezeit_bei_aktiver_abwesenheit_schickt_keine_ooo_vorlage(): void
    {
        $this->abwesenheitAn();
        $thread = $this->thread();
        $erste = $this->nachricht($thread, 'inbound', -120);
        $this->assertSame('gesendet', $this->wieImListener($thread, $erste));
        $zweite = $this->nachricht($thread, 'inbound', -5);

        $this->assertSame('uebersprungen:ruhezeit', $this->wieImListener($thread, $zweite));
        $this->assertCount(1, $this->gesendet, 'Die Abwesenheit stand schon in der Themen-Frage.');
    }

    public function test_ohne_themen_frage_schickt_der_ooo_handler_wie_bisher(): void
    {
        // Gegenprobe: ohne Frage im Thread geht die Vorlage raus — sonst
        // waeren die beiden Tests darueber auch ohne Fix gruen.
        $this->abwesenheitAn();
        $this->mitarbeiterId = null;
        [$thread, $msg] = $this->eingang();

        $this->assertSame('uebersprungen:kein_aktiver_ma', $this->wieImListener($thread, $msg));
        $this->assertCount(1, $this->gesendet);
        $this->assertSame('hr_abwesend', $this->gesendet[0]['vorlage']);
    }

    public function test_geblacklisteter_kontakt_bekommt_keine_themen_frage(): void
    {
        [$thread, $msg] = $this->eingang();
        $kontakt = (int) Capsule::table('crm_contacts')->insertGetId([
            'uuid' => 'k-gesperrt', 'first_name' => 'Gesperrt', 'last_name' => 'Kontakt',
            'team_id' => self::TEAM, 'is_blacklisted' => true,
        ]);
        Capsule::table('crm_phone_numbers')->insert([
            'uuid' => 'p-gesperrt', 'phoneable_type' => (new CrmContact())->getMorphClass(), 'phoneable_id' => $kontakt,
            'international' => (string) $thread->remote_phone_number, 'phone_type_id' => 1,
        ]);

        $this->assertSame('uebersprungen:kontakt_gesperrt', $this->handler()->beiEingang($this->kanal, $thread, $msg));
        $this->assertCount(0, $this->gesendet);
    }

    public function test_zeile_nicht_anlegbar_gilt_trotzdem_als_gesendet(): void
    {
        [$thread, $msg] = $this->eingang();
        RecConversationTopic::creating(function () {
            throw new \RuntimeException('DB weg');
        });
        try {
            $status = $this->handler()->beiEingang($this->kanal, $thread, $msg);
        } finally {
            RecConversationTopic::flushEventListeners();
        }

        $this->assertSame('gesendet', $status, 'Die Frage ist draussen — sonst kaeme die OOO-Vorlage hinterher.');
        $this->assertCount(1, $this->gesendet);
    }

    public function test_schalter_aus_legt_keine_einstellungen_an(): void
    {
        Capsule::table('rec_applicant_settings')->where('team_id', self::TEAM)->delete();
        [$thread, $msg] = $this->eingang();

        $this->assertSame('uebersprungen:schalter_aus', $this->handler()->beiEingang($this->kanal, $thread, $msg));
        $this->assertSame(0, Capsule::table('rec_applicant_settings')->where('team_id', self::TEAM)->count());
    }

    public function test_personen_suche_laeuft_erst_nach_den_billigen_pruefungen(): void
    {
        // Button-Klick: die teure Suche darf gar nicht erst laufen.
        $this->mitarbeiterId = -1; // Signal: Suche verboten
        [$thread, $msg] = $this->eingang(payload: ['type' => 'button', 'button' => ['text' => 'Ja']]);

        $this->assertSame('uebersprungen:button_klick', $this->handler()->beiEingang($this->kanal, $thread, $msg));
    }

    /** Ablauf wie im Listener: erst Themen-Auswahl, OOO nur, wenn die Abwesenheit noch nicht mitgeteilt ist. */
    private function wieImListener(CommsWhatsAppThread $thread, CommsWhatsAppMessage $msg): string
    {
        $status = $this->handler()->beiEingang($this->kanal, $thread, $msg);
        if (!ThemenAuswahlHandler::abwesenheitMitgeteilt($status)) {
            $this->ooo()->handle($this->kanal, $thread->fresh(), $msg);
        }

        return $status;
    }

    private function abwesenheitAn(): void
    {
        $this->einstellungen([
            'comms_topic_enabled' => true,
            'use_informal_address' => true,
            'comms_ooo_enabled' => true,
            'comms_ooo_template_id' => self::$vorlageId,
            'comms_ooo_from' => date('Y-m-d', strtotime('-1 days')),
            'comms_ooo_until' => date('Y-m-d', strtotime('1 days')),
            'comms_ooo_back_at' => date('Y-m-d', strtotime('2 days')),
        ]);
    }

    // ---------------------------------------------------------------- Hilfen

    private function handler(): ThemenAuswahlHandler
    {
        $gesendet = $this->gesendet;
        $status = $this->sendStatus;
        $meta = new class($gesendet, $status) extends WhatsAppMetaService {
            public function __construct(private \ArrayObject $calls, private string $status) {}

            public function sendInteractive(CommsChannel $channel, string $to, array $interactive, string $displayBody, ?\Platform\Core\Models\User $sender = null, bool $isAutoReply = false): CommsWhatsAppMessage
            {
                $this->calls[] = compact('to', 'interactive', 'displayBody', 'isAutoReply');
                $m = new CommsWhatsAppMessage();
                $m->id = 777;
                $m->status = $this->status;
                $m->meta_payload = [];

                return $m;
            }
        };

        $id = $this->mitarbeiterId;
        $resolver = new class($id) extends EmployeeSenderResolver {
            public function __construct(private ?int $id) {}

            public function resolve(string $senderPhone, int $teamId): array
            {
                if ($this->id === -1) {
                    throw new \LogicException('Personen-Suche haette nicht laufen duerfen.');
                }

                return $this->id === null
                    ? ['status' => self::NONE, 'employee_id' => null, 'employee_ids' => []]
                    : ['status' => self::EMPLOYEE, 'employee_id' => $this->id, 'employee_ids' => [$this->id]];
            }
        };

        return new ThemenAuswahlHandler(new InteraktivSender($meta), new ThemenAuswahlEmpfaenger($resolver), $this->ooo());
    }

    private function ooo(): OooAutoReplyHandler
    {
        // Vorlagen-Sends landen in derselben Aufzeichnung wie die Themen-Frage:
        // so zaehlt ein Test „genau eine Nachricht“ ueber beide Wege.
        $meta = new class($this->gesendet) extends WhatsAppMetaService {
            public function __construct(private \ArrayObject $calls) {}

            public function sendTemplate(CommsChannel $channel, string $to, string $templateName, array $components = [], string $languageCode = 'de', ?\Platform\Core\Models\User $sender = null, bool $isAutoReply = false): CommsWhatsAppMessage
            {
                $this->calls[] = ['vorlage' => $templateName, 'to' => $to];

                return new CommsWhatsAppMessage();
            }
        };

        return new OooAutoReplyHandler(new HoldingTemplateSender($meta));
    }

    /** Team-Einstellungen; auto_pilot_wa_account_id ist der Anker des HR-Kanal-Sets. */
    private function einstellungen(array $weitere): void
    {
        Capsule::table('rec_applicant_settings')->where('team_id', self::TEAM)->delete();
        Capsule::table('rec_applicant_settings')->insert([
            'team_id' => self::TEAM,
            'settings' => json_encode(array_merge(['auto_pilot_wa_account_id' => self::$kontoId], $weitere)),
        ]);
    }

    /** @return array{0: CommsWhatsAppThread, 1: CommsWhatsAppMessage} */
    private function eingang(string $typ = 'text', ?array $payload = null): array
    {
        $thread = $this->thread();

        return [$thread, $this->nachricht($thread, 'inbound', -5, typ: $typ, payload: $payload ?? ['type' => $typ])];
    }

    private function thread(): CommsWhatsAppThread
    {
        return CommsWhatsAppThread::create([
            'team_id' => self::TEAM,
            'comms_channel_id' => self::$hrKanalId,
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
        string $typ = 'text',
        ?array $payload = null,
    ): CommsWhatsAppMessage {
        return CommsWhatsAppMessage::create([
            'comms_whatsapp_thread_id' => $thread->id,
            'direction' => $direction,
            'is_auto_reply' => $autoReply,
            'body' => 'Hallo',
            'message_type' => $typ,
            'meta_payload' => $payload,
            'sent_at' => date('Y-m-d H:i:s', time() + $sekundenRelativ),
        ]);
    }
}
