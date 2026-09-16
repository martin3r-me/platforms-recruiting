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
use Platform\Crm\Models\CommsWhatsAppMessage;
use Platform\Crm\Models\CommsWhatsAppThread;
use Platform\Integrations\Models\IntegrationsWhatsAppTemplate;
use Platform\Recruiting\Models\RecApplicant;
use Platform\Recruiting\Models\RecApplicantSettings;
use Platform\Recruiting\Models\RecConversationHandled;
use Platform\Recruiting\Services\Comms\ConversationEscalation;
use Platform\Recruiting\Services\Comms\InboxFilter;
use Platform\Recruiting\Services\Comms\InboxQuery;
use Platform\Recruiting\Services\Comms\InboxRow;

/**
 * Task 4 — DER WICHTIGSTE TEST DES PAKETS.
 *
 * Die alte Uebersicht filtert auf context_model IN (rec_applicant, RecEmployee)
 * und fasst pro Person auf den neuesten Thread zusammen. Beides sind
 * Verlustpfade: Fall 2474 (Chat haengt am blossen CrmContact -> unsichtbar,
 * 41 Threads, davon 22 verlorene Bewerbungen) und Fall #307 (zwei Threads
 * derselben Person, einer nur eingehend -> einer unsichtbar).
 *
 * Dieser Test waere im August rot gewesen. Er darf nie wieder rot werden.
 *
 * Fix-Runde 1 (Review): zusaetzlich abgedeckt —
 *  - Auto-Antwort-Ausschluss vs. menschlicher Outbound (echte Nachrichten-Zeilen)
 *  - Bewerber-Zweig von hydrate() (subjectType/title/ownerUserId), inkl. Morph-Map
 *  - voller Zaehler-Satz statt nur 'handled'
 *  - deterministische Sortierung bei Gleichstand (thread_id als Tiebreaker)
 *  - snapshot()/fallback
 */
class InboxQueryCompletenessTest extends TestCase
{
    private const TEAM = 703;
    private const TEAM_OHNE_KANAL = 799;
    private const JETZT = 1_757_930_000;

    private static int $channelId = 0;
    private static int $fremdChannelId = 0;
    private static int $threadOhneKontext = 0;
    private static int $threadBewerber = 0;
    private static int $threadZwilling = 0;
    private static int $threadFremderKanal = 0;
    private static int $threadErledigt = 0;
    private static int $threadBeantwortet = 0;
    private static int $threadNurAutoReply = 0;

    /** @var array<string, class-string> Zustand vor diesem Test — siehe tearDownAfterClass(). */
    private static array $previousMorphMap = [];
    private static bool $previousRequireMorphMap = false;

    public static function setUpBeforeClass(): void
    {
        $container = Container::getInstance();

        // CrmContact nutzt LogsActivity (Trait initializeLogsActivity() ruft
        // config()) — sobald hydrate() echte Kontakte laedt (Fix-Runde 1),
        // braucht der Container eine 'config'-Bindung. Events leer = keine
        // Activity-Log-Hooks (Muster: DuplicateMatchQueryTest).
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

        // Prod (RecruitingServiceProvider::boot()) registriert diesen Alias
        // per Relation::morphMap() — NICHT enforceMorphMap(). Ohne ihn liefert
        // (new RecApplicant)->getMorphClass() den vollen Klassennamen, waehrend
        // die Fixtures (wie im Bestand) das Literal 'rec_applicant' schreiben —
        // hydrate() wuerde JEDE Bewerber-Zeile als 'unassigned' einordnen, der
        // Bewerber-Zweig bliebe ungetestet.
        //
        // Bewusst NICHT enforceMorphMap(): das setzt Relation::$requireMorphMap
        // prozessweit auf true, und Model::getMorphClass() wirft dann fuer JEDES
        // Modell ohne Eintrag eine ClassMorphViolationException — auch fuer
        // RecEmployee, das im echten ServiceProvider ABSICHTLICH nicht in der
        // Map steht (siehe Kommentar in ConversationInboxService). Erhaertet
        // durch einen echten Testlauf: enforceMorphMap() hier liess 19 andere
        // Integrationstests (ZasCrmContactBackfillTest, ZasEmployeeContactLinkerTest)
        // mit genau dieser Exception rot werden. Die einfache morphMap() erreicht
        // dieselbe Vorwaerts-Aufloesung (Klasse -> Alias) ohne diesen Nebeneffekt.
        //
        // Relation::$morphMap ist trotzdem STATISCH und prozessweit — ein
        // zweiter, unabhaengiger Befund aus demselben Testlauf: ohne Restore
        // liess das Setzen hier StatisticsInterviewsTableTest (laeuft alphabetisch
        // NACH diesem Test, gleicher PHPUnit-Prozess) fehlschlagen. Diese Klasse
        // verlinkt ihren CRM-Kontakt ueber die VOLLE Klasse als linkable_type
        // (Alt-Zeile, kein Alias) und erwartet, dass RecApplicant::getMorphClass()
        // wieder die volle Klasse liefert, sobald unser Test fertig ist — sonst
        // matcht crmContactLinks() die alte Zeile nicht mehr und der Name faellt
        // auf den Platzhalter "Bewerber #204" zurueck. Zustand daher exakt sichern
        // und zurueckschreiben, unabhaengig davon, was vor diesem Test schon
        // registriert war.
        self::$previousMorphMap = Relation::morphMap();
        self::$previousRequireMorphMap = Relation::requiresMorphMap();

        Relation::morphMap(['rec_applicant' => RecApplicant::class]);

        self::runMigrations();
        self::seedFixtures();
    }

    public static function tearDownAfterClass(): void
    {
        Relation::morphMap(self::$previousMorphMap, false);
        Relation::requireMorphMap(self::$previousRequireMorphMap);

        Container::getInstance()->forgetInstance('config');
        Facade::clearResolvedInstances();
    }

    public function test_chat_ohne_bewerber_kontext_erscheint_trotzdem(): void
    {
        $ids = $this->threadIds(new InboxFilter());

        $this->assertContains(
            self::$threadOhneKontext,
            $ids,
            'Chat am blossen CrmContact fehlt — das ist Fall 2474.',
        );
    }

    public function test_beide_threads_derselben_person_erscheinen(): void
    {
        $ids = $this->threadIds(new InboxFilter());

        $this->assertContains(self::$threadBewerber, $ids);
        $this->assertContains(self::$threadZwilling, $ids);
    }

    public function test_fremder_kanal_erscheint_nicht(): void
    {
        $this->assertNotContains(self::$threadFremderKanal, $this->threadIds(new InboxFilter()));
    }

    public function test_erledigter_chat_faellt_aus_der_liste_und_den_zaehlern(): void
    {
        $this->assertNotContains(self::$threadErledigt, $this->threadIds(new InboxFilter()));

        $counts = (new InboxQuery())->counts(self::TEAM, self::JETZT);

        // Voller Zaehler-Satz, nicht nur 'handled' — sonst faellt ein
        // Auseinanderlaufen von Liste und Pillen (Ampel zeigt etwas anderes
        // als die sichtbaren Zeilen) hier nicht auf. Sichtbare Nicht-
        // Handled-Threads: ohneKontext, bewerber, zwilling, nurAutoReply
        // (alle 'missed', gleiches altes last_inbound_at) + beantwortet
        // ('none', menschlich beantwortet). Fremder Kanal zaehlt gar nicht
        // mit (anderer Kanal), erledigt nur unter 'handled'.
        $this->assertSame([
            'unread' => 0,
            'green' => 0,
            'yellow' => 0,
            'red' => 0,
            'missed' => 4,
            'handled' => 1,
            'total' => 5,
        ], $counts);
    }

    public function test_erledigt_filter_zeigt_genau_die_abgehakten(): void
    {
        $ids = $this->threadIds(new InboxFilter(handled: true));

        $this->assertSame([self::$threadErledigt], $ids);
    }

    public function test_neuer_eingang_nach_dem_stempel_holt_den_chat_zurueck(): void
    {
        CommsWhatsAppThread::query()
            ->whereKey(self::$threadErledigt)
            ->update(['last_inbound_at' => date('Y-m-d H:i:s', self::JETZT - 60)]);

        try {
            $this->assertContains(self::$threadErledigt, $this->threadIds(new InboxFilter()));
        } finally {
            // Fuer die folgenden Tests zuruecksetzen — im finally, damit ein
            // Fehlschlag der Assertion oben nicht den naechsten Test aus dem
            // falschen Grund rot macht (der Ruecksetz-Schritt lief sonst nie).
            CommsWhatsAppThread::query()
                ->whereKey(self::$threadErledigt)
                ->update(['last_inbound_at' => date('Y-m-d H:i:s', self::JETZT - 100_000)]);
        }
    }

    public function test_seite_schneidet_und_meldet_die_gesamtzahl(): void
    {
        $result = (new InboxQuery())->page(self::TEAM, new InboxFilter(), 2, 0, self::JETZT);

        $this->assertCount(2, $result['rows']);
        // 5 sichtbare Threads: ohne Kontext, Bewerber, Zwilling, beantwortet,
        // nur-Auto-Reply. Fremder Kanal und abgehakter Chat gehoeren nicht dazu.
        $this->assertSame(5, $result['total']);
    }

    public function test_auto_antwort_zaehlt_nicht_als_beantwortet_aber_menschlicher_outbound_schon(): void
    {
        $result = (new InboxQuery())->page(self::TEAM, new InboxFilter(), 50, 0, self::JETZT);

        $beantwortet = self::findRow($result['rows'], self::$threadBeantwortet);
        $this->assertNotNull($beantwortet);
        $this->assertSame(
            ConversationEscalation::LEVEL_NONE,
            $beantwortet->escalation->level,
            'Menschlicher Outbound NACH dem letzten Eingang muss als beantwortet gelten.',
        );

        $nurAutoReply = self::findRow($result['rows'], self::$threadNurAutoReply);
        $this->assertNotNull($nurAutoReply);
        $this->assertSame(
            ConversationEscalation::LEVEL_MISSED,
            $nurAutoReply->escalation->level,
            'Eine reine Auto-Antwort (is_auto_reply=true) darf NICHT als menschliche Antwort '
                . 'zaehlen — sonst gilt ein nie gelesener Chat als beantwortet.',
        );
    }

    public function test_bewerber_zweig_liefert_subjecttype_titel_und_owner(): void
    {
        $result = (new InboxQuery())->page(self::TEAM, new InboxFilter(), 50, 0, self::JETZT);

        $row = self::findRow($result['rows'], self::$threadBewerber);

        $this->assertNotNull($row);
        $this->assertSame('applicant', $row->subjectType);
        $this->assertSame('Selin Yildiz', $row->title);
        $this->assertSame(42, $row->ownerUserId);
    }

    public function test_neueste_zuerst_und_gleichstand_stabil_nach_thread_id(): void
    {
        // Sortierung ist "neueste Nachricht zuerst" (Kundenwunsch 16.09.2026),
        // NICHT mehr nach Eskalationsstufe — sonst klebten monatealte verpasste
        // Chats dauerhaft oben, waehrend die Nachricht von heute unten stand.
        //
        // threadBeantwortet traegt einen menschlichen Outbound NACH seinem
        // Eingang, hat also die juengste Nachricht des Fixture-Satzes und muss
        // damit ganz oben stehen — obwohl er als einziger NICHT eskaliert ist.
        // Genau daran wuerde ein Rueckfall auf die alte Reihenfolge auffallen.
        // Die uebrigen vier teilen sich denselben last_inbound_at (echter
        // Gleichstand) und ordnen sich darin aufsteigend nach thread_id.
        $full = (new InboxQuery())->page(self::TEAM, new InboxFilter(), 50, 0, self::JETZT);
        $fullIds = array_map(fn (InboxRow $row) => $row->threadId, $full['rows']);

        $this->assertSame(
            [
                self::$threadBeantwortet,
                self::$threadOhneKontext,
                self::$threadBewerber,
                self::$threadZwilling,
                self::$threadNurAutoReply,
            ],
            $fullIds,
            'Die juengste Nachricht gehoert nach oben, danach der Gleichstand '
                . 'aufsteigend nach thread_id.',
        );

        // Zwei aufeinanderfolgende Seiten muessen exakt dieselbe Gesamt-
        // Reihenfolge ergeben wie eine einzelne groessere Seite — sonst kann
        // beim Blaettern eine Zeile uebersprungen werden oder doppelt
        // erscheinen.
        $page1 = (new InboxQuery())->page(self::TEAM, new InboxFilter(), 3, 0, self::JETZT);
        $page2 = (new InboxQuery())->page(self::TEAM, new InboxFilter(), 2, 3, self::JETZT);
        $combined = array_map(
            fn (InboxRow $row) => $row->threadId,
            array_merge($page1['rows'], $page2['rows']),
        );

        $this->assertSame($fullIds, $combined);
    }

    public function test_snapshot_meldet_fallback_je_nach_konfiguriertem_kanal(): void
    {
        $konfiguriert = (new InboxQuery())->snapshot(self::TEAM, new InboxFilter(), 50, 0, self::JETZT);
        $this->assertFalse($konfiguriert['fallback'], 'Team mit konfiguriertem WABA-Konto darf nicht im Rueckfall laufen.');

        $ohneKanal = (new InboxQuery())->snapshot(self::TEAM_OHNE_KANAL, new InboxFilter(), 50, 0, self::JETZT);
        $this->assertTrue($ohneKanal['fallback'], 'Team ohne konfiguriertes Konto muss den Rueckfall-Pfad melden.');
    }

    /** @return list<int> */
    private function threadIds(InboxFilter $filter): array
    {
        $result = (new InboxQuery())->page(self::TEAM, $filter, 50, 0, self::JETZT);

        return array_map(fn ($row) => $row->threadId, $result['rows']);
    }

    /** @param list<InboxRow> $rows */
    private static function findRow(array $rows, int $threadId): ?InboxRow
    {
        foreach ($rows as $row) {
            if ($row->threadId === $threadId) {
                return $row;
            }
        }

        return null;
    }

    private static function seedFixtures(): void
    {
        $accountId = (int) Capsule::table('integrations_whatsapp_accounts')->insertGetId([
            'uuid' => 'acc-rec-inbox', 'phone_number' => '+49 160 5553001',
            'title' => 'Recruiting', 'active' => true, 'user_id' => 1,
        ]);

        self::$channelId = self::createChannel('+49 160 5553001', $accountId);
        self::$fremdChannelId = self::createChannel('+49 160 5559999', 999999);

        RecApplicantSettings::create([
            'team_id' => self::TEAM,
            'settings' => ['auto_pilot_wa_account_id' => $accountId],
        ]);

        // 1) Chat am blossen CrmContact — Fall 2474.
        self::$threadOhneKontext = self::createThread(
            self::$channelId, '+49 151 70000001', 'Platform\\Crm\\Models\\CrmContact', 2484,
        );

        // 2+3) Zwei Threads derselben Person — Fall #307. Applicant #555 ist
        // ein ECHTER Bewerber (siehe unten), damit der Bewerber-Zweig von
        // hydrate() (subjectType/title/owner) mitgetestet wird.
        self::$threadBewerber = self::createThread(
            self::$channelId, '+49 151 70000002', 'rec_applicant', 555,
        );
        self::$threadZwilling = self::createThread(
            self::$channelId, '0151 70000002', 'rec_applicant', 555,
        );

        // Echter Bewerber #555 + verknuepfter CRM-Kontakt + Owner. Rohe
        // Inserts (kein Eloquent-create) fuer crm_contacts/crm_contact_links,
        // um Model-Hooks (LogsActivity/auth()) nicht anzufassen.
        Capsule::table('rec_applicants')->insert([
            'id' => 555, 'uuid' => 'uuid-applicant-555', 'team_id' => self::TEAM,
            'progress' => 0, 'is_active' => true, 'auto_pilot' => false,
            'owned_by_user_id' => 42,
            'created_at' => date('Y-m-d H:i:s', self::JETZT), 'updated_at' => date('Y-m-d H:i:s', self::JETZT),
        ]);
        Capsule::table('crm_contacts')->insert([
            'id' => 900, 'uuid' => 'uuid-contact-900', 'first_name' => 'Selin', 'last_name' => 'Yildiz',
            'team_id' => self::TEAM, 'is_active' => true,
            'created_at' => date('Y-m-d H:i:s', self::JETZT), 'updated_at' => date('Y-m-d H:i:s', self::JETZT),
        ]);
        Capsule::table('crm_contact_links')->insert([
            'uuid' => 'uuid-link-900', 'contact_id' => 900, 'team_id' => self::TEAM,
            'created_by_user_id' => 1, 'linkable_id' => 555, 'linkable_type' => 'rec_applicant',
            'created_at' => date('Y-m-d H:i:s', self::JETZT), 'updated_at' => date('Y-m-d H:i:s', self::JETZT),
        ]);

        // 4) Fremder Kanal (Dispo) — gehoert nicht hierher.
        self::$threadFremderKanal = self::createThread(
            self::$fremdChannelId, '+49 151 70000003', 'rec_applicant', 556,
        );

        // 5) Abgehakter Chat.
        self::$threadErledigt = self::createThread(
            self::$channelId, '+49 151 70000004', 'rec_applicant', 557,
        );
        RecConversationHandled::create([
            'team_id' => self::TEAM,
            'comms_whatsapp_thread_id' => self::$threadErledigt,
            'handled_at' => date('Y-m-d H:i:s', self::JETZT - 1_000),
            'handled_by_user_id' => 1,
            'handled_reason' => RecConversationHandled::REASON_MANUAL,
        ]);

        // 6) Menschlich beantwortet: Outbound NACH dem letzten Eingang.
        self::$threadBeantwortet = self::createThread(
            self::$channelId, '+49 151 70000005', 'rec_applicant', 558,
        );
        $humanMsgId = (int) CommsWhatsAppMessage::create([
            'comms_whatsapp_thread_id' => self::$threadBeantwortet,
            'direction' => 'outbound',
            'is_auto_reply' => false,
            'body' => 'Klar, kein Problem — bis dann!',
        ])->id;
        Capsule::table('comms_whatsapp_messages')->where('id', $humanMsgId)->update([
            'created_at' => date('Y-m-d H:i:s', self::JETZT - 99_000),
        ]);
        // Der Thread traegt denselben Zeitpunkt auch in seiner eigenen Spalte —
        // so wie es der CRM-Inbound beim echten Versand tut. Ohne das haette
        // dieser Thread dieselbe "letzte Nachricht" wie alle anderen, und der
        // Sortiertest koennte "neueste zuerst" gar nicht nachweisen.
        Capsule::table('comms_whatsapp_threads')->where('id', self::$threadBeantwortet)->update([
            'last_outbound_at' => date('Y-m-d H:i:s', self::JETZT - 99_000),
        ]);

        // 7) NUR eine Auto-Antwort (OOO/Voice) — zaehlt NICHT als beantwortet.
        self::$threadNurAutoReply = self::createThread(
            self::$channelId, '+49 151 70000006', 'rec_applicant', 559,
        );
        $autoMsgId = (int) CommsWhatsAppMessage::create([
            'comms_whatsapp_thread_id' => self::$threadNurAutoReply,
            'direction' => 'outbound',
            'is_auto_reply' => true,
            'body' => 'Automatische Abwesenheitsnotiz',
        ])->id;
        Capsule::table('comms_whatsapp_messages')->where('id', $autoMsgId)->update([
            'created_at' => date('Y-m-d H:i:s', self::JETZT - 99_000),
        ]);
    }

    private static function createChannel(string $sender, int $accountId): int
    {
        return (int) Capsule::table('comms_channels')->insertGetId([
            'team_id' => self::TEAM, 'type' => 'whatsapp', 'provider' => 'whatsapp_meta',
            'sender_identifier' => $sender, 'is_active' => true,
            'meta' => json_encode(['integrations_whatsapp_account_id' => $accountId]),
        ]);
    }

    private static function createThread(int $channelId, string $phone, string $contextModel, int $contextId): int
    {
        return (int) CommsWhatsAppThread::create([
            'team_id' => self::TEAM,
            'comms_channel_id' => $channelId,
            'token' => bin2hex(random_bytes(8)),
            'remote_phone_number' => $phone,
            'context_model' => $contextModel,
            'context_model_id' => $contextId,
            'is_unread' => false,
            'last_inbound_at' => date('Y-m-d H:i:s', self::JETZT - 100_000),
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
            [$own, 'database/migrations/2026_02_09_000005_create_rec_applicants_table.php'],
            [$own, 'database/migrations/2026_05_20_000001_create_rec_employees_table.php'],
            [$crm, 'database/migrations/2026_01_14_000003_create_comms_channels_table.php'],
            [$crm, 'database/migrations/2026_02_12_100001_create_comms_whatsapp_threads_table.php'],
            [$crm, 'database/migrations/2026_02_12_100002_create_comms_whatsapp_messages_table.php'],
            // Nachtrag zur urspruenglichen Vorgabe: is_auto_reply ist eine
            // spaetere ALTER-TABLE-Migration, nicht Teil der CREATE-Migration.
            // Ohne sie WIRFT die Query in InboxQuery::humanOutboundTimestamps()
            // NICHT (SQLite quotet den unbekannten Bezeichner doppelt und liest
            // "is_auto_reply" als String-Literal statt als Spaltenverweis —
            // das WHERE liefert dann stumm keine Zeile). Das ist schlimmer als
            // ein Absturz: jeder Thread mit echten Nachrichten gaelte als nie
            // beantwortet. Siehe Fix-Bericht unten fuer den Nachweis.
            [$crm, 'database/migrations/2026_07_10_000001_add_is_auto_reply_to_comms_whatsapp_messages.php'],
            [$integrations, 'database/migrations/2026_01_17_150000_create_integrations_whatsapp_accounts_table.php'],
            // Fix-Runde 1: fuer den echten Bewerber #555 (Kontaktname/Owner).
            [$crm, 'database/migrations/2024_01_01_000016_create_crm_contacts_table.php'],
            [$crm, 'database/migrations/2024_01_01_000020_create_crm_contact_links_table.php'],
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
