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
        Capsule::table('crm_contact_links')->delete();
        RecEmployee::where('id', '!=', self::$employeeId)->delete();
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
            /** @var list<string> 'to'-Werte, fuer die Meta status=failed liefert (kein Wurf). */
            public array $failFor = [];
            /** @var list<string> 'to'-Werte, fuer die sendTemplate() eine Exception wirft. */
            public array $throwFor = [];

            public function sendTemplate($channel, string $to, string $templateName, array $components = [], string $languageCode = 'de', $sender = null, bool $isAutoReply = false): object
            {
                $this->calls++;
                $this->log[] = ['to' => $to, 'templateName' => $templateName, 'components' => $components];
                if (in_array($to, $this->throwFor, true)) {
                    throw new \RuntimeException('Simulierter Netzwerkfehler (Test)');
                }
                $status = in_array($to, $this->failFor, true) ? 'failed' : 'sent';
                return (object) ['id' => 9000 + $this->calls, 'status' => $status];
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

    /** Gemeinsame Basis-Einbuchung: Auftrag, angeschrieben 20.08. 10:00, gematcht. */
    private function baseRow(int $eventId, string $dsRef, string $datum): array
    {
        return [
            'rec_dispo_event_id' => $eventId, 'ds_ref' => $dsRef,
            'pnr_raw' => 'RG' . self::$employeeId, 'rec_employee_id' => self::$employeeId,
            'datum' => $datum, 'status_id' => RecDispoAssignment::STATUS_AUFTRAG,
            'reminder_sent_at' => '2026-08-20 10:00:00',
        ];
    }

    /** Zweiter Datensatz derselben Person (MA-Praefix), per crm_contact_links mit self::$employeeId verknuepft. */
    private function twinEmployee(): int
    {
        $twin = (int) RecEmployee::create([
            'team_id' => self::TEAM, 'first_name' => 'Erika', 'last_name' => 'Muster', 'personnel_number' => 'MA777',
            'phone' => '+49 151 12345678', 'portal_token' => 'tok-twin', 'is_active' => true,
        ])->id;
        foreach ([self::$employeeId, $twin] as $eid) {
            Capsule::table('crm_contact_links')->insert([
                'uuid' => 'lnk-' . $eid, 'contact_id' => 4242, 'team_id' => self::TEAM, 'created_by_user_id' => 1,
                'linkable_id' => $eid, 'linkable_type' => (new RecEmployee())->getMorphClass(),
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        return $twin;
    }

    public function test_stage1_sends_once_per_person_but_stamps_both_records(): void
    {
        $twin = $this->twinEmployee();
        $event = RecDispoEvent::create(['einsatz_ref' => 'RG-ESC-TWIN', 'name' => 'Twin', 'filial_nr' => self::FILIAL_NR]);
        RecDispoAssignment::create($this->baseRow($event->id, 'DS-TWIN-RG', '2026-08-26'));
        RecDispoAssignment::create(array_merge($this->baseRow($event->id, 'DS-TWIN-MA', '2026-08-26'), ['rec_employee_id' => $twin, 'pnr_raw' => 'MA777']));

        $report = $this->probe()->probeEscalate(new DispoEscalationPlanner(), new DispoChannelResolver(), new DispoEmployeeGateway(), $this->at('2026-08-25 14:01:00'));

        $this->assertSame(2, $report['stage1'], 'Beide Einbuchungen sind faellig …');
        $this->assertSame(1, $this->stub->calls, '… aber die Person bekommt genau EINE WhatsApp.');
        $this->assertNotNull(RecDispoAssignment::where('ds_ref', 'DS-TWIN-RG')->value('escalation_1_at'));
        $this->assertNotNull(RecDispoAssignment::where('ds_ref', 'DS-TWIN-MA')->value('escalation_1_at'));
    }

    public function test_stage3_locks_whole_person_and_alarm_counts_persons(): void
    {
        $twin = $this->twinEmployee();
        $event = RecDispoEvent::create(['einsatz_ref' => 'RG-ESC-TWIN3', 'name' => 'Twin3', 'filial_nr' => self::FILIAL_NR]);
        RecDispoAssignment::create($this->baseRow($event->id, 'DS-TWIN3-RG', '2026-08-26'));
        RecDispoAssignment::create(array_merge($this->baseRow($event->id, 'DS-TWIN3-MA', '2026-08-26'), ['rec_employee_id' => $twin, 'pnr_raw' => 'MA777']));

        $this->probe()->probeEscalate(new DispoEscalationPlanner(), new DispoChannelResolver(), new DispoEmployeeGateway(), $this->at('2026-08-25 16:01:00'));

        $this->assertNotNull(RecEmployee::find(self::$employeeId)->portal_locked_at);
        $this->assertNotNull(RecEmployee::find($twin)->portal_locked_at, 'Sperre gilt fuer die Person, also beide Datensaetze.');
        $alarm = collect($this->stub->log)->firstWhere('to', self::DUTY_PHONE);
        $this->assertSame('1', $alarm['components'][0]['parameters'][1]['text'], 'Alarm zaehlt Personen, nicht Einbuchungen.');
    }

    public function test_einsatztag_override_escalates_today_with_event_times(): void
    {
        $event = RecDispoEvent::create([
            'einsatz_ref' => 'RG-ESC-SAMEDAY', 'name' => 'Fruehschicht', 'filial_nr' => self::FILIAL_NR,
            'escalation_day' => 'einsatztag', 'escalation_time_1' => '07:00', 'escalation_time_2' => '08:00', 'escalation_time_3' => '09:00',
        ]);
        RecDispoAssignment::create($this->baseRow($event->id, 'DS-SAMEDAY', '2026-08-25'));

        $report = $this->probe()->probeEscalate(new DispoEscalationPlanner(), new DispoChannelResolver(), new DispoEmployeeGateway(), $this->at('2026-08-25 07:05:00'));

        $this->assertSame(1, $report['population']);
        $this->assertSame(1, $report['stage1'], 'Stufe 1 laut VA-Zeit 07:00 am Einsatztag.');
        $this->assertNotNull(RecDispoAssignment::where('ds_ref', 'DS-SAMEDAY')->value('escalation_1_at'));
    }

    public function test_einsatztag_event_for_tomorrow_is_not_touched_today(): void
    {
        $event = RecDispoEvent::create([
            'einsatz_ref' => 'RG-ESC-SAMEDAY-TMR', 'name' => 'Morgen', 'filial_nr' => self::FILIAL_NR,
            'escalation_day' => 'einsatztag', 'escalation_time_1' => '07:00', 'escalation_time_2' => '08:00', 'escalation_time_3' => '09:00',
        ]);
        RecDispoAssignment::create($this->baseRow($event->id, 'DS-SAMEDAY-TMR', '2026-08-26'));

        $report = $this->probe()->probeEscalate(new DispoEscalationPlanner(), new DispoChannelResolver(), new DispoEmployeeGateway(), $this->at('2026-08-25 14:01:00'));

        $this->assertSame(0, $report['population']);
        $this->assertNull(RecDispoAssignment::where('ds_ref', 'DS-SAMEDAY-TMR')->value('escalation_1_at'));
    }

    public function test_vortag_event_for_today_is_not_touched(): void
    {
        $event = RecDispoEvent::create(['einsatz_ref' => 'RG-ESC-VORTAG-TODAY', 'name' => 'Heute', 'filial_nr' => self::FILIAL_NR]);
        RecDispoAssignment::create($this->baseRow($event->id, 'DS-VORTAG-TODAY', '2026-08-25'));

        $report = $this->probe()->probeEscalate(new DispoEscalationPlanner(), new DispoChannelResolver(), new DispoEmployeeGateway(), $this->at('2026-08-25 14:01:00'));

        $this->assertSame(0, $report['population']);
        $this->assertNull(RecDispoAssignment::where('ds_ref', 'DS-VORTAG-TODAY')->value('escalation_1_at'));
        $this->assertNull(RecDispoAssignment::where('ds_ref', 'DS-VORTAG-TODAY')->value('deletion_marked_at'));
    }

    /**
     * Runde 4 (#4): Modus "datum" — heute ist das gewaehlte Eskalationsdatum,
     * der Einsatz liegt noch mehrere Tage in der Zukunft. Trotzdem eskaliert
     * er JETZT, weil das Datum ueber alle kommenden Einsatztage der VA gilt.
     */
    public function test_datum_mode_escalates_all_upcoming_days_on_the_chosen_date(): void
    {
        $today = new \DateTimeImmutable('2026-08-29 14:05:00');
        $event = RecDispoEvent::create(['einsatz_ref' => 'RG-ESC-DATUM', 'name' => 'Datum-Modus', 'filial_nr' => self::FILIAL_NR]);
        RecDispoEvent::whereKey($event->id)->update(['escalation_day' => 'datum', 'escalation_date' => '2026-08-29']);
        $a = RecDispoAssignment::create($this->baseRow($event->id, 'DS-DATUM', '2026-09-02'));

        $this->probe()->probeEscalate(new DispoEscalationPlanner(), new DispoChannelResolver(), new DispoEmployeeGateway(), $today, false);

        $this->assertNotNull(RecDispoAssignment::find($a->id)->escalation_1_at, 'Stufe 1 feuert heute, obwohl der Einsatz erst in 4 Tagen ist');
        $this->assertSame(1, $this->stub->calls);
    }

    /** Modus "datum": an jedem ANDEREN Tag als dem gewaehlten Eskalationsdatum bleibt die VA stumm. */
    public function test_datum_mode_is_silent_on_other_days(): void
    {
        $today = new \DateTimeImmutable('2026-08-29 14:05:00');
        $event = RecDispoEvent::create(['einsatz_ref' => 'RG-ESC-DATUM-OFF', 'name' => 'Datum-Modus', 'filial_nr' => self::FILIAL_NR]);
        RecDispoEvent::whereKey($event->id)->update(['escalation_day' => 'datum', 'escalation_date' => '2026-08-30']);
        $a = RecDispoAssignment::create($this->baseRow($event->id, 'DS-DATUM-OFF', '2026-09-02'));

        $this->probe()->probeEscalate(new DispoEscalationPlanner(), new DispoChannelResolver(), new DispoEmployeeGateway(), $today, false);

        $this->assertNull(RecDispoAssignment::find($a->id)->escalation_1_at);
        $this->assertSame(0, $this->stub->calls);
    }

    public function test_event_time_override_wins_over_settings_in_vortag_mode(): void
    {
        $event = RecDispoEvent::create([
            'einsatz_ref' => 'RG-ESC-TIMES', 'name' => 'Spaet', 'filial_nr' => self::FILIAL_NR,
            'escalation_time_1' => '18:00', 'escalation_time_2' => '19:00', 'escalation_time_3' => '20:00',
        ]);
        RecDispoAssignment::create($this->baseRow($event->id, 'DS-TIMES', '2026-08-26'));

        $early = $this->probe()->probeEscalate(new DispoEscalationPlanner(), new DispoChannelResolver(), new DispoEmployeeGateway(), $this->at('2026-08-25 14:30:00'));
        $this->assertSame(1, $early['population']);
        $this->assertSame(0, $early['stage1'], 'Settings sagen 14:00, VA sagt 18:00 -> noch nichts.');

        $late = $this->probe()->probeEscalate(new DispoEscalationPlanner(), new DispoChannelResolver(), new DispoEmployeeGateway(), $this->at('2026-08-25 18:05:00'));
        $this->assertSame(1, $late['stage1']);
    }

    public function test_mixed_modes_in_one_run(): void
    {
        $vortag = RecDispoEvent::create(['einsatz_ref' => 'RG-MIX-V', 'name' => 'V', 'filial_nr' => self::FILIAL_NR]);
        $same = RecDispoEvent::create([
            'einsatz_ref' => 'RG-MIX-S', 'name' => 'S', 'filial_nr' => self::FILIAL_NR,
            'escalation_day' => 'einsatztag', 'escalation_time_1' => '13:00', 'escalation_time_2' => '13:30', 'escalation_time_3' => '13:45',
        ]);
        RecDispoAssignment::create($this->baseRow($vortag->id, 'DS-MIX-V', '2026-08-26'));
        RecDispoAssignment::create($this->baseRow($same->id, 'DS-MIX-S', '2026-08-25'));

        $report = $this->probe()->probeEscalate(new DispoEscalationPlanner(), new DispoChannelResolver(), new DispoEmployeeGateway(), $this->at('2026-08-25 14:01:00'));

        $this->assertSame(2, $report['population']);
        $this->assertSame(1, $report['stage1'], 'Vortag-VA: Stufe 1 (14:00).');
        $this->assertSame(1, $report['stage3'], 'Einsatztag-VA: 13:45 ueberschritten -> Stufe 3.');
        $this->assertNotNull(RecDispoAssignment::where('ds_ref', 'DS-MIX-S')->value('deletion_marked_at'));
        $this->assertNull(RecDispoAssignment::where('ds_ref', 'DS-MIX-V')->value('deletion_marked_at'));
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

    /**
     * Runde-3-Nachzug: Stufe 2 darf die Rausnahme-Uhrzeit als {{5}} tragen — aber NUR,
     * wenn der Template-Body den Platzhalter enthaelt (Meta prueft die Parameterzahl
     * exakt; altes 4er-Template muss weiter funktionieren).
     */
    public function test_stage2_sends_four_parameters_when_template_has_no_deadline_placeholder(): void
    {
        $event = RecDispoEvent::create(['einsatz_ref' => 'RG-ESC-S2-OLD', 'name' => 'Alt', 'filial_nr' => self::FILIAL_NR]);
        RecDispoAssignment::create($this->baseRow($event->id, 'DS-S2-OLD', '2026-08-26'));

        $report = $this->probe()->probeEscalate(new DispoEscalationPlanner(), new DispoChannelResolver(), new DispoEmployeeGateway(), $this->at('2026-08-25 15:01:00'));

        $this->assertSame(1, $report['stage2']);
        $params = $this->stub->log[0]['components'][0]['parameters'];
        $this->assertCount(4, $params, 'Template ohne {{5}} -> genau vier Body-Parameter.');
    }

    public function test_stage2_passes_event_deadline_as_fifth_parameter_when_template_uses_it(): void
    {
        $accountId = IntegrationsWhatsAppTemplate::find(self::$template2Id)->whatsapp_account_id;
        $finalV2 = IntegrationsWhatsAppTemplate::create([
            'external_id' => 'ext-esc-2-v2', 'name' => 'dispo_final_v2', 'language' => 'de', 'status' => 'APPROVED',
            'components' => [['type' => 'BODY', 'text' => 'Final {{1}} bis {{5}} Uhr']], 'whatsapp_account_id' => $accountId, 'user_id' => 1,
        ]);
        $settings = RecApplicantSettings::where('team_id', self::TEAM)->first();
        $settings->settings = array_merge(self::baselineSettings(), ['dispo_escalation_template_2_id' => $finalV2->id]);
        $settings->save();

        // VA-Override: Stufe 3 um 20:00 -> genau diese Uhrzeit muss als {{5}} rausgehen (nicht der Team-Default 16:00).
        $event = RecDispoEvent::create([
            'einsatz_ref' => 'RG-ESC-S2-V2', 'name' => 'Neu', 'filial_nr' => self::FILIAL_NR,
            'escalation_time_1' => '18:00', 'escalation_time_2' => '19:00', 'escalation_time_3' => '20:00',
        ]);
        RecDispoAssignment::create($this->baseRow($event->id, 'DS-S2-V2', '2026-08-26'));

        $report = $this->probe()->probeEscalate(new DispoEscalationPlanner(), new DispoChannelResolver(), new DispoEmployeeGateway(), $this->at('2026-08-25 19:05:00'));

        $this->assertSame(1, $report['stage2']);
        $this->assertSame('dispo_final_v2', $this->stub->log[0]['templateName']);
        $params = $this->stub->log[0]['components'][0]['parameters'];
        $this->assertCount(5, $params, 'Template mit {{5}} -> fuenf Body-Parameter.');
        $this->assertSame('20:00', $params[4]['text'], '{{5}} = effektive Stufe-3-Uhrzeit der VA.');
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

    /**
     * Meta-`failed`-Pfad Stufe 1: kein Wurf, das Message-Objekt sagt failed —
     * das ist eine DEFINITIVE Ablehnung (anders als eine Exception): trotzdem
     * stempeln (feuert einmal), kein Retry im idempotenten Re-Lauf.
     */
    public function test_stage1_meta_failed_status_still_stamps_once_and_is_not_retried(): void
    {
        $event = RecDispoEvent::create(['einsatz_ref' => 'RG-ESC-FAIL1', 'name' => 'Test-VA', 'filial_nr' => self::FILIAL_NR]);
        RecDispoAssignment::create([
            'ds_ref' => 'DS-FAIL1', 'rec_dispo_event_id' => $event->id, 'pnr_raw' => 'RG' . self::$employeeId,
            'rec_employee_id' => self::$employeeId, 'datum' => '2026-08-26', 'von' => '16:00', 'bis' => '22:00',
            'status_id' => RecDispoAssignment::STATUS_AUFTRAG, 'reminder_sent_at' => '2026-08-20 10:00:00',
        ]);

        $employeePhone = RecEmployee::find(self::$employeeId)->phone;
        $this->stub->failFor[] = $employeePhone;

        $planner = new DispoEscalationPlanner();
        $resolver = new DispoChannelResolver();
        $gateway = new DispoEmployeeGateway();

        $first = $this->probe()->probeEscalate($planner, $resolver, $gateway, $this->at('2026-08-25 14:01:00'), false);
        $this->assertSame(1, $first['stage1']);
        $this->assertSame(1, $this->stub->calls, 'Genau ein Sende-Versuch trotz failed-Status.');

        $row = RecDispoAssignment::where('ds_ref', 'DS-FAIL1')->first();
        $this->assertNotNull($row->escalation_1_at, 'Meta failed ist eine definitive Ablehnung -> trotzdem gestempelt.');
        $this->assertNotNull($row->escalation_1_message_id);
        $firstAt = $row->escalation_1_at;
        $firstMessageId = $row->escalation_1_message_id;

        // Zweiter Lauf im selben Fenster -> kein Retry trotz failed-Status.
        $second = $this->probe()->probeEscalate($planner, $resolver, $gateway, $this->at('2026-08-25 14:05:00'), false);
        $this->assertSame(0, $second['stage1']);
        $this->assertSame(1, $this->stub->calls, 'Kein zweiter Sende-Versuch — failed wird nicht alle 5 Minuten neu versucht.');

        $row->refresh();
        $this->assertSame($firstAt->toDateTimeString(), $row->escalation_1_at->toDateTimeString());
        $this->assertSame($firstMessageId, $row->escalation_1_message_id);
    }

    /**
     * Meta-`failed`-Pfad Alarm: gleiche Regel wie Stufe 1/2 — stempeln (feuert
     * einmal), kein Re-Alarm. Der zweite Lauf feuert hier ohnehin nicht erneut,
     * weil die Einbuchung nach Stufe 3 aus der Zielmenge faellt
     * (deletion_marked_at gesetzt) — das ist der Idempotenz-Beleg.
     */
    public function test_alarm_meta_failed_status_stamps_once_and_is_not_retried(): void
    {
        $event = RecDispoEvent::create(['einsatz_ref' => 'RG-ESC-FAIL3', 'name' => 'Test-VA-Alarm', 'filial_nr' => self::FILIAL_NR]);
        RecDispoAssignment::create([
            'ds_ref' => 'DS-FAIL3', 'rec_dispo_event_id' => $event->id, 'pnr_raw' => 'RG' . self::$employeeId,
            'rec_employee_id' => self::$employeeId, 'datum' => '2026-08-26', 'von' => '16:00', 'bis' => '22:00',
            'status_id' => RecDispoAssignment::STATUS_AUFTRAG, 'reminder_sent_at' => '2026-08-20 10:00:00',
        ]);

        $this->stub->failFor[] = self::DUTY_PHONE;

        $planner = new DispoEscalationPlanner();
        $resolver = new DispoChannelResolver();
        $gateway = new DispoEmployeeGateway();

        $report = $this->probe()->probeEscalate($planner, $resolver, $gateway, $this->at('2026-08-25 16:01:00'), false);
        $this->assertSame(1, $report['stage3']);
        $this->assertSame(1, $this->stub->calls);

        $event->refresh();
        $this->assertNotNull($event->alarm_message_id, 'Trotz Meta failed-Status wird der Alarm gestempelt (feuert einmal).');

        $second = $this->probe()->probeEscalate($planner, $resolver, $gateway, $this->at('2026-08-25 16:05:00'), false);
        $this->assertSame(0, $second['stage3'], 'Die Einbuchung ist jetzt deletion_marked_at -> faellt aus der Zielmenge.');
        $this->assertSame(1, $this->stub->calls, 'Kein zweiter Alarm-Versuch.');
    }

    /**
     * DAS ist der Kern von Review-Fund A: eine geworfene Sende-Exception bei
     * EINER Zeile darf die restliche Zielmenge NICHT abbrechen. Zwei Zeilen
     * im selben Lauf, eine wirft — die andere muss trotzdem normal gestempelt
     * werden, und die geworfene bleibt ungestempelt (heilt sich selbst).
     */
    public function test_send_exception_does_not_stamp_and_does_not_abort_the_batch(): void
    {
        $second = RecEmployee::create([
            'team_id' => self::TEAM, 'first_name' => 'Klaus', 'last_name' => 'Zweit',
            'phone' => '+49 151 99999999', 'portal_token' => 'tok-dispo-escalate-batch', 'is_active' => true,
        ]);

        $event = RecDispoEvent::create(['einsatz_ref' => 'RG-ESC-EXC', 'name' => 'Test-VA', 'filial_nr' => self::FILIAL_NR]);
        $base = [
            'rec_dispo_event_id' => $event->id, 'datum' => '2026-08-26', 'von' => '16:00', 'bis' => '22:00',
            'status_id' => RecDispoAssignment::STATUS_AUFTRAG, 'reminder_sent_at' => '2026-08-20 10:00:00',
        ];
        RecDispoAssignment::create(array_merge($base, ['ds_ref' => 'DS-EXC-BAD', 'pnr_raw' => 'RG' . self::$employeeId, 'rec_employee_id' => self::$employeeId]));
        RecDispoAssignment::create(array_merge($base, ['ds_ref' => 'DS-EXC-OK', 'pnr_raw' => 'RG' . $second->id, 'rec_employee_id' => $second->id]));

        $badPhone = RecEmployee::find(self::$employeeId)->phone;
        $this->stub->throwFor[] = $badPhone;

        $report = $this->probe()->probeEscalate(
            new DispoEscalationPlanner(), new DispoChannelResolver(), new DispoEmployeeGateway(),
            $this->at('2026-08-25 14:01:00'), false
        );

        $this->assertSame(2, $report['stage1'], 'Beide Zeilen waren faellig (der Zaehler zaehlt Faelligkeit, nicht Erfolg).');
        $this->assertSame(2, $this->stub->calls, 'Fuer beide Zeilen wurde ein Sende-Versuch unternommen.');

        $bad = RecDispoAssignment::where('ds_ref', 'DS-EXC-BAD')->first();
        $this->assertNull($bad->escalation_1_at, 'Exception -> kein Stempel, heilt sich beim naechsten Lauf.');

        $ok = RecDispoAssignment::where('ds_ref', 'DS-EXC-OK')->first();
        $this->assertNotNull($ok->escalation_1_at, 'Zweite Zeile wird trotz Exception bei der ersten normal verarbeitet — Batch bricht nicht ab.');
        $this->assertNotNull($ok->escalation_1_message_id);
    }

    /** Optionaler Skip-Branch-Test: fehlendes Template -> kein Stempel, kein Crash. */
    public function test_missing_template_is_skipped_without_crash(): void
    {
        $settings = RecApplicantSettings::where('team_id', self::TEAM)->first();
        $overridden = self::baselineSettings();
        $overridden['dispo_escalation_template_1_id'] = null;
        $settings->settings = $overridden;
        $settings->save();

        $event = RecDispoEvent::create(['einsatz_ref' => 'RG-ESC-NOTPL', 'name' => 'Test-VA', 'filial_nr' => self::FILIAL_NR]);
        RecDispoAssignment::create([
            'ds_ref' => 'DS-NOTPL', 'rec_dispo_event_id' => $event->id, 'pnr_raw' => 'RG' . self::$employeeId,
            'rec_employee_id' => self::$employeeId, 'datum' => '2026-08-26',
            'status_id' => RecDispoAssignment::STATUS_AUFTRAG, 'reminder_sent_at' => '2026-08-20 10:00:00',
        ]);

        $report = $this->probe()->probeEscalate(
            new DispoEscalationPlanner(), new DispoChannelResolver(), new DispoEmployeeGateway(),
            $this->at('2026-08-25 14:01:00'), false
        );

        $this->assertSame(1, $report['stage1'], 'Zaehlt weiterhin als faellig.');
        $this->assertSame(0, $this->stub->calls, 'Ohne Template wird gar nicht gesendet — kein Crash.');

        $row = RecDispoAssignment::where('ds_ref', 'DS-NOTPL')->first();
        $this->assertNull($row->escalation_1_at);
    }

    /**
     * F3 (Final-Review): der Dedup pro Person/VA sendet den EINEN Reminder mit
     * den Zeiten der chronologisch ERSTEN Zeile — die Zielmengen-Query muss
     * dafuer nach datum/von sortiert sein, unabhaengig von der Insert-Reihenfolge.
     */
    public function test_target_query_is_ordered_so_dedup_uses_earliest_shift(): void
    {
        $event = RecDispoEvent::create(['einsatz_ref' => 'RG-ESC-ORDER', 'name' => 'Doppelschicht', 'filial_nr' => self::FILIAL_NR]);
        // Absichtlich in umgekehrter chronologischer Reihenfolge angelegt: die
        // spaete Schicht zuerst, die fruehe Schicht danach.
        RecDispoAssignment::create(array_merge($this->baseRow($event->id, 'DS-LATE', '2026-08-26'), ['von' => '18:00', 'bis' => '22:00']));
        RecDispoAssignment::create(array_merge($this->baseRow($event->id, 'DS-EARLY', '2026-08-26'), ['von' => '10:00', 'bis' => '12:00']));

        $report = $this->probe()->probeEscalate(new DispoEscalationPlanner(), new DispoChannelResolver(), new DispoEmployeeGateway(), $this->at('2026-08-25 14:01:00'));

        $this->assertSame(2, $report['stage1'], 'Beide Zeilen sind faellig.');
        $this->assertSame(1, $this->stub->calls, 'Genau EIN Reminder pro Person/VA.');
        $params = $this->stub->log[0]['components'][0]['parameters'];
        $this->assertSame('10:00 bis 12:00', $params[3]['text'], 'Der Reminder traegt die Zeiten der chronologisch ERSTEN Schicht.');

        $this->assertNotNull(RecDispoAssignment::where('ds_ref', 'DS-LATE')->value('escalation_1_at'));
        $this->assertNotNull(RecDispoAssignment::where('ds_ref', 'DS-EARLY')->value('escalation_1_at'));
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
            // personnel_number/company: contacts() selektiert die Spalten — fehlen sie im Schema, liefert SQLite den Spaltennamen als String-Literal statt eines Fehlers (kein Test-Fail, falsche Daten).
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
            [$own, 'database/migrations/2026_08_27_000002_add_escalation_override_to_rec_dispo_events.php'],
            [$own, 'database/migrations/2026_08_28_000001_add_escalation_date_to_rec_dispo_events.php'],
            [$own, 'database/migrations/2026_08_24_000004_add_portal_lock_to_rec_employees.php'],
            [$own, 'database/migrations/2026_02_09_000008_create_rec_applicant_settings_table.php'],
            [$crm, 'database/migrations/2026_01_14_000003_create_comms_channels_table.php'],
            [$crm, 'database/migrations/2024_01_01_000020_create_crm_contact_links_table.php'],
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
