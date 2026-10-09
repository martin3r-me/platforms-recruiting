<?php

namespace Platform\Recruiting\Tests\Integration;

use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use Platform\Crm\Models\CommsChannel;
use Platform\Crm\Models\CommsWhatsAppThread;
use Platform\Integrations\Models\IntegrationsWhatsAppTemplate;
use Platform\Recruiting\Models\RecApplicant;
use Platform\Recruiting\Models\RecApplicantSettings;
use Platform\Recruiting\Models\RecEmployee;
use Platform\Recruiting\Services\Comms\EmployeeSenderResolver;
use Platform\Recruiting\Services\Comms\EmployeeThreadLinker;
use Platform\Recruiting\Services\Comms\InboxFilter;
use Platform\Recruiting\Services\Comms\InboxQuery;
use Platform\Recruiting\Services\IncomingApplicationService;

/**
 * Mitarbeiter schreiben an die HR-Nummer (Kundenwunsch 09.10.2026):
 *  - EmployeeSenderResolver::resolve() findet aktive Mitarbeiter des Teams
 *    ueber rec_employees.phone (ZAS-Bestand hat nur diese Nummer)
 *  - EmployeeThreadLinker haengt den Thread an den Mitarbeiter
 *  - /recruiting/conversations filtert Alle | Bewerber | Mitarbeiter
 *
 * Aufbau wie InboxOwnerSearchIsolationTest (Capsule + echte Migrationen).
 */
class MitarbeiterAufHrNummerTest extends TestCase
{
    private const TEAM = 893;
    private const JETZT = 1_757_930_000;

    private static int $channelId = 0;

    /** @var array<string, class-string> */
    private static array $previousMorphMap = [];
    private static bool $previousRequireMorphMap = false;

    public static function setUpBeforeClass(): void
    {
        $container = Container::getInstance();
        $container->instance('config', new ConfigRepository([
            'activity-log' => ['events' => []],
            // Kein Team-Anker: DispoIdentityResolver gruppiert fail-closed nicht.
            'recruiting' => ['zas' => ['inbound_team_id' => null]],
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
        self::$previousRequireMorphMap = Relation::requiresMorphMap();
        Relation::morphMap(['rec_applicant' => RecApplicant::class]);

        self::runMigrations();

        $accountId = (int) Capsule::table('integrations_whatsapp_accounts')->insertGetId([
            'uuid' => 'acc-ma-hr-nummer', 'phone_number' => '+49 160 5558003',
            'title' => 'Recruiting', 'active' => true, 'user_id' => 1,
        ]);
        self::$channelId = (int) Capsule::table('comms_channels')->insertGetId([
            'team_id' => self::TEAM, 'type' => 'whatsapp', 'provider' => 'whatsapp_meta',
            'sender_identifier' => '+49 160 5558003', 'is_active' => true,
            'meta' => json_encode(['integrations_whatsapp_account_id' => $accountId]),
        ]);
        RecApplicantSettings::create([
            'team_id' => self::TEAM,
            'settings' => ['auto_pilot_wa_account_id' => $accountId],
        ]);
        Capsule::table('rec_intake_channels')->insert([
            'uuid' => 'uuid-intake-ma-hr', 'team_id' => self::TEAM, 'comms_channel_id' => self::$channelId, 'is_active' => true,
            'created_at' => date('Y-m-d H:i:s', self::JETZT), 'updated_at' => date('Y-m-d H:i:s', self::JETZT),
        ]);
    }

    public static function tearDownAfterClass(): void
    {
        Relation::morphMap(self::$previousMorphMap, false);
        Relation::requireMorphMap(self::$previousRequireMorphMap);

        Container::getInstance()->forgetInstance('config');
        Facade::clearResolvedInstances();
    }

    protected function setUp(): void
    {
        foreach (['comms_thread_contexts', 'comms_whatsapp_threads', 'rec_employees', 'rec_applicants',
                  'crm_phone_numbers', 'crm_contact_links', 'crm_contacts'] as $table) {
            Capsule::table($table)->delete();
        }

        // Log-Facade: Attrappe binden UND den Facade-Cache leeren, sonst
        // greift eine Instanz aus einer frueheren Testklasse.
        Container::getInstance()->instance('log', new \Psr\Log\NullLogger());
        Facade::clearResolvedInstance('log');
    }

    // ---- Eingang (Service) ---------------------------------------------

    public function test_eingang_legt_fuer_mitarbeiter_keinen_bewerber_an(): void
    {
        $this->employee(712, '0151 2345 6789');

        $result = (new IncomingApplicationService())->handleInboundMessage(
            channel: CommsChannel::findOrFail(self::$channelId),
            senderIdentifier: '4915123456789',
            messageBody: 'Hallo, ich habe eine Frage zu meinem Einsatz',
        );

        $this->assertSame(EmployeeSenderResolver::EMPLOYEE, $result['employee_match']['status'] ?? null);
        $this->assertSame(712, $result['employee_match']['employee_id']);
        $this->assertSame(0, Capsule::table('rec_applicants')->count(), 'Fuer einen Mitarbeiter darf kein Bewerber entstehen.');
    }

    public function test_eingang_vorhandene_bewerbung_hat_vorrang_vor_mitarbeiter(): void
    {
        // Aus einer Bewerbung zum Mitarbeiter geworden: die Nachricht bleibt
        // an der Bewerbung (Reminder-/Kampagnen-Antworten laufen dort).
        $this->applicant(804);
        $this->employee(713, '0151 2345 6789', ['rec_applicant_id' => 804]);
        Capsule::table('crm_contacts')->insert([
            'id' => 8040, 'uuid' => 'uuid-contact-8040', 'first_name' => 'Lea', 'last_name' => 'Ott',
            'team_id' => self::TEAM, 'is_active' => true,
            'created_at' => date('Y-m-d H:i:s', self::JETZT), 'updated_at' => date('Y-m-d H:i:s', self::JETZT),
        ]);
        Capsule::table('crm_contact_links')->insert([
            'uuid' => 'uuid-link-8040', 'contact_id' => 8040, 'team_id' => self::TEAM,
            'created_by_user_id' => 1, 'linkable_id' => 804, 'linkable_type' => 'rec_applicant',
            'created_at' => date('Y-m-d H:i:s', self::JETZT), 'updated_at' => date('Y-m-d H:i:s', self::JETZT),
        ]);
        \Platform\Crm\Models\CrmContact::findOrFail(8040)->phoneNumbers()->create([
            'raw_input' => '0151 2345 6789', 'international' => '+4915123456789',
            'is_primary' => true, 'is_active' => true,
            'phone_type_id' => 1, // NOT NULL im echten Schema
        ]);

        $result = (new IncomingApplicationService())->handleInboundMessage(
            channel: CommsChannel::findOrFail(self::$channelId),
            senderIdentifier: '4915123456789',
            messageBody: 'Hallo',
        );

        $this->assertArrayNotHasKey('employee_match', $result ?? []);
        $this->assertSame(804, (int) $result['applicant']->id);
        $this->assertFalse($result['is_new']);
    }

    // ---- Erkennung -----------------------------------------------------

    public function test_zas_mitarbeiter_mit_nationaler_nummer_wird_erkannt(): void
    {
        $this->employee(701, '0151 2345 6789');

        $result = (new EmployeeSenderResolver())->resolve('4915123456789', self::TEAM);

        $this->assertSame(EmployeeSenderResolver::EMPLOYEE, $result['status']);
        $this->assertSame(701, $result['employee_id']);
    }

    public function test_inaktiver_mitarbeiter_wird_nicht_erkannt(): void
    {
        // Wiederbewerbung Ehemaliger soll weiter als Bewerbung ankommen.
        $this->employee(702, '0151 2345 6789', ['is_active' => false]);

        $result = (new EmployeeSenderResolver())->resolve('4915123456789', self::TEAM);

        $this->assertSame(EmployeeSenderResolver::NONE, $result['status']);
    }

    public function test_mitarbeiter_eines_fremden_teams_wird_nicht_erkannt(): void
    {
        $this->employee(703, '0151 2345 6789', ['team_id' => self::TEAM + 1]);

        $result = (new EmployeeSenderResolver())->resolve('4915123456789', self::TEAM);

        $this->assertSame(EmployeeSenderResolver::NONE, $result['status']);
    }

    public function test_zwei_personen_mit_einer_nummer_sind_mehrdeutig(): void
    {
        $this->employee(704, '0151 2345 6789');
        $this->employee(705, '+49 151 23456789');

        $result = (new EmployeeSenderResolver())->resolve('4915123456789', self::TEAM);

        $this->assertSame(EmployeeSenderResolver::AMBIGUOUS, $result['status']);
        $this->assertSame([704, 705], $result['employee_ids']);
    }

    public function test_rg_und_ma_datensatz_derselben_person_sind_ein_treffer(): void
    {
        $this->employee(706, '0151 2345 6789', ['person_key' => 'pk-706']);
        $this->employee(707, '0151 2345 6789', ['person_key' => 'pk-706']);

        $result = (new EmployeeSenderResolver())->resolve('4915123456789', self::TEAM);

        $this->assertSame(EmployeeSenderResolver::EMPLOYEE, $result['status']);
        $this->assertSame(706, $result['employee_id']);
    }

    // ---- Zuordnung -----------------------------------------------------

    public function test_linker_befoerdert_nackten_kontakt_thread_zum_mitarbeiter(): void
    {
        $this->employee(708, '0151 2345 6789');
        $threadId = $this->thread('4915123456789', 'Platform\\Crm\\Models\\CrmContact', 55);

        EmployeeThreadLinker::link(CommsWhatsAppThread::findOrFail($threadId), 708, 'recruiting_inbound_employee');

        $thread = CommsWhatsAppThread::findOrFail($threadId);
        $this->assertSame(RecEmployee::class, $thread->context_model);
        $this->assertSame(708, (int) $thread->context_model_id);
        $this->assertTrue(
            Capsule::table('comms_thread_contexts')
                ->where('thread_id', $threadId)
                ->where('context_model', RecEmployee::class)
                ->where('context_model_id', 708)
                ->exists(),
            'Pivot-Kontext fehlt — ThreadContextGate wuerde die naechste Nachricht nicht blocken.',
        );
    }

    // ---- Filter Alle | Bewerber | Mitarbeiter ---------------------------

    public function test_filter_trennt_bewerber_und_mitarbeiter(): void
    {
        $this->applicant(801);
        $bewerber = $this->thread('4915100000001', 'rec_applicant', 801);

        // Bewerbung, aus der ein aktiver Mitarbeiter wurde -> "Mitarbeiter".
        $this->applicant(802);
        $this->employee(709, '0151 0000 0002', ['rec_applicant_id' => 802]);
        $ehemaligerBewerber = $this->thread('4915100000002', 'rec_applicant', 802);

        // Bewerbung mit INAKTIVEM Mitarbeiter -> bleibt "Bewerber".
        $this->applicant(803);
        $this->employee(710, '0151 0000 0003', ['rec_applicant_id' => 803, 'is_active' => false]);
        $ausgeschieden = $this->thread('4915100000003', 'rec_applicant', 803);

        $this->employee(711, '0151 0000 0004');
        $mitarbeiter = $this->thread('4915100000004', RecEmployee::class, 711);

        $unbekannt = $this->thread('4915100000005', 'Platform\\Crm\\Models\\CrmContact', 66);

        $alle = $this->threadIds(new InboxFilter());
        $nurBewerber = $this->threadIds(new InboxFilter(kind: 'applicants'));
        $nurMitarbeiter = $this->threadIds(new InboxFilter(kind: 'employees'));

        sort($alle);
        $this->assertSame([$bewerber, $ehemaligerBewerber, $ausgeschieden, $mitarbeiter, $unbekannt], $alle);
        $this->assertEqualsCanonicalizing([$bewerber, $ausgeschieden], $nurBewerber);
        $this->assertEqualsCanonicalizing([$ehemaligerBewerber, $mitarbeiter], $nurMitarbeiter);

        $counts = (new InboxQuery())->counts(self::TEAM, self::JETZT);
        $this->assertSame(5, $counts['total']);
        $this->assertSame(2, $counts['applicants']);
        $this->assertSame(2, $counts['employees']);
    }

    // ---- Hilfen --------------------------------------------------------

    /** @return list<int> */
    private function threadIds(InboxFilter $filter): array
    {
        $result = (new InboxQuery())->page(self::TEAM, $filter, 50, 0, self::JETZT);

        return array_map(fn ($row) => $row->threadId, $result['rows']);
    }

    private function employee(int $id, string $phone, array $extra = []): void
    {
        Capsule::table('rec_employees')->insert(array_merge([
            'id' => $id, 'uuid' => 'uuid-employee-' . $id, 'team_id' => self::TEAM,
            'first_name' => 'MA', 'last_name' => (string) $id, 'phone' => $phone, 'is_active' => true,
            'created_at' => date('Y-m-d H:i:s', self::JETZT), 'updated_at' => date('Y-m-d H:i:s', self::JETZT),
        ], $extra));
    }

    private function applicant(int $id): void
    {
        Capsule::table('rec_applicants')->insert([
            'id' => $id, 'uuid' => 'uuid-applicant-' . $id, 'team_id' => self::TEAM,
            'progress' => 0, 'is_active' => true, 'auto_pilot' => false,
            'created_at' => date('Y-m-d H:i:s', self::JETZT), 'updated_at' => date('Y-m-d H:i:s', self::JETZT),
        ]);
    }

    private function thread(string $phone, string $contextModel, int $contextId): int
    {
        return (int) CommsWhatsAppThread::create([
            'team_id' => self::TEAM,
            'comms_channel_id' => self::$channelId,
            'token' => bin2hex(random_bytes(8)),
            'remote_phone_number' => $phone,
            'context_model' => $contextModel,
            'context_model_id' => $contextId,
            'is_unread' => false,
            'last_inbound_at' => date('Y-m-d H:i:s', self::JETZT - 100_000),
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
            [$own, 'database/migrations/2026_02_09_000005_create_rec_applicants_table.php'],
            // rejected_at: ohne die Spalte liest SQLite "rejected_at" als
            // String-Literal, der Bestandscheck faende still nie etwas.
            [$own, 'database/migrations/2026_04_24_000001_add_hr_desk_to_rec_applicants.php'],
            [$own, 'database/migrations/2026_02_09_000006_create_rec_applicant_posting_table.php'],
            [$own, 'database/migrations/2026_04_29_000001_create_rec_source_platforms_table.php'],
            [$own, 'database/migrations/2026_06_12_000003_add_matching_columns.php'],
            [$own, 'database/migrations/2026_05_20_000001_create_rec_employees_table.php'],
            [$own, 'database/migrations/2026_09_10_000001_add_person_key_to_rec_employees.php'],
            [$crm, 'database/migrations/2026_01_14_000003_create_comms_channels_table.php'],
            [$crm, 'database/migrations/2026_02_12_100001_create_comms_whatsapp_threads_table.php'],
            [$crm, 'database/migrations/2026_02_12_100002_create_comms_whatsapp_messages_table.php'],
            [$crm, 'database/migrations/2026_07_10_000001_add_is_auto_reply_to_comms_whatsapp_messages.php'],
            [$integrations, 'database/migrations/2026_01_17_150000_create_integrations_whatsapp_accounts_table.php'],
            [$crm, 'database/migrations/2024_01_01_000016_create_crm_contacts_table.php'],
            [$crm, 'database/migrations/2024_01_01_000020_create_crm_contact_links_table.php'],
            [$crm, 'database/migrations/2024_01_01_000014_create_crm_phone_numbers_table.php'],
            [$crm, 'database/migrations/2024_01_01_000015_create_crm_email_addresses_table.php'],
            [$own, 'database/migrations/2026_06_12_000001_create_rec_intake_channels_table.php'],
        ];

        foreach ($files as [$root, $relative]) {
            $path = $root . '/' . $relative;
            if (!file_exists($path)) {
                throw new \RuntimeException("Migration fehlt: {$path}");
            }
            $migration = require $path;
            $migration->up();
        }

        // Die CRM-Migration kopiert Altbestand aus comms_email_threads — die
        // Tabelle braucht dieser Test nicht, deshalb das Pivot von Hand.
        Capsule::schema()->create('comms_thread_contexts', function (Blueprint $table) {
            $table->id();
            $table->string('thread_type');
            $table->unsignedBigInteger('thread_id');
            $table->string('context_model');
            $table->unsignedBigInteger('context_model_id');
            $table->string('source')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        // HCM-Check des Eingangs (senderHasActiveHcmRecord) fragt diese
        // Tabellen, sobald ein CRM-Kontakt zur Nummer existiert.
        // Rueckgabe des Bestandschecks liest postings()->first() — leer reicht.
        Capsule::schema()->create('rec_postings', function (Blueprint $table) {
            $table->id();
        });

        foreach (['hcm_onboardings', 'hcm_employees'] as $hcmTable) {
            Capsule::schema()->create($hcmTable, function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('team_id');
                $table->boolean('is_active')->default(true);
                $table->timestamps();
            });
        }
    }

    private static function packageRootOf(string $class): string
    {
        return dirname((new \ReflectionClass($class))->getFileName(), 3);
    }
}
