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
use Platform\Integrations\Models\IntegrationsWhatsAppTemplate;
use Platform\Recruiting\Models\RecApplicantSettings;
use Platform\Recruiting\Services\Comms\InboxFilter;
use Platform\Recruiting\Services\Comms\InboxQuery;

/**
 * Re-Review-Nachzug zur Abschluss-Durchsicht, Befund 6: die Listenzeile
 * bekam eine neue Spalte (Uhrzeit der letzten Nachricht, InboxRow::
 * $lastMessageAt) — dafuer gab es noch keinen Test. Deckt InboxQuery::
 * lastMessageAt() ueber page()/rowForThread() ab: das Feld ist IMMER der
 * SPAETERE der beiden Zeitstempel (Eingang/Ausgang), unabhaengig davon,
 * welcher davon zeitlich zuerst kommt.
 */
class InboxLastMessageAtTest extends TestCase
{
    private const TEAM = 897;
    private const JETZT = 1_757_930_000;

    private static int $channelId = 0;

    public static function setUpBeforeClass(): void
    {
        $container = Container::getInstance();
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

        self::runMigrations();

        $accountId = (int) Capsule::table('integrations_whatsapp_accounts')->insertGetId([
            'uuid' => 'acc-last-message-at', 'phone_number' => '+49 160 5559400',
            'title' => 'Recruiting', 'active' => true, 'user_id' => 1,
        ]);
        self::$channelId = (int) Capsule::table('comms_channels')->insertGetId([
            'team_id' => self::TEAM, 'type' => 'whatsapp', 'provider' => 'whatsapp_meta',
            'sender_identifier' => '+49 160 5559400', 'is_active' => true,
            'meta' => json_encode(['integrations_whatsapp_account_id' => $accountId]),
        ]);
        RecApplicantSettings::create([
            'team_id' => self::TEAM,
            'settings' => ['auto_pilot_wa_account_id' => $accountId],
        ]);
    }

    public static function tearDownAfterClass(): void
    {
        Container::getInstance()->forgetInstance('config');
        Facade::clearResolvedInstances();
    }

    public function test_ohne_ausgang_ist_die_letzte_nachricht_der_eingang(): void
    {
        $threadId = $this->createThread('+49 151 89004001', self::JETZT - 3600, null);

        $row = self::findRow((new InboxQuery())->page(self::TEAM, new InboxFilter(), 50, 0, self::JETZT)['rows'], $threadId);

        $this->assertNotNull($row);
        $this->assertSame(self::JETZT - 3600, $row->lastMessageAt);
    }

    public function test_ausgang_nach_dem_eingang_ist_die_letzte_nachricht(): void
    {
        $threadId = $this->createThread('+49 151 89004002', self::JETZT - 7200, self::JETZT - 1800);

        $row = self::findRow((new InboxQuery())->page(self::TEAM, new InboxFilter(), 50, 0, self::JETZT)['rows'], $threadId);

        $this->assertNotNull($row);
        $this->assertSame(
            self::JETZT - 1800,
            $row->lastMessageAt,
            'Der spaetere der beiden Zeitstempel (hier: Ausgang) muss gewinnen.',
        );
    }

    public function test_eingang_nach_einem_aelteren_ausgang_ist_die_letzte_nachricht(): void
    {
        // Umgekehrter Fall: der Ausgang liegt VOR dem (neueren) Eingang —
        // max() darf sich nicht blind auf eine Reihenfolge verlassen.
        $threadId = $this->createThread('+49 151 89004003', self::JETZT - 900, self::JETZT - 5000);

        $row = self::findRow((new InboxQuery())->page(self::TEAM, new InboxFilter(), 50, 0, self::JETZT)['rows'], $threadId);

        $this->assertNotNull($row);
        $this->assertSame(self::JETZT - 900, $row->lastMessageAt);
    }

    public function test_rowfortread_liefert_dasselbe_feld(): void
    {
        $threadId = $this->createThread('+49 151 89004004', self::JETZT - 7200, self::JETZT - 1800);
        $thread = CommsWhatsAppThread::query()->whereKey($threadId)->where('team_id', self::TEAM)->first();

        $row = (new InboxQuery())->rowForThread($thread, self::TEAM, self::JETZT);

        $this->assertNotNull($row);
        $this->assertSame(self::JETZT - 1800, $row->lastMessageAt);
    }

    /** @param list<\Platform\Recruiting\Services\Comms\InboxRow> $rows */
    private static function findRow(array $rows, int $threadId): ?\Platform\Recruiting\Services\Comms\InboxRow
    {
        foreach ($rows as $row) {
            if ($row->threadId === $threadId) {
                return $row;
            }
        }

        return null;
    }

    private function createThread(string $phone, int $lastInboundAt, ?int $lastOutboundAt): int
    {
        // Bewusst OHNE context_model/context_model_id (Chat ohne Kontext,
        // Fall 2474) — dieser Test prueft nur das lastMessageAt-Feld, nicht
        // die Subjekt-Zuordnung.
        return (int) CommsWhatsAppThread::create([
            'team_id' => self::TEAM,
            'comms_channel_id' => self::$channelId,
            'token' => bin2hex(random_bytes(8)),
            'remote_phone_number' => $phone,
            'is_unread' => false,
            'last_inbound_at' => date('Y-m-d H:i:s', $lastInboundAt),
            'last_outbound_at' => $lastOutboundAt !== null ? date('Y-m-d H:i:s', $lastOutboundAt) : null,
            'last_message_preview' => 'Hallo',
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
            // hydrate() fragt rec_applicants/rec_employees auch bei leerer
            // ID-Liste ab (whereIn([])) — die Tabellen muessen existieren,
            // auch wenn dieser Test keine Zeilen darin anlegt.
            [$own, 'database/migrations/2026_02_09_000005_create_rec_applicants_table.php'],
            [$own, 'database/migrations/2026_05_20_000001_create_rec_employees_table.php'],
            [$crm, 'database/migrations/2026_01_14_000003_create_comms_channels_table.php'],
            [$crm, 'database/migrations/2026_02_12_100001_create_comms_whatsapp_threads_table.php'],
            [$crm, 'database/migrations/2026_02_12_100002_create_comms_whatsapp_messages_table.php'],
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
