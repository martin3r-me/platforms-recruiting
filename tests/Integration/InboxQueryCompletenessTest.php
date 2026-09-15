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
use Platform\Integrations\Models\IntegrationsWhatsAppTemplate;
use Platform\Recruiting\Models\RecApplicantSettings;
use Platform\Recruiting\Models\RecConversationHandled;
use Platform\Recruiting\Services\Comms\InboxFilter;
use Platform\Recruiting\Services\Comms\InboxQuery;

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
 */
class InboxQueryCompletenessTest extends TestCase
{
    private const TEAM = 703;
    private const JETZT = 1_757_930_000;

    private static int $channelId = 0;
    private static int $fremdChannelId = 0;
    private static int $threadOhneKontext = 0;
    private static int $threadBewerber = 0;
    private static int $threadZwilling = 0;
    private static int $threadFremderKanal = 0;
    private static int $threadErledigt = 0;

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

        self::runMigrations();
        self::seedFixtures();
    }

    public static function tearDownAfterClass(): void
    {
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
        $this->assertSame(1, $counts['handled']);
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

        $this->assertContains(self::$threadErledigt, $this->threadIds(new InboxFilter()));

        // Fuer die folgenden Tests zuruecksetzen.
        CommsWhatsAppThread::query()
            ->whereKey(self::$threadErledigt)
            ->update(['last_inbound_at' => date('Y-m-d H:i:s', self::JETZT - 100_000)]);
    }

    public function test_seite_schneidet_und_meldet_die_gesamtzahl(): void
    {
        $result = (new InboxQuery())->page(self::TEAM, new InboxFilter(), 2, 0, self::JETZT);

        $this->assertCount(2, $result['rows']);
        // 3 sichtbare Threads: ohne Kontext, Bewerber, Zwilling.
        // Fremder Kanal und abgehakter Chat gehoeren nicht dazu.
        $this->assertSame(3, $result['total']);
    }

    /** @return list<int> */
    private function threadIds(InboxFilter $filter): array
    {
        $result = (new InboxQuery())->page(self::TEAM, $filter, 50, 0, self::JETZT);

        return array_map(fn ($row) => $row->threadId, $result['rows']);
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

        // 2+3) Zwei Threads derselben Person — Fall #307.
        self::$threadBewerber = self::createThread(
            self::$channelId, '+49 151 70000002', 'rec_applicant', 555,
        );
        self::$threadZwilling = self::createThread(
            self::$channelId, '0151 70000002', 'rec_applicant', 555,
        );

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
            // Nachtrag zur Vorgabe: is_auto_reply ist eine spaetere ALTER-TABLE-
            // Migration, nicht Teil der CREATE-Migration. InboxQuery::humanOutboundTimestamps()
            // filtert darauf — ohne diese Migration schlaegt die Query mit
            // "no such column: is_auto_reply" fehl, sobald mindestens ein Thread
            // sichtbar ist (echter fehlender Migrationspfad, siehe Auftrag).
            [$crm, 'database/migrations/2026_07_10_000001_add_is_auto_reply_to_comms_whatsapp_messages.php'],
            [$integrations, 'database/migrations/2026_01_17_150000_create_integrations_whatsapp_accounts_table.php'],
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
