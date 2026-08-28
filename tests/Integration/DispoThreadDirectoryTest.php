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
use Platform\Crm\Models\CommsWhatsAppMessage;
use Platform\Crm\Models\CommsWhatsAppThread;
use Platform\Crm\Models\CrmContact;
use Platform\Crm\Models\CrmContactLink;
use Platform\Recruiting\Models\RecDispoAssignment;
use Platform\Recruiting\Models\RecDispoEvent;
use Platform\Recruiting\Models\RecEmployee;
use Platform\Recruiting\Services\Zas\Dispo\DispoEmployeeGateway;
use Platform\Recruiting\Services\Zas\Dispo\DispoIdentityResolver;
use Platform\Recruiting\Services\Zas\Dispo\DispoThreadDirectory;

/**
 * DispoThreadDirectory loest Person -> Thread auf (Runde 4, #1), fuer den
 * VA-Chat-Panel (Task 7). Ende zu Ende gegen ECHTE Migrationen (recruiting +
 * crm), kein Testbench — Muster DispoIdentityResolverTest.
 */
class DispoThreadDirectoryTest extends TestCase
{
    private const TEAM = 701;

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

        $container->instance('config', new ConfigRepository([
            'recruiting' => ['zas' => ['inbound_team_id' => self::TEAM]],
        ]));

        self::runMigrations();
    }

    public static function tearDownAfterClass(): void
    {
        Container::getInstance()->forgetInstance('config');
        Facade::clearResolvedInstances();
    }

    protected function setUp(): void
    {
        Capsule::table('comms_whatsapp_messages')->delete();
        Capsule::table('comms_whatsapp_threads')->delete();
        Capsule::table('comms_channels')->delete();
        Capsule::table('rec_dispo_assignments')->delete();
        Capsule::table('rec_dispo_events')->delete();
        Capsule::table('crm_contact_links')->delete();
        Capsule::table('rec_employees')->delete();
    }

    private function directory(): DispoThreadDirectory
    {
        return new DispoThreadDirectory(new DispoIdentityResolver(), new DispoEmployeeGateway());
    }

    private function employee(string $pnr, ?string $phone = null): int
    {
        return (int) RecEmployee::create([
            'team_id' => self::TEAM, 'first_name' => 'Markus', 'last_name' => 'Ammerer',
            'personnel_number' => $pnr, 'phone' => $phone, 'portal_token' => 'tok-' . $pnr, 'is_active' => true,
        ])->id;
    }

    private function link(int $employeeId, int $contactId): void
    {
        Capsule::table('crm_contact_links')->insert([
            'uuid' => 'lnk-' . $employeeId . '-' . $contactId, 'contact_id' => $contactId, 'team_id' => self::TEAM,
            'created_by_user_id' => 1, 'linkable_id' => $employeeId,
            'linkable_type' => (new RecEmployee())->getMorphClass(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function channel(): int
    {
        return (int) CommsChannel::create([
            'team_id' => self::TEAM, 'type' => 'whatsapp', 'provider' => 'whatsapp_meta',
            'sender_identifier' => '+49 160 555' . random_int(100000, 999999),
        ])->id;
    }

    private function thread(int $channelId, string $remotePhone, ?int $contactId, bool $isUnread, string $updatedAt): int
    {
        $attrs = [
            'team_id'             => self::TEAM,
            'comms_channel_id'    => $channelId,
            'token'               => bin2hex(random_bytes(8)),
            'remote_phone_number' => $remotePhone,
            'is_unread'           => $isUnread,
        ];
        if ($contactId !== null) {
            $attrs['contact_id'] = $contactId;
            $attrs['contact_type'] = CrmContact::class;
        }

        $id = (int) CommsWhatsAppThread::create($attrs)->id;
        Capsule::table('comms_whatsapp_threads')->where('id', $id)->update(['updated_at' => $updatedAt]);

        return $id;
    }

    public function test_contact_linked_thread_is_found_for_the_whole_identity_group(): void
    {
        $rg = $this->employee('RG1'); $ma = $this->employee('MA1');
        $this->link($rg, 900); $this->link($ma, 900);

        $channel = $this->channel();
        $threadId = $this->thread($channel, '+49 999 000000', 900, false, '2026-08-27 10:00:00');

        $result = $this->directory()->threadsFor([$channel], [$ma]);

        $canon = min($rg, $ma);
        $this->assertArrayHasKey($canon, $result);
        $this->assertSame($threadId, $result[$canon]['thread_id']);
    }

    public function test_phone_matched_thread_is_found_without_contact(): void
    {
        $ma = $this->employee('MA2', '0172 3333333');
        $channel = $this->channel();
        $threadId = $this->thread($channel, '+49 172 3333333', null, false, '2026-08-27 10:00:00');

        $result = $this->directory()->threadsFor([$channel], [$ma]);

        $this->assertSame($threadId, $result[$ma]['thread_id']);
    }

    public function test_newest_thread_wins_and_contact_beats_phone(): void
    {
        $ma = $this->employee('MA3', '0172 4444444');
        $this->link($ma, 910);

        $channel1 = $this->channel();
        $channel2 = $this->channel();

        // Zwei Telefon-Treffer auf demselben Kanal — derselbe normalisierte
        // Wert, unterschiedliche Rohschreibweise (Unique-Constraint erlaubt es).
        $newerPhoneThread = $this->thread($channel1, '+49 172 4444444', null, false, '2026-08-27 10:00:00');
        $olderPhoneThread = $this->thread($channel1, '0172 4444444', null, false, '2026-08-25 10:00:00');

        // Kontakt-verlinkter Thread ist AELTER als beide Telefon-Treffer, gewinnt aber.
        $contactThread = $this->thread($channel2, '+49 999 111000', 910, false, '2026-08-20 10:00:00');

        $phoneOnly = $this->directory()->threadsFor([$channel1], [$ma]);
        $this->assertSame($newerPhoneThread, $phoneOnly[$ma]['thread_id'], 'Unter reinen Telefon-Treffern gewinnt der neuere.');
        $this->assertNotSame($olderPhoneThread, $phoneOnly[$ma]['thread_id']);

        $withContact = $this->directory()->threadsFor([$channel1, $channel2], [$ma]);
        $this->assertSame($contactThread, $withContact[$ma]['thread_id'], 'Kontakt-Treffer schlaegt Telefon-Treffer, auch wenn aelter.');
    }

    public function test_unread_by_event_counts_persons_not_records(): void
    {
        $rg = $this->employee('RG4', '0172 5550001'); $ma = $this->employee('MA4', '0172 5550002');
        $this->link($rg, 920); $this->link($ma, 920);

        $channel = $this->channel();
        $this->thread($channel, '+49 999 222000', 920, true, '2026-08-28 09:00:00');

        $event = RecDispoEvent::create(['einsatz_ref' => 'E-UNREAD-1']);
        $today = now()->toDateString();

        RecDispoAssignment::create([
            'ds_ref' => 'DS-UNREAD-1', 'rec_dispo_event_id' => $event->id, 'pnr_raw' => 'RG4',
            'rec_employee_id' => $rg, 'datum' => $today, 'status_id' => RecDispoAssignment::STATUS_AUFTRAG,
        ]);
        RecDispoAssignment::create([
            'ds_ref' => 'DS-UNREAD-2', 'rec_dispo_event_id' => $event->id, 'pnr_raw' => 'MA4',
            'rec_employee_id' => $ma, 'datum' => $today, 'status_id' => RecDispoAssignment::STATUS_AUFTRAG,
        ]);

        $result = $this->directory()->unreadByEvent([$channel], [$event->id], $today);

        $this->assertSame([$event->id => 1], $result, 'RG- und MA-Einbuchung derselben Person zaehlen als EINE Person.');
    }

    public function test_messages_can_be_filtered_by_since(): void
    {
        $channel = $this->channel();
        $threadId = $this->thread($channel, '+49 172 7777777', null, false, '2026-08-28 09:00:00');

        $yesterday = (int) CommsWhatsAppMessage::create([
            'comms_whatsapp_thread_id' => $threadId, 'direction' => 'inbound', 'body' => 'gestern',
        ])->id;
        Capsule::table('comms_whatsapp_messages')->where('id', $yesterday)->update(['created_at' => '2026-08-27 10:00:00']);

        $today = (int) CommsWhatsAppMessage::create([
            'comms_whatsapp_thread_id' => $threadId, 'direction' => 'inbound', 'body' => 'heute',
        ])->id;
        Capsule::table('comms_whatsapp_messages')->where('id', $today)->update(['created_at' => '2026-08-28 09:00:00']);

        $thread = CommsWhatsAppThread::find($threadId);
        $since = new \DateTimeImmutable('2026-08-28 00:00:00');

        $result = $this->directory()->messages($thread, [], $since);

        $this->assertCount(1, $result);
        $this->assertSame('heute', $result[0]['body']);
    }

    private static function runMigrations(): void
    {
        $own = dirname(__DIR__, 2);
        $crm = self::packageRootOf(CrmContactLink::class);

        $files = [
            [$own, 'database/migrations/2026_05_20_000001_create_rec_employees_table.php'],
            [$own, 'database/migrations/2026_05_22_000001_add_personnel_number_to_rec_employees.php'],
            [$own, 'database/migrations/2026_08_26_000002_add_company_to_rec_employees.php'],
            [$crm, 'database/migrations/2024_01_01_000020_create_crm_contact_links_table.php'],
            [$crm, 'database/migrations/2026_01_14_000003_create_comms_channels_table.php'],
            [$crm, 'database/migrations/2026_02_12_100001_create_comms_whatsapp_threads_table.php'],
            [$crm, 'database/migrations/2026_02_12_100002_create_comms_whatsapp_messages_table.php'],
            [$own, 'database/migrations/2026_08_12_000001_create_rec_dispo_events_table.php'],
            [$own, 'database/migrations/2026_08_12_000002_create_rec_dispo_assignments_table.php'],
            [$own, 'database/migrations/2026_08_14_000001_add_confirmation_fields_to_rec_dispo_assignments.php'],
        ];

        foreach ($files as [$root, $relative]) {
            $path = $root . '/' . $relative;
            if (!file_exists($path)) {
                throw new \RuntimeException("Migration fehlt: {$path}");
            }
            (require $path)->up();
        }
    }

    /** Wurzel des Composer-Pakets einer geladenen Klasse (Modulmuster). */
    private static function packageRootOf(string $class): string
    {
        $file = (new \ReflectionClass($class))->getFileName();
        $dir = dirname((string) $file);

        while ($dir !== '/' && !file_exists($dir . '/composer.json')) {
            $dir = dirname($dir);
        }

        return $dir;
    }
}
