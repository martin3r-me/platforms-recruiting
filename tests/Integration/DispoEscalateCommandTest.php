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
use Platform\Recruiting\Console\Commands\DispoEscalateCommand;
use Platform\Recruiting\Models\RecApplicantSettings;
use Platform\Recruiting\Models\RecDispoAssignment;
use Platform\Recruiting\Models\RecDispoEvent;
use Platform\Recruiting\Models\RecDispoFilialeSettings;
use Platform\Recruiting\Models\RecEmployee;
use Platform\Recruiting\Services\Zas\Dispo\DispoChannelResolver;
use Platform\Recruiting\Services\Zas\Dispo\DispoEmployeeGateway;
use Platform\Recruiting\Services\Zas\Dispo\DispoEscalationPlanner;

/**
 * Der Eskalations-Command (Spec §3): Zielmengen-Filter, Stufen-Idempotenz und
 * die 16-Uhr-Rausnahme (deletion_marked_at + Portalsperre + Alarm) — Ende zu
 * Ende gegen ECHTE Migrationen (recruiting + crm + integrations), kein
 * Testbench.
 *
 * Probe-Muster wie ReconcileApplicantPositionsGateTest: escalate() ist aus
 * handle() herausgehoben (keine $this->option()/$this->warn()) und wird hier
 * ohne Artisan-Lebenszyklus direkt aufgerufen — $now kommt als
 * DateTimeImmutable rein statt ueber die --now=-Parsing in handle() (die
 * selbst nur eine Einzeiler-Konvertierung ist, siehe Report).
 *
 * WhatsAppMetaService wird als Container-Bindung gestubbt (Muster
 * TrainingCertificateWhatsAppDeliveryTest/HoldingTemplateSenderResolveTargetTest):
 * echter Kanal/Template/Account ueber echte Migrationen, nur der tatsaechliche
 * Meta-Call ist eine Attrappe die {id,status} liefert und mitzaehlt.
 */
class DispoEscalateCommandTest extends TestCase
{
    private const TEAM = 501;
    private const FILIAL_NR = 40;
    private const ACCOUNT_NUMMER = '+49 160 5551234';
    private const DUTY_PHONE = '+49 170 5559876';

    private static int $employeeId = 0;
    private static int $template1Id = 0;
    private static int $template2Id = 0;
    private static int $alarmTemplateId = 0;

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
        Capsule::table('rec_dispo_assignments')->delete();
        Capsule::table('rec_dispo_events')->delete();
        Capsule::table('rec_employees')->where('id', self::$employeeId)->update([
            'portal_locked_at' => null, 'portal_locked_reason' => null,
        ]);

        $settings = RecApplicantSettings::where('team_id', self::TEAM)->first();
        $settings->settings = self::baselineSettings();
        $settings->save();

        $this->stub = new class {
            public int $calls = 0;
            /** @var list<array{to:string,templateName:string,components:array}> */
            public array $log = [];

            public function sendTemplate($channel, string $to, string $templateName, array $components = [], string $languageCode = 'de', $sender = null, bool $isAutoReply = false): object
            {
                $this->calls++;
                $this->log[] = ['to' => $to, 'templateName' => $templateName, 'components' => $components];
                return (object) ['id' => 9000 + $this->calls, 'status' => 'sent'];
            }
        };
        Container::getInstance()->instance(WhatsAppMetaService::class, $this->stub);
    }

    private function probe(): DispoEscalateCommandProbe
    {
        return new DispoEscalateCommandProbe();
    }

    private function at(string $time): \DateTimeImmutable
    {
        return new \DateTimeImmutable($time, new \DateTimeZone('Europe/Berlin'));
    }

    public function test_target_population_filters_correctly(): void
    {
        $event = RecDispoEvent::create(['einsatz_ref' => 'RG-ESC-POP', 'name' => 'Test-VA', 'filial_nr' => self::FILIAL_NR]);

        $base = [
            'rec_dispo_event_id' => $event->id,
            'pnr_raw'            => 'RG' . self::$employeeId,
            'rec_employee_id'    => self::$employeeId,
            'datum'              => '2026-08-26', // "morgen" bezogen auf now=2026-08-25
            'status_id'          => RecDispoAssignment::STATUS_AUFTRAG,
            'reminder_sent_at'   => '2026-08-20 10:00:00',
        ];

        $correct = RecDispoAssignment::create(array_merge($base, ['ds_ref' => 'DS-CORRECT']));
        RecDispoAssignment::create(array_merge($base, ['ds_ref' => 'DS-WRONG-DATUM', 'datum' => '2026-08-27']));
        RecDispoAssignment::create(array_merge($base, ['ds_ref' => 'DS-WRONG-STATUS', 'status_id' => RecDispoAssignment::STATUS_ANGEBOT]));
        RecDispoAssignment::create(array_merge($base, ['ds_ref' => 'DS-NO-REMINDER', 'reminder_sent_at' => null]));
        RecDispoAssignment::create(array_merge($base, ['ds_ref' => 'DS-ALREADY-CONFIRMED', 'confirmed_at' => '2026-08-21 08:00:00']));
        RecDispoAssignment::create(array_merge($base, ['ds_ref' => 'DS-ALREADY-DELETED', 'deletion_marked_at' => '2026-08-21 08:00:00']));
        RecDispoAssignment::create(array_merge($base, ['ds_ref' => 'DS-MISSING', 'missing_since' => '2026-08-21 08:00:00']));
        RecDispoAssignment::create(array_merge($base, ['ds_ref' => 'DS-UNMATCHED', 'rec_employee_id' => null]));

        $report = $this->probe()->probeEscalate(
            new DispoEscalationPlanner(), new DispoChannelResolver(), new DispoEmployeeGateway(),
            $this->at('2026-08-25 14:01:00'), false
        );

        $this->assertSame(1, $report['population'], 'Nur die eine korrekte Zeile gehoert in die Zielmenge.');
        $this->assertSame(1, $report['stage1']);

        $this->assertNotNull(RecDispoAssignment::where('ds_ref', 'DS-CORRECT')->value('escalation_1_at'));
        foreach (['DS-WRONG-DATUM', 'DS-WRONG-STATUS', 'DS-NO-REMINDER', 'DS-ALREADY-CONFIRMED', 'DS-ALREADY-DELETED', 'DS-MISSING', 'DS-UNMATCHED'] as $ds) {
            $this->assertNull(RecDispoAssignment::where('ds_ref', $ds)->value('escalation_1_at'), "{$ds} haette NICHT eskaliert werden duerfen.");
        }
    }

    public function test_stage1_fires_once_and_is_idempotent_on_second_run(): void
    {
        $event = RecDispoEvent::create(['einsatz_ref' => 'RG-ESC-S1', 'name' => 'Test-VA', 'filial_nr' => self::FILIAL_NR]);
        RecDispoAssignment::create([
            'ds_ref' => 'DS-S1', 'rec_dispo_event_id' => $event->id, 'pnr_raw' => 'RG' . self::$employeeId,
            'rec_employee_id' => self::$employeeId, 'datum' => '2026-08-26', 'von' => '16:00', 'bis' => '22:00',
            'status_id' => RecDispoAssignment::STATUS_AUFTRAG, 'reminder_sent_at' => '2026-08-20 10:00:00',
        ]);

        $planner = new DispoEscalationPlanner();
        $resolver = new DispoChannelResolver();
        $gateway = new DispoEmployeeGateway();

        $first = $this->probe()->probeEscalate($planner, $resolver, $gateway, $this->at('2026-08-25 14:01:00'), false);
        $this->assertSame(1, $first['stage1']);
        $this->assertSame(1, $this->stub->calls, 'Genau ein Sende-Versuch beim ersten Lauf.');

        $row = RecDispoAssignment::where('ds_ref', 'DS-S1')->first();
        $this->assertNotNull($row->escalation_1_at);
        $this->assertNotNull($row->escalation_1_message_id);
        $firstAt = $row->escalation_1_at;
        $firstMessageId = $row->escalation_1_message_id;

        // Zweiter Lauf im selben Zeitfenster -> keine erneute Stufe, kein zweiter Send.
        $second = $this->probe()->probeEscalate($planner, $resolver, $gateway, $this->at('2026-08-25 14:05:00'), false);
        $this->assertSame(0, $second['stage1']);
        $this->assertSame(1, $this->stub->calls, 'Kein zweiter Sende-Versuch beim idempotenten Re-Lauf.');

        $row->refresh();
        $this->assertSame($firstAt->toDateTimeString(), $row->escalation_1_at->toDateTimeString());
        $this->assertSame($firstMessageId, $row->escalation_1_message_id);
    }

    public function test_stage3_marks_deletion_locks_portal_and_sends_alarm(): void
    {
        $event = RecDispoEvent::create(['einsatz_ref' => 'RG-ESC-S3', 'name' => 'Test-VA-Alarm', 'filial_nr' => self::FILIAL_NR]);
        RecDispoAssignment::create([
            'ds_ref' => 'DS-S3', 'rec_dispo_event_id' => $event->id, 'pnr_raw' => 'RG' . self::$employeeId,
            'rec_employee_id' => self::$employeeId, 'datum' => '2026-08-26', 'von' => '16:00', 'bis' => '22:00',
            'status_id' => RecDispoAssignment::STATUS_AUFTRAG, 'reminder_sent_at' => '2026-08-20 10:00:00',
        ]);

        $this->assertNull(RecEmployee::find(self::$employeeId)->portal_locked_at, 'Vorbedingung: MA noch nicht gesperrt.');

        $report = $this->probe()->probeEscalate(
            new DispoEscalationPlanner(), new DispoChannelResolver(), new DispoEmployeeGateway(),
            $this->at('2026-08-25 16:01:00'), false
        );

        $this->assertSame(1, $report['stage3']);

        $row = RecDispoAssignment::where('ds_ref', 'DS-S3')->first();
        $this->assertNotNull($row->deletion_marked_at);

        $employee = RecEmployee::find(self::$employeeId);
        $this->assertNotNull($employee->portal_locked_at);
        $this->assertStringContainsString('RG-ESC-S3', (string) $employee->portal_locked_reason);

        $event->refresh();
        $this->assertNotNull($event->alarm_message_id);
        $this->assertSame(1, $this->stub->calls, 'Genau ein Alarm-Sende-Versuch (aggregiert pro VA).');
        $this->assertSame(self::DUTY_PHONE, $this->stub->log[0]['to']);
    }

    public function test_disabled_is_noop(): void
    {
        $settings = RecApplicantSettings::where('team_id', self::TEAM)->first();
        $settings->settings = array_merge(self::baselineSettings(), ['dispo_escalation_enabled' => false]);
        $settings->save();

        $event = RecDispoEvent::create(['einsatz_ref' => 'RG-ESC-OFF', 'name' => 'Test-VA', 'filial_nr' => self::FILIAL_NR]);
        RecDispoAssignment::create([
            'ds_ref' => 'DS-OFF', 'rec_dispo_event_id' => $event->id, 'pnr_raw' => 'RG' . self::$employeeId,
            'rec_employee_id' => self::$employeeId, 'datum' => '2026-08-26',
            'status_id' => RecDispoAssignment::STATUS_AUFTRAG, 'reminder_sent_at' => '2026-08-20 10:00:00',
        ]);

        $report = $this->probe()->probeEscalate(
            new DispoEscalationPlanner(), new DispoChannelResolver(), new DispoEmployeeGateway(),
            $this->at('2026-08-25 16:01:00'), false
        );

        $this->assertTrue($report['skipped']);
        $this->assertSame(0, $this->stub->calls);

        $row = RecDispoAssignment::where('ds_ref', 'DS-OFF')->first();
        $this->assertNull($row->escalation_1_at);
        $this->assertNull($row->deletion_marked_at);
        $this->assertNull(RecEmployee::find(self::$employeeId)->portal_locked_at);
    }

    /** @return array<string,mixed> */
    private static function baselineSettings(): array
    {
        return [
            'dispo_escalation_enabled'       => true,
            'dispo_escalation_time_1'        => '14:00',
            'dispo_escalation_time_2'        => '15:00',
            'dispo_escalation_time_3'        => '16:00',
            'dispo_escalation_template_1_id' => self::$template1Id,
            'dispo_escalation_template_2_id' => self::$template2Id,
            'dispo_alarm_template_id'        => self::$alarmTemplateId,
        ];
    }

    private static function seedFixtures(): void
    {
        $channelId = (int) Capsule::table('comms_channels')->insertGetId([
            'team_id'           => self::TEAM,
            'type'              => 'whatsapp',
            'provider'          => 'whatsapp_meta',
            'sender_identifier' => self::ACCOUNT_NUMMER,
            'is_active'         => true,
        ]);

        $accountId = (int) Capsule::table('integrations_whatsapp_accounts')->insertGetId([
            'uuid'         => 'acc-dispo-escalate',
            'phone_number' => self::ACCOUNT_NUMMER,
            'title'        => 'Test-Account',
            'active'       => true,
            'user_id'      => 1,
        ]);

        self::$template1Id = (int) IntegrationsWhatsAppTemplate::create([
            'external_id' => 'ext-esc-1', 'name' => 'dispo_reminder', 'language' => 'de', 'status' => 'APPROVED',
            'components' => [['type' => 'BODY', 'text' => 'Reminder {{1}}']], 'whatsapp_account_id' => $accountId, 'user_id' => 1,
        ])->id;
        self::$template2Id = (int) IntegrationsWhatsAppTemplate::create([
            'external_id' => 'ext-esc-2', 'name' => 'dispo_final', 'language' => 'de', 'status' => 'APPROVED',
            'components' => [['type' => 'BODY', 'text' => 'Final {{1}}']], 'whatsapp_account_id' => $accountId, 'user_id' => 1,
        ])->id;
        self::$alarmTemplateId = (int) IntegrationsWhatsAppTemplate::create([
            'external_id' => 'ext-esc-alarm', 'name' => 'dispo_alarm', 'language' => 'de', 'status' => 'APPROVED',
            'components' => [['type' => 'BODY', 'text' => 'Alarm {{1}} {{2}}']], 'whatsapp_account_id' => $accountId, 'user_id' => 1,
        ])->id;

        RecDispoFilialeSettings::create([
            'team_id' => self::TEAM, 'filial_nr' => self::FILIAL_NR,
            'comms_channel_id' => $channelId, 'duty_phone' => self::DUTY_PHONE,
        ]);

        RecApplicantSettings::create([
            'team_id' => self::TEAM,
            'settings' => self::baselineSettings(),
        ]);

        self::$employeeId = (int) RecEmployee::create([
            'team_id' => self::TEAM, 'first_name' => 'Erika', 'last_name' => 'Muster',
            'phone' => '+49 151 12345678', 'portal_token' => 'tok-dispo-escalate', 'is_active' => true,
        ])->id;
    }

    private static function runMigrations(): void
    {
        $own = dirname(__DIR__, 2);
        $crm = self::packageRootOf(CommsChannel::class);
        $integrations = self::packageRootOf(IntegrationsWhatsAppTemplate::class);

        $files = [
            [$own, 'database/migrations/2026_05_20_000001_create_rec_employees_table.php'],
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

/** Probe-Muster (siehe ReconcileApplicantPositionsGateTest): macht die reine Engine-Logik ohne Artisan-Lebenszyklus aufrufbar. */
final class DispoEscalateCommandProbe extends DispoEscalateCommand
{
    /** @return array{skipped:bool, population:int, stage1:int, stage2:int, stage3:int} */
    public function probeEscalate(
        DispoEscalationPlanner $planner,
        DispoChannelResolver $resolver,
        DispoEmployeeGateway $gateway,
        \DateTimeImmutable $now,
        bool $dryRun = false,
    ): array {
        return $this->escalate($planner, $resolver, $gateway, $now, $dryRun);
    }
}
