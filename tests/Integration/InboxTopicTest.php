<?php

namespace Platform\Recruiting\Tests\Integration;

use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use Platform\Crm\Models\CommsChannel;
use Platform\Crm\Models\CommsWhatsAppThread;
use Platform\Integrations\Models\IntegrationsWhatsAppTemplate;
use Platform\Recruiting\Models\RecApplicant;
use Platform\Recruiting\Models\RecApplicantSettings;
use Platform\Recruiting\Models\RecConversationTopic;
use Platform\Recruiting\Models\RecEmployee;
use Platform\Recruiting\Services\Comms\Forward\DispoForwardChannels;
use Platform\Recruiting\Services\Comms\InboxFilter;
use Platform\Recruiting\Services\Comms\InboxQuery;
use Platform\Recruiting\Services\Comms\InboxRow;

/**
 * Themen-Auswahl in der HR-Kommunikation (Spec 2.8): Chip „Lohn“/„HR“/
 * „Dispo → Köln“, Vermerk bei verschicktem Hinweis, Filter nach Thema.
 * Massgeblich ist je Chat die JUENGSTE Zeile (Spec 2.7, Nachtrag 16.10.):
 * nur wenn sie ein Thema hat, gibt es einen Chip. Eine neuere, nie
 * beantwortete Frage loest eine aeltere Wahl ab; ohne neue Frage bleibt die
 * Wahl stehen, auch ueber die Ruhezeit hinaus.
 * Der Themen-Filter kombiniert sich mit der Filiale (UND); die Themen-Zahlen
 * folgen der Filiale wie die uebrigen Pillen, nicht der eigenen Auswahl.
 */
class InboxTopicTest extends TestCase
{
    private const TEAM = 906;
    private const JETZT = 1_757_930_000;

    private static int $dispoKanal = 0;
    private static int $lohnMgl = 0;       // Bewerbung Stelle 200, lohn + akte_hint_sent
    private static int $hrMgl = 0;         // Bewerbung Stelle 200, hr chosen
    private static int $dispoCgn = 0;      // MA Kostenstelle 401, dispo routed -> Koeln
    private static int $wechsel = 0;       // Bewerbung Stelle 400: erst lohn, spaeter hr
    private static int $nurGefragt = 0;    // alte Frage, nie geklickt
    private static int $ohneZeile = 0;
    private static int $loginHinweis = 0;  // MA ohne Kostenstelle, lohn + lohn_info_sent
    private static int $neueFrage = 0;     // gestern Lohn gewaehlt, vor 1h neue Frage (unbeantwortet)
    private static int $gesternHr = 0;     // gestern HR gewaehlt, seitdem keine neue Frage

    private static array $previousMorphMap = [];

    public static function setUpBeforeClass(): void
    {
        $container = Container::getInstance();
        // Support\Filialen liest config('recruiting.filialen') — Beschriftung der Dispo-Ziele.
        $container->instance('config', new ConfigRepository([
            'activity-log' => ['events' => []],
            'recruiting' => ['filialen' => [
                200 => ['code' => 'MGL', 'name' => 'Mönchengladbach'],
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

        self::$previousMorphMap = Relation::morphMap();
        Relation::morphMap(['rec_applicant' => RecApplicant::class]);

        $own = dirname(__DIR__, 2);
        $crm = dirname((new \ReflectionClass(CommsChannel::class))->getFileName(), 3);
        $integrations = dirname((new \ReflectionClass(IntegrationsWhatsAppTemplate::class))->getFileName(), 3);
        foreach ([
            [$own, '2026_02_09_000008_create_rec_applicant_settings_table.php'],
            [$own, '2026_09_15_000002_create_rec_conversation_handled_table.php'],
            [$own, '2026_10_17_000001_create_rec_conversation_topics_table.php'],
            [$own, '2026_08_24_000001_create_rec_dispo_filiale_settings_table.php'],
            [$own, '2026_02_09_000001_create_rec_positions_table.php'],
            [$own, '2026_05_21_000006_add_cost_center_to_rec_positions.php'],
            [$own, '2026_02_09_000005_create_rec_applicants_table.php'],
            [$own, '2026_08_18_000001_add_rec_position_id_to_rec_applicants.php'],
            [$own, '2026_05_20_000001_create_rec_employees_table.php'],
            [$own, '2026_07_07_000001_add_cost_center_to_rec_employees.php'],
            [$crm, '2026_01_14_000003_create_comms_channels_table.php'],
            [$crm, '2026_02_12_100001_create_comms_whatsapp_threads_table.php'],
            [$crm, '2026_02_12_100002_create_comms_whatsapp_messages_table.php'],
            [$crm, '2026_07_10_000001_add_is_auto_reply_to_comms_whatsapp_messages.php'],
            [$integrations, '2026_01_17_150000_create_integrations_whatsapp_accounts_table.php'],
            [$crm, '2024_01_01_000016_create_crm_contacts_table.php'],
            [$crm, '2024_01_01_000020_create_crm_contact_links_table.php'],
        ] as [$root, $file]) {
            (require $root . '/database/migrations/' . $file)->up();
        }

        self::seed();
    }

    public static function tearDownAfterClass(): void
    {
        Relation::morphMap(self::$previousMorphMap, false);
        Container::getInstance()->forgetInstance('config');
        Facade::clearResolvedInstances();
    }

    private function query(): InboxQuery
    {
        return new InboxQuery(new DispoForwardChannels([self::$dispoKanal]));
    }

    /** @return array<int, InboxRow> threadId => Zeile */
    private function zeilen(InboxFilter $filter = new InboxFilter()): array
    {
        $out = [];
        foreach ($this->query()->snapshot(self::TEAM, $filter, 50, 0, self::JETZT)['rows'] as $row) {
            $out[$row->threadId] = $row;
        }

        return $out;
    }

    public function test_lohn_mit_aktenhinweis_traegt_chip_und_vermerk(): void
    {
        $row = $this->zeilen()[self::$lohnMgl];

        $this->assertSame('lohn', $row->topic);
        $this->assertSame('Lohn', $row->topicLabel);
        $this->assertSame('Akte unvollständig · Hinweis gesendet', $row->topicNote);
    }

    public function test_login_hinweis_hat_eigenen_vermerk(): void
    {
        $row = $this->zeilen()[self::$loginHinweis];

        $this->assertSame('Lohn', $row->topicLabel);
        $this->assertSame('Login-Hinweis gesendet', $row->topicNote);
    }

    public function test_dispo_weitergeleitet_zeigt_die_filiale(): void
    {
        $row = $this->zeilen()[self::$dispoCgn];

        $this->assertSame('dispo', $row->topic);
        $this->assertSame('Dispo → Köln', $row->topicLabel);
        $this->assertNull($row->topicNote);
    }

    public function test_hr_und_juengstes_thema_gewinnt(): void
    {
        $zeilen = $this->zeilen();

        $this->assertSame('HR', $zeilen[self::$hrMgl]->topicLabel);
        $this->assertSame('hr', $zeilen[self::$wechsel]->topic, 'Die juengere Wahl (HR) loest die aeltere (Lohn) ab.');
        $this->assertNull($zeilen[self::$wechsel]->topicNote);
    }

    public function test_ohne_zeile_und_nie_geklickte_frage_haben_keinen_chip(): void
    {
        $zeilen = $this->zeilen();

        foreach ([self::$ohneZeile, self::$nurGefragt] as $id) {
            $this->assertNull($zeilen[$id]->topic);
            $this->assertNull($zeilen[$id]->topicLabel);
            $this->assertNull($zeilen[$id]->topicNote);
        }
    }

    public function test_neuere_unbeantwortete_frage_loest_die_aeltere_wahl_ab(): void
    {
        $row = $this->zeilen()[self::$neueFrage];

        $this->assertNull($row->topic);
        $this->assertNull($row->topicLabel);
        $this->assertNull($row->topicNote);
    }

    public function test_wahl_von_gestern_ohne_neue_frage_behaelt_den_chip(): void
    {
        // Kein Ablauf nach der Ruhezeit (Controller-Ruling 16.10.): ein offener
        // Chat soll sein Thema weiter zeigen.
        $this->assertSame('HR', $this->zeilen()[self::$gesternHr]->topicLabel);
    }

    public function test_filter_nach_thema(): void
    {
        $lohn = array_keys($this->zeilen(new InboxFilter(topic: 'lohn')));
        sort($lohn);
        $erwartet = [self::$lohnMgl, self::$loginHinweis];
        sort($erwartet);
        $this->assertSame($erwartet, $lohn);

        $this->assertSame([self::$dispoCgn], array_keys($this->zeilen(new InboxFilter(topic: 'dispo'))));

        // Dieselbe Regel wie der Chip: die abgeloeste Lohn-Wahl trifft nicht,
        // die HR-Wahl von gestern schon.
        $this->assertNotContains(self::$neueFrage, $lohn);
        $this->assertContains(self::$gesternHr, array_keys($this->zeilen(new InboxFilter(topic: 'hr'))));
    }

    public function test_thema_und_filiale_wirken_zusammen(): void
    {
        $this->assertSame([self::$lohnMgl], array_keys($this->zeilen(new InboxFilter(filiale: '200', topic: 'lohn'))));
        $this->assertSame([], array_keys($this->zeilen(new InboxFilter(filiale: '200', topic: 'dispo'))));
    }

    public function test_themen_zaehler_folgen_der_filiale_nicht_der_eigenen_auswahl(): void
    {
        $alle = $this->query()->snapshot(self::TEAM, new InboxFilter(), 50, 0, self::JETZT)['counts'];
        $this->assertSame(['dispo' => 1, 'lohn' => 2, 'hr' => 3], $alle['topics']);

        $nurLohn = $this->query()->snapshot(self::TEAM, new InboxFilter(topic: 'lohn'), 50, 0, self::JETZT);
        $this->assertSame(['dispo' => 1, 'lohn' => 2, 'hr' => 3], $nurLohn['counts']['topics']);
        $this->assertSame(9, $nurLohn['counts']['total'], 'Das Thema schneidet die uebrigen Zaehler nicht.');
        $this->assertSame(2, $nurLohn['total']);

        $mgl = $this->query()->snapshot(self::TEAM, new InboxFilter(filiale: '200'), 50, 0, self::JETZT)['counts'];
        $this->assertSame(['dispo' => 0, 'lohn' => 1, 'hr' => 1], $mgl['topics']);
    }

    public function test_kopfzeile_eines_einzelnen_chats_traegt_das_thema(): void
    {
        $row = $this->query()->rowForThread(CommsWhatsAppThread::findOrFail(self::$dispoCgn), self::TEAM, self::JETZT);

        $this->assertSame('Dispo → Köln', $row->topicLabel);
    }

    public function test_themen_kosten_genau_eine_abfrage_je_durchlauf(): void
    {
        // Liste pollt alle 20s: die Themen kommen gebuendelt, nicht je Zeile.
        // Ohne Dispo-Thema auf der Seite faellt auch die Kanal-Beschriftung weg.
        $n = 0;
        Capsule::connection()->listen(function ($q) use (&$n) {
            if (str_contains($q->sql, 'rec_conversation_topics')) {
                $n++;
            }
        });
        $this->query()->snapshot(self::TEAM, new InboxFilter(), 50, 0, self::JETZT);
        Capsule::connection()->getEventDispatcher()->forget(\Illuminate\Database\Events\QueryExecuted::class);

        $this->assertSame(1, $n);
    }

    public function test_ohne_tabelle_laeuft_die_liste_weiter_ohne_themen(): void
    {
        // Deploy ohne migrate (Forge-Skript reisst im npm-Teil): Sidebar und
        // Kommunikation duerfen nicht an der fehlenden Tabelle sterben.
        $schema = Capsule::schema();
        $gesichert = Capsule::table('rec_conversation_topics')->get()->map(fn ($r) => (array) $r)->all();
        $schema->drop('rec_conversation_topics');
        try {
            $counts = $this->query()->counts(self::TEAM, self::JETZT);
            $snap = $this->query()->snapshot(self::TEAM, new InboxFilter(), 50, 0, self::JETZT);
        } finally {
            (require dirname(__DIR__, 2) . '/database/migrations/2026_10_17_000001_create_rec_conversation_topics_table.php')->up();
            Capsule::table('rec_conversation_topics')->insert($gesichert);
        }

        $this->assertSame(9, $counts['total']);
        $this->assertSame(['dispo' => 0, 'lohn' => 0, 'hr' => 0], $counts['topics']);
        $this->assertCount(9, $snap['rows']);
        foreach ($snap['rows'] as $row) {
            $this->assertNull($row->topicLabel);
        }
    }

    private static function seed(): void
    {
        $accountId = (int) Capsule::table('integrations_whatsapp_accounts')->insertGetId([
            'uuid' => 'acc-topic', 'phone_number' => '+49 160 5559601',
            'title' => 'Recruiting', 'active' => true, 'user_id' => 1,
        ]);
        $channel = (int) Capsule::table('comms_channels')->insertGetId([
            'team_id' => self::TEAM, 'type' => 'whatsapp', 'provider' => 'whatsapp_meta',
            'sender_identifier' => '+49 160 5559601', 'is_active' => true,
            'meta' => json_encode(['integrations_whatsapp_account_id' => $accountId]),
        ]);
        RecApplicantSettings::create(['team_id' => self::TEAM, 'settings' => ['auto_pilot_wa_account_id' => $accountId]]);

        self::$dispoKanal = (int) Capsule::table('comms_channels')->insertGetId([
            'team_id' => self::TEAM, 'type' => 'whatsapp', 'provider' => 'whatsapp_meta',
            'name' => 'RheinGedeck Disposition CGN', 'sender_identifier' => '+49 211 78177729', 'is_active' => true,
        ]);
        Capsule::table('rec_dispo_filiale_settings')->insert([
            'team_id' => self::TEAM, 'filial_nr' => 400, 'comms_channel_id' => self::$dispoKanal,
        ]);

        $stelle = fn (int $kst) => (int) Capsule::table('rec_positions')->insertGetId([
            'uuid' => 'pos-t' . $kst, 'team_id' => self::TEAM, 'title' => 'Stelle ' . $kst, 'cost_center' => $kst,
        ]);
        $s200 = $stelle(200);
        $s400 = $stelle(400);

        $bewerbung = fn (int $id, int $positionId) => Capsule::table('rec_applicants')->insert([
            'id' => $id, 'uuid' => 'app-t' . $id, 'team_id' => self::TEAM, 'progress' => 0, 'is_active' => true,
            'auto_pilot' => false, 'rec_position_id' => $positionId,
        ]);
        $bewerbung(711, $s200);
        $bewerbung(712, $s200);
        $bewerbung(713, $s400);

        $ma = fn (int $id, ?string $kst) => Capsule::table('rec_employees')->insert([
            'id' => $id, 'uuid' => 'ma-t' . $id, 'team_id' => self::TEAM, 'is_active' => true, 'cost_center' => $kst,
        ]);
        $ma(811, '401');
        $ma(812, null);

        $thread = fn (string $nr, ?string $model, ?int $id) => (int) CommsWhatsAppThread::create([
            'team_id' => self::TEAM, 'comms_channel_id' => $channel, 'token' => bin2hex(random_bytes(8)),
            'remote_phone_number' => $nr, 'context_model' => $model, 'context_model_id' => $id,
            'is_unread' => true, 'last_inbound_at' => date('Y-m-d H:i:s', self::JETZT - 3_600),
        ])->id;
        self::$lohnMgl = $thread('+4915100000711', 'rec_applicant', 711);
        self::$hrMgl = $thread('+4915100000712', 'rec_applicant', 712);
        self::$wechsel = $thread('+4915100000713', 'rec_applicant', 713);
        self::$dispoCgn = $thread('+4915100000811', RecEmployee::class, 811);
        self::$loginHinweis = $thread('+4915100000812', RecEmployee::class, 812);
        self::$nurGefragt = $thread('+4915100000998', null, null);
        self::$ohneZeile = $thread('+4915100000999', null, null);
        self::$neueFrage = $thread('+4915100000997', null, null);
        self::$gesternHr = $thread('+4915100000996', null, null);

        $zeile = fn (int $threadId, ?string $topic, string $state, int $vorStunden, ?int $kanal = null) => RecConversationTopic::create([
            'team_id' => self::TEAM, 'comms_whatsapp_thread_id' => $threadId,
            'topic' => $topic, 'state' => $state, 'dispo_channel_id' => $kanal,
            'asked_at' => date('Y-m-d H:i:s', self::JETZT - $vorStunden * 3_600),
            'chosen_at' => $topic === null ? null : date('Y-m-d H:i:s', self::JETZT - $vorStunden * 3_600 + 60),
        ]);
        // Reihenfolge der Anlage = Reihenfolge der IDs: die aeltere Wahl zuerst.
        $zeile(self::$wechsel, RecConversationTopic::TOPIC_LOHN, RecConversationTopic::STATE_LOHN_INFO, 30);
        $zeile(self::$lohnMgl, RecConversationTopic::TOPIC_LOHN, RecConversationTopic::STATE_AKTE_HINT, 2);
        $zeile(self::$hrMgl, RecConversationTopic::TOPIC_HR, RecConversationTopic::STATE_CHOSEN, 2);
        $zeile(self::$dispoCgn, RecConversationTopic::TOPIC_DISPO, RecConversationTopic::STATE_ROUTED, 2, self::$dispoKanal);
        $zeile(self::$loginHinweis, RecConversationTopic::TOPIC_LOHN, RecConversationTopic::STATE_LOHN_INFO, 2);
        $zeile(self::$wechsel, RecConversationTopic::TOPIC_HR, RecConversationTopic::STATE_CHOSEN, 1);
        // Frage von vor drei Tagen (laengst ausserhalb der Ruhezeit), nie beantwortet.
        $zeile(self::$nurGefragt, null, RecConversationTopic::STATE_ASKED, 72);
        $zeile(self::$neueFrage, RecConversationTopic::TOPIC_LOHN, RecConversationTopic::STATE_AKTE_HINT, 20);
        $zeile(self::$gesternHr, RecConversationTopic::TOPIC_HR, RecConversationTopic::STATE_CHOSEN, 26);
        $zeile(self::$neueFrage, null, RecConversationTopic::STATE_ASKED, 1);
    }
}
