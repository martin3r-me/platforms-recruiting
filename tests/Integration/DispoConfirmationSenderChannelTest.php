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
use Platform\Crm\Services\Comms\WhatsAppMetaService;
use Platform\Integrations\Models\IntegrationsWhatsAppTemplate;
use Platform\Recruiting\Models\RecApplicantSettings;
use Platform\Recruiting\Models\RecDispoAssignment;
use Platform\Recruiting\Models\RecDispoEvent;
use Platform\Recruiting\Models\RecDispoFilialeSettings;
use Platform\Recruiting\Models\RecEmployee;
use Platform\Recruiting\Services\Zas\Dispo\DispoConfirmationSender;
use Platform\Recruiting\Services\Zas\Dispo\DispoEmployeeGateway;

/**
 * Fix 1 (Dispo-Versand-Konsistenz): der Erstversand (DispoConfirmationSender)
 * muss denselben Kanal-Aufloeser wie die Eskalation benutzen (DispoChannelResolver
 * ::resolveForEvent) — eine Veranstaltung mit eigenem Filial-Kanal sendet die
 * Erstbestaetigung von DIESER Nummer, nicht vom Template-Account. Aufbau/Migrations-
 * Muster wie DispoEscalateCommandTest (echte Migrationen, WhatsAppMetaService als
 * Container-Stub).
 */
class DispoConfirmationSenderChannelTest extends TestCase
{
    private const TEAM = 601;
    private const FILIAL_NR = 77;
    private const TEMPLATE_ACCOUNT_NUMMER = '+49 160 5550001';
    private const FILIALE_CHANNEL_NUMMER = '+49 160 5550002';

    private static int $employeeId = 0;
    private static int $templateId = 0;
    private static int $defaultChannelId = 0;
    private static int $filialeChannelId = 0;

    /** @var object{calls:int,log:array} */
    private object $stub;

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
        self::seedFixtures();
    }

    public static function tearDownAfterClass(): void
    {
        Container::getInstance()->forgetInstance('config');
        Container::getInstance()->forgetInstance(WhatsAppMetaService::class);
        Facade::clearResolvedInstances();
    }

    protected function setUp(): void
    {
        $this->stub = new class {
            public int $calls = 0;
            /** @var list<array{channel:mixed,to:string}> */
            public array $log = [];

            public function sendTemplate($channel, string $to, string $templateName, array $components = [], string $languageCode = 'de', $sender = null, bool $isAutoReply = false): object
            {
                $this->calls++;
                $this->log[] = ['channel' => $channel, 'to' => $to];
                return (object) ['id' => 8000 + $this->calls, 'status' => 'sent'];
            }
        };
        Container::getInstance()->instance(WhatsAppMetaService::class, $this->stub);
    }

    public function test_confirmation_send_uses_filiale_channel_when_configured(): void
    {
        $event = RecDispoEvent::create([
            'einsatz_ref' => 'RG-CONF-CHAN', 'name' => 'Test-VA', 'filial_nr' => self::FILIAL_NR,
            'vorlauf_minuten' => 30,
        ]);

        RecDispoAssignment::create([
            'ds_ref' => 'DS-CONF-CHAN', 'rec_dispo_event_id' => $event->id, 'pnr_raw' => 'RG' . self::$employeeId,
            'rec_employee_id' => self::$employeeId, 'datum' => '2026-08-26', 'von' => '16:00', 'bis' => '22:00',
            'status_id' => RecDispoAssignment::STATUS_AUFTRAG,
        ]);

        $sender = new DispoConfirmationSender(new DispoEmployeeGateway());
        $recipients = [[
            'employee_id'     => self::$employeeId,
            'phone'           => RecEmployee::find(self::$employeeId)->phone,
            'assignment_ids'  => [RecDispoAssignment::where('ds_ref', 'DS-CONF-CHAN')->value('id')],
            'first_datum'     => '2026-08-26',
            'is_reminder'     => false,
        ]];

        $result = $sender->send($event, $recipients, self::$templateId);

        $this->assertTrue($result['ok']);
        $this->assertSame(1, $result['sent']);
        $this->assertSame(1, $this->stub->calls);

        $usedChannel = $this->stub->log[0]['channel'];
        $this->assertInstanceOf(CommsChannel::class, $usedChannel);
        $this->assertSame(self::$filialeChannelId, $usedChannel->id, 'Erstversand muss ueber den Filial-Kanal laufen, nicht ueber den Template-Account.');
        $this->assertSame(self::FILIALE_CHANNEL_NUMMER, $usedChannel->sender_identifier);
    }

    public function test_confirmation_send_falls_back_to_default_channel_without_filiale_mapping(): void
    {
        $event = RecDispoEvent::create([
            'einsatz_ref' => 'RG-CONF-DEFAULT', 'name' => 'Test-VA-Default', 'filial_nr' => 999999,
            'vorlauf_minuten' => 30,
        ]);

        RecDispoAssignment::create([
            'ds_ref' => 'DS-CONF-DEFAULT', 'rec_dispo_event_id' => $event->id, 'pnr_raw' => 'RG' . self::$employeeId,
            'rec_employee_id' => self::$employeeId, 'datum' => '2026-08-26', 'von' => '16:00', 'bis' => '22:00',
            'status_id' => RecDispoAssignment::STATUS_AUFTRAG,
        ]);

        $sender = new DispoConfirmationSender(new DispoEmployeeGateway());
        $recipients = [[
            'employee_id'     => self::$employeeId,
            'phone'           => RecEmployee::find(self::$employeeId)->phone,
            'assignment_ids'  => [RecDispoAssignment::where('ds_ref', 'DS-CONF-DEFAULT')->value('id')],
            'first_datum'     => '2026-08-26',
            'is_reminder'     => false,
        ]];

        $result = $sender->send($event, $recipients, self::$templateId);

        $this->assertTrue($result['ok']);
        $this->assertSame(1, $result['sent']);

        $usedChannel = $this->stub->log[0]['channel'];
        $this->assertSame(self::$defaultChannelId, $usedChannel->id, 'Ohne Filial-Zuordnung muss der Default-Kanal des Templates verwendet werden (unveraendertes Verhalten).');
    }

    private static function seedFixtures(): void
    {
        self::$defaultChannelId = (int) Capsule::table('comms_channels')->insertGetId([
            'team_id'           => self::TEAM,
            'type'              => 'whatsapp',
            'provider'          => 'whatsapp_meta',
            'sender_identifier' => self::TEMPLATE_ACCOUNT_NUMMER,
            'is_active'         => true,
        ]);

        self::$filialeChannelId = (int) Capsule::table('comms_channels')->insertGetId([
            'team_id'           => self::TEAM,
            'type'              => 'whatsapp',
            'provider'          => 'whatsapp_meta',
            'sender_identifier' => self::FILIALE_CHANNEL_NUMMER,
            'is_active'         => true,
        ]);

        $accountId = (int) Capsule::table('integrations_whatsapp_accounts')->insertGetId([
            'uuid'         => 'acc-dispo-confirm',
            'phone_number' => self::TEMPLATE_ACCOUNT_NUMMER,
            'title'        => 'Test-Account',
            'active'       => true,
            'user_id'      => 1,
        ]);

        self::$templateId = (int) IntegrationsWhatsAppTemplate::create([
            'external_id' => 'ext-conf-1', 'name' => 'dispo_einsatz_bestaetigung', 'language' => 'de', 'status' => 'APPROVED',
            'components' => [['type' => 'BODY', 'text' => 'Bestaetigung {{1}}']], 'whatsapp_account_id' => $accountId, 'user_id' => 1,
        ])->id;

        RecApplicantSettings::create([
            'team_id' => self::TEAM,
            'settings' => ['dispo_confirmation_template_id' => self::$templateId],
        ]);

        RecDispoFilialeSettings::create([
            'team_id' => self::TEAM, 'filial_nr' => self::FILIAL_NR,
            'comms_channel_id' => self::$filialeChannelId, 'duty_phone' => '+49 170 5559999',
        ]);

        self::$employeeId = (int) RecEmployee::create([
            'team_id' => self::TEAM, 'first_name' => 'Petra', 'last_name' => 'Muster',
            'phone' => '+49 151 88888888', 'portal_token' => 'tok-dispo-confirm', 'is_active' => true,
        ])->id;
    }

    private static function runMigrations(): void
    {
        $own = dirname(__DIR__, 2);
        $crm = self::packageRootOf(CommsChannel::class);
        $integrations = self::packageRootOf(IntegrationsWhatsAppTemplate::class);

        $files = [
            [$own, 'database/migrations/2026_05_20_000001_create_rec_employees_table.php'],
            [$own, 'database/migrations/2026_05_22_000001_add_personnel_number_to_rec_employees.php'],
            [$own, 'database/migrations/2026_08_26_000002_add_company_to_rec_employees.php'],
            [$own, 'database/migrations/2026_08_12_000001_create_rec_dispo_events_table.php'],
            [$own, 'database/migrations/2026_08_12_000002_create_rec_dispo_assignments_table.php'],
            [$own, 'database/migrations/2026_08_14_000001_add_confirmation_fields_to_rec_dispo_assignments.php'],
            [$own, 'database/migrations/2026_08_14_000002_add_vorlauf_minuten_to_rec_dispo_events.php'],
            [$own, 'database/migrations/2026_08_19_000001_add_ansprechpartner_to_rec_dispo_events.php'],
            [$own, 'database/migrations/2026_08_20_000001_add_filiale_to_rec_dispo_events.php'],
            [$own, 'database/migrations/2026_08_20_000002_add_individual_note_to_rec_dispo_assignments.php'],
            [$own, 'database/migrations/2026_08_21_000001_add_filial_nr_to_rec_dispo_events.php'],
            [$own, 'database/migrations/2026_08_24_000001_create_rec_dispo_filiale_settings_table.php'],
            [$own, 'database/migrations/2026_08_24_000002_add_escalation_fields_to_rec_dispo_assignments.php'],
            [$own, 'database/migrations/2026_08_24_000003_add_alarm_message_id_to_rec_dispo_events.php'],
            [$own, 'database/migrations/2026_08_24_000004_add_portal_lock_to_rec_employees.php'],
            [$own, 'database/migrations/2026_02_09_000008_create_rec_applicant_settings_table.php'],
            [$crm, 'database/migrations/2026_01_14_000003_create_comms_channels_table.php'],
            [$integrations, 'database/migrations/2026_01_17_150000_create_integrations_whatsapp_accounts_table.php'],
            [$integrations, 'database/migrations/2026_02_12_000001_create_integrations_whatsapp_templates_table.php'],
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
