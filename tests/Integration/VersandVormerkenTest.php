<?php

namespace Platform\Recruiting\Tests\Integration;

use Illuminate\Container\Container;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use Platform\Core\Models\CoreExtraFieldDefinition;
use Platform\Recruiting\Models\RecApplicant;
use Platform\Recruiting\Models\RecContractSendReservation;
use Platform\Recruiting\Models\RecPhase;

/**
 * Vertragsversand vormerken (Spec 06.10.2026). Eine Klasse fuer alles mit DB:
 * Vormerkung, Erinnerung (Drossel), Klick-Ablauf, Ausloeser, Job-Pruefstufen,
 * Aufraeumen. Aufbau wie AnzeigeVerknuepfenTest, aber MIT Dispatcher.
 */
final class VersandVormerkenTest extends TestCase
{
    private const TEAM = 11;
    private const POSITION = 111;        // „Gladbach"
    private const POSITION_FREMD = 112;  // „Koeln"
    private const PHASE_1 = 211;         // Bewerbung (fields: vorname)
    private const PHASE_3 = 213;         // Onboarding (fields: strasse)
    private const PHASE_4 = 214;         // Vertraege (contract_sent, creates_employee)
    private const PHASE_FREMD = 215;
    private const APPLICANT = 5001;
    private const BOOKING = 7001;
    private const HEUTE = '2026-10-07 10:00:00';
    /** Fuer die anonyme Dispatch-Attrappe (self:: zeigt dort auf die anonyme Klasse). */
    public const HEUTE_OEFFENTLICH = self::HEUTE;

    public static function setUpBeforeClass(): void
    {
        $container = Container::getInstance();
        $capsule = new Capsule();
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $capsule->setEventDispatcher(new Dispatcher($container));
        $capsule->setAsGlobal();
        $capsule->bootEloquent();
        Model::unguard();
        Model::setEventDispatcher($capsule->getEventDispatcher());
        Model::clearBootedModels();

        $container->instance('db', $capsule->getDatabaseManager());
        $container->instance('db.schema', $capsule->getConnection()->getSchemaBuilder());
        Facade::setFacadeApplication($container);
        Facade::clearResolvedInstances();

        $schema = $capsule->getConnection()->getSchemaBuilder();
        foreach (['teams', 'users', 'hcm_job_titles', 'comms_channels'] as $fremd) {
            if (!$schema->hasTable($fremd)) {
                $schema->create($fremd, fn ($table) => $table->id());
            }
        }
        if (!$schema->hasTable('crm_contact_links')) {
            $schema->create('crm_contact_links', function ($table) {
                $table->id();
                $table->string('linkable_type');
                $table->unsignedBigInteger('linkable_id');
                $table->timestamps();
            });
        }

        $container->instance(AuthFactory::class, new class(self::TEAM) implements AuthFactory {
            public function __construct(private int $teamId) {}
            public function user(): object
            {
                return new class($this->teamId) {
                    public object $currentTeam;
                    public int $id = 7;
                    public function __construct(int $teamId) { $this->currentTeam = (object) ['id' => $teamId]; }
                };
            }
            public function guard($name = null) { return $this; }
            public function shouldUse($name) {}
        });

        self::runRealMigrations();
        self::seed();
    }

    public static function tearDownAfterClass(): void
    {
        RecApplicant::flushEventListeners();
        \Platform\Recruiting\Models\RecInterviewBooking::flushEventListeners();
        \Platform\Recruiting\Models\RecApplicantLegalStatus::flushEventListeners();
        Model::unsetEventDispatcher();
        Model::clearBootedModels();
        Container::getInstance()->forgetInstance(AuthFactory::class);
        Facade::clearResolvedInstances();
    }

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(self::HEUTE);
        self::ausgangszustand();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** Bewerber 5001 zurueck auf Phase 3, keine Vormerkungen, keine Vertraege, keine Logs. */
    private static function ausgangszustand(): void
    {
        Capsule::table('rec_applicants')->where('id', self::APPLICANT)->update([
            'rec_position_id' => self::POSITION, 'rec_phase_id' => self::PHASE_3,
            'is_active' => 1, 'is_parked' => 0, 'rejected_at' => null,
            'contract_template_id' => 900, 'zuschlag' => 1.1,
        ]);
        Capsule::table('rec_contract_send_reservations')->delete();
        Capsule::table('rec_contracts')->delete();
        Capsule::table('rec_employees')->delete();
        Capsule::table('rec_hr_desk_cases')->delete();
        Capsule::table('rec_applicant_legal_statuses')->delete();
        Capsule::table('rec_auto_pilot_logs')->delete();
        Capsule::table('core_extra_field_values')->where('fieldable_id', self::APPLICANT)->delete();
        Capsule::table('rec_interview_bookings')->where('id', self::BOOKING)->update(['status' => 'attended', 'deleted_at' => null]);
    }

    private function bewerber(): RecApplicant
    {
        return RecApplicant::find(self::APPLICANT);
    }

    private function strasseAusfuellen(): void
    {
        Capsule::table('core_extra_field_values')->insert([
            'definition_id' => self::definitionId(self::PHASE_3, 'strasse'),
            'fieldable_type' => (new RecApplicant())->getMorphClass(), 'fieldable_id' => self::APPLICANT,
            'value' => 'Bootstraße 8', 'created_at' => self::HEUTE, 'updated_at' => self::HEUTE,
        ]);
    }

    private static function definitionId(int $phaseId, string $name): int
    {
        return (int) CoreExtraFieldDefinition::query()
            ->where('context_type', RecPhase::class)->where('context_id', $phaseId)->where('name', $name)->value('id');
    }

    private function logs(string $type): \Illuminate\Support\Collection
    {
        return Capsule::table('rec_auto_pilot_logs')->where('rec_applicant_id', self::APPLICANT)->where('type', $type)->get();
    }

    private static function runRealMigrations(): void
    {
        $core = self::packageRootOf(CoreExtraFieldDefinition::class);

        $files = [
            $core . '/database/migrations/2026_02_07_000001_create_core_extra_field_definitions_table.php',
            $core . '/database/migrations/2026_02_07_000002_create_core_extra_field_values_table.php',
        ];

        $own = glob(dirname(__DIR__, 2) . '/database/migrations/*.php');
        sort($own);

        foreach (array_merge($files, $own) as $path) {
            if (!file_exists($path)) {
                throw new \RuntimeException("Migration fehlt: {$path}");
            }
            $migration = require $path;
            $migration->up();
        }
    }

    private static function packageRootOf(string $class): string
    {
        $dir = dirname((new \ReflectionClass($class))->getFileName());

        for ($i = 0; $i < 10; $i++) {
            if (is_dir($dir . '/database/migrations')) {
                return $dir;
            }
            $parent = dirname($dir);
            if ($parent === $dir) {
                break;
            }
            $dir = $parent;
        }

        throw new \RuntimeException('Paketwurzel nicht gefunden: ' . $class);
    }

    private static function seed(): void
    {
        $now = self::HEUTE;
        Capsule::table('rec_positions')->insert([
            ['id' => self::POSITION, 'uuid' => 'vv-pos-111', 'team_id' => self::TEAM, 'title' => 'Moenchengladbach allgemein', 'location' => 'MG', 'is_active' => 1, 'is_sammelstelle' => 0, 'created_at' => $now, 'updated_at' => $now],
            ['id' => self::POSITION_FREMD, 'uuid' => 'vv-pos-112', 'team_id' => self::TEAM, 'title' => 'Koeln allgemein', 'location' => 'K', 'is_active' => 1, 'is_sammelstelle' => 0, 'created_at' => $now, 'updated_at' => $now],
        ]);
        Capsule::table('rec_phases')->insert([
            ['id' => self::PHASE_1, 'uuid' => 'vv-ph-211', 'team_id' => self::TEAM, 'rec_position_id' => self::POSITION, 'name' => 'Bewerbung', 'order' => 1, 'completion_type' => 'fields', 'is_active' => 1, 'completion_config' => null, 'auto_pilot_settings' => json_encode(['auto_pilot_wa_initial_template_id' => 25]), 'created_at' => $now, 'updated_at' => $now],
            ['id' => self::PHASE_3, 'uuid' => 'vv-ph-213', 'team_id' => self::TEAM, 'rec_position_id' => self::POSITION, 'name' => 'Onboarding (Bestätigung)', 'order' => 3, 'completion_type' => 'fields', 'is_active' => 1, 'completion_config' => null, 'auto_pilot_settings' => json_encode(['auto_pilot_wa_initial_template_id' => 28]), 'created_at' => $now, 'updated_at' => $now],
            ['id' => self::PHASE_4, 'uuid' => 'vv-ph-214', 'team_id' => self::TEAM, 'rec_position_id' => self::POSITION, 'name' => 'Schulung & Verträge versenden', 'order' => 4, 'completion_type' => 'contract_sent', 'completion_config' => json_encode(['creates_employee_on_completion' => true]), 'is_active' => 1, 'auto_pilot_settings' => null, 'created_at' => $now, 'updated_at' => $now],
            ['id' => self::PHASE_FREMD, 'uuid' => 'vv-ph-215', 'team_id' => self::TEAM, 'rec_position_id' => self::POSITION_FREMD, 'name' => 'Bewerbung', 'order' => 1, 'completion_type' => 'fields', 'is_active' => 1, 'auto_pilot_settings' => null, 'completion_config' => null, 'created_at' => $now, 'updated_at' => $now],
        ]);
        CoreExtraFieldDefinition::query()->insert([
            ['team_id' => self::TEAM, 'context_type' => RecPhase::class, 'context_id' => self::PHASE_1, 'name' => 'vorname', 'label' => 'Vorname', 'type' => 'text', 'is_required' => 1, 'order' => 1, 'options' => null, 'created_at' => $now, 'updated_at' => $now],
            ['team_id' => self::TEAM, 'context_type' => RecPhase::class, 'context_id' => self::PHASE_3, 'name' => 'strasse', 'label' => 'Straße', 'type' => 'text', 'is_required' => 1, 'order' => 1, 'options' => null, 'created_at' => $now, 'updated_at' => $now],
        ]);
        Capsule::table('rec_contract_templates')->insert([
            'id' => 900, 'uuid' => 'vv-tpl-900', 'team_id' => self::TEAM, 'code' => 'AV-default', 'name' => 'AV', 'is_active' => 1, 'created_at' => $now, 'updated_at' => $now,
        ]);
        Capsule::table('rec_interviews')->insert([
            'id' => 8001, 'uuid' => 'vv-int-8001', 'team_id' => self::TEAM, 'rec_position_id' => self::POSITION, 'starts_at' => '2026-10-07 18:00:00', 'status' => 'planned', 'is_active' => 1, 'created_at' => $now, 'updated_at' => $now,
        ]);
        Capsule::table('rec_applicants')->insert([
            'id' => self::APPLICANT, 'uuid' => 'vv-app-5001', 'team_id' => self::TEAM, 'applied_at' => '2026-10-05',
            'rec_position_id' => self::POSITION, 'rec_phase_id' => self::PHASE_3, 'is_test' => 0, 'is_active' => 1,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        Capsule::table('rec_interview_bookings')->insert([
            'id' => self::BOOKING, 'uuid' => 'vv-bk-7001', 'team_id' => self::TEAM, 'rec_interview_id' => 8001,
            'rec_applicant_id' => self::APPLICANT, 'status' => 'attended', 'created_at' => $now, 'updated_at' => $now,
        ]);
    }

    public function test_offene_vormerkung_wird_gefunden_und_abgeschlossene_nicht(): void
    {
        Capsule::table('rec_contract_send_reservations')->insert([
            ['team_id' => self::TEAM, 'rec_applicant_id' => self::APPLICANT, 'reserved_at' => self::HEUTE, 'vertragsbeginn' => null, 'completed_at' => self::HEUTE, 'created_at' => self::HEUTE, 'updated_at' => self::HEUTE],
            ['team_id' => self::TEAM, 'rec_applicant_id' => self::APPLICANT, 'reserved_at' => self::HEUTE, 'vertragsbeginn' => '2026-11-01', 'completed_at' => null, 'created_at' => self::HEUTE, 'updated_at' => self::HEUTE],
        ]);

        $offen = $this->bewerber()->offeneVersandVormerkung();

        $this->assertNotNull($offen);
        $this->assertSame('2026-11-01', $offen->vertragsbeginn->format('Y-m-d'));
        $this->assertSame(['vertragsbeginn' => '2026-11-01', 'vertragsende' => null], $offen->contractFields());
    }

    /** Dienst mit Erinnerungs-Attrappe: zaehlt Aufrufe, Ergebnis einstellbar. */
    private array $erinnerungen = [];
    private array $erinnerungsErgebnis = ['ok' => true, 'error' => null];

    private function dienst(): \Platform\Recruiting\Services\ContractSendReservationService
    {
        $calls = &$this->erinnerungen;
        $ergebnis = &$this->erinnerungsErgebnis;

        return new class($calls, $ergebnis) extends \Platform\Recruiting\Services\ContractSendReservationService {
            public function __construct(private array &$calls, private array &$ergebnis) {}
            protected function erinnerungSenden(RecApplicant $a): array
            {
                $this->calls[] = $a->id;
                return $this->ergebnis;
            }
        };
    }

    public function test_vormerken_legt_an_loggt_und_erinnert(): void
    {
        $r = $this->dienst()->vormerken($this->bewerber(), self::BOOKING, '2026-11-01', null, 'nachbereitung', 7, 'Clara', ['Straße']);

        $this->assertTrue($r->istOffen());
        $this->assertSame('2026-11-01', $r->vertragsbeginn->format('Y-m-d'));
        $this->assertSame(7, (int) $r->reserved_by_user_id);
        $this->assertSame([self::APPLICANT], $this->erinnerungen);
        $this->assertSame(self::HEUTE, $r->fresh()->last_reminder_at->format('Y-m-d H:i:s'));
        $this->assertCount(1, $this->logs('contract_send_reserved'));
        $this->assertStringContainsString('Clara', $this->logs('contract_send_reserved')->first()->summary);
        $this->assertStringContainsString('Straße', $this->logs('contract_send_reserved')->first()->summary);
    }

    public function test_zweites_vormerken_aktualisiert_die_offene_und_erinnert_nicht_erneut(): void
    {
        $dienst = $this->dienst();
        $erste = $dienst->vormerken($this->bewerber(), self::BOOKING, '2026-11-01', null, 'nachbereitung', 7, 'Clara', ['Straße']);
        Carbon::setTestNow('2026-10-07 11:00:00');

        $zweite = $dienst->vormerken($this->bewerber(), self::BOOKING, '2026-11-15', '2027-11-14', 'hr_desk', 8, 'HR', ['Straße']);

        $this->assertSame($erste->id, $zweite->id, 'hoechstens eine offene Vormerkung');
        $this->assertSame('2026-11-15', $zweite->vertragsbeginn->format('Y-m-d'));
        $this->assertSame('hr_desk', $zweite->source);
        $this->assertSame(1, Capsule::table('rec_contract_send_reservations')->count());
        $this->assertCount(1, $this->erinnerungen, 'Drossel: keine zweite Erinnerung binnen 24 h');
        $this->assertCount(2, $this->logs('contract_send_reserved'));
    }

    public function test_erinnerung_nach_24h_wieder_und_mit_force_sofort(): void
    {
        $dienst = $this->dienst();
        $r = $dienst->vormerken($this->bewerber(), self::BOOKING, '2026-11-01', null, 'nachbereitung', 7, 'Clara', []);

        Carbon::setTestNow('2026-10-08 09:59:00');
        $this->assertFalse($dienst->erinnern($r->fresh()));
        Carbon::setTestNow('2026-10-08 10:01:00');
        $this->assertTrue($dienst->erinnern($r->fresh()));
        $this->assertTrue($dienst->erinnern($r->fresh(), force: true));
        $this->assertCount(3, $this->erinnerungen);
    }

    public function test_vormerken_gelingt_auch_wenn_die_erinnerung_scheitert(): void
    {
        $this->erinnerungsErgebnis = ['ok' => false, 'error' => 'Keine Telefonnummer.'];

        $r = $this->dienst()->vormerken($this->bewerber(), self::BOOKING, '2026-11-01', null, 'nachbereitung', 7, 'Clara', []);

        $this->assertTrue($r->istOffen());
        $this->assertNull($r->fresh()->last_reminder_at);
        $log = $this->logs('contract_send_reminder')->first();
        $this->assertNotNull($log);
        $this->assertStringContainsString('Keine Telefonnummer', $log->summary);
    }

    public function test_zuruecknehmen_und_abschliessen(): void
    {
        $dienst = $this->dienst();
        $r = $dienst->vormerken($this->bewerber(), self::BOOKING, '2026-11-01', null, 'nachbereitung', 7, 'Clara', []);

        $dienst->zuruecknehmen($r, 'Buchung abgesagt', null);
        $this->assertFalse($r->fresh()->istOffen());
        $this->assertSame('Buchung abgesagt', $r->fresh()->cancel_reason);
        $this->assertCount(1, $this->logs('contract_send_cancelled'));
        $this->assertNull($this->bewerber()->offeneVersandVormerkung());

        $r2 = $dienst->vormerken($this->bewerber(), self::BOOKING, '2026-11-01', null, 'nachbereitung', 7, 'Clara', []);
        $dienst->abschliessen($r2, 'automatisch versendet');
        $this->assertNotNull($r2->fresh()->completed_at);
        $this->assertSame(2, Capsule::table('rec_contract_send_reservations')->count(), 'Historie bleibt');
    }

    private array $gesendet = [];

    private function klickLauf(): \Platform\Recruiting\Services\ContractSendRun
    {
        $gesendet = &$this->gesendet;
        $dispatch = new class($gesendet) extends \Platform\Recruiting\Services\ContractDispatchService {
            public function __construct(private array &$gesendet) {}
            public function sendForApplicant(RecApplicant $applicant, ?int $userId, ?array $contractFields, ?\Platform\Recruiting\Models\RecContractTemplate $defaultTemplate): array
            {
                $this->gesendet[] = [$applicant->id, $contractFields];
                Capsule::table('rec_contracts')->insert(['uuid' => 'c-' . $applicant->id . '-' . count($this->gesendet), 'team_id' => $applicant->team_id, 'rec_applicant_id' => $applicant->id, 'rec_contract_template_id' => 900, 'status' => 'sent', 'sent_at' => VersandVormerkenTest::HEUTE_OEFFENTLICH, 'created_at' => VersandVormerkenTest::HEUTE_OEFFENTLICH, 'updated_at' => VersandVormerkenTest::HEUTE_OEFFENTLICH]);
                return ['status' => 'sent', 'portal_sent' => true, 'message' => null];
            }
        };

        return new \Platform\Recruiting\Services\ContractSendRun($dispatch, $this->dienst());
    }

    public function test_klick_sendet_bereite_merkt_unvollstaendige_vor_und_sperrt_fremde(): void
    {
        // 5001: Phase 3, Strasse leer → unvollstaendig
        // 5002: Phase 4 → bereit
        // 5003: Phase fremder Stelle → gesperrt
        Capsule::table('rec_applicants')->insert([
            ['id' => 5002, 'uuid' => 'vv-app-5002', 'team_id' => self::TEAM, 'rec_position_id' => self::POSITION, 'rec_phase_id' => self::PHASE_4, 'contract_template_id' => 900, 'zuschlag' => 1.1, 'is_test' => 0, 'is_active' => 1, 'created_at' => self::HEUTE, 'updated_at' => self::HEUTE],
            ['id' => 5003, 'uuid' => 'vv-app-5003', 'team_id' => self::TEAM, 'rec_position_id' => self::POSITION, 'rec_phase_id' => self::PHASE_FREMD, 'contract_template_id' => 900, 'zuschlag' => 1.1, 'is_test' => 0, 'is_active' => 1, 'created_at' => self::HEUTE, 'updated_at' => self::HEUTE],
        ]);
        Capsule::table('rec_interview_bookings')->insert([
            ['id' => 7002, 'uuid' => 'vv-bk-7002', 'team_id' => self::TEAM, 'rec_interview_id' => 8001, 'rec_applicant_id' => 5002, 'status' => 'attended', 'created_at' => self::HEUTE, 'updated_at' => self::HEUTE],
            ['id' => 7003, 'uuid' => 'vv-bk-7003', 'team_id' => self::TEAM, 'rec_interview_id' => 8001, 'rec_applicant_id' => 5003, 'status' => 'attended', 'created_at' => self::HEUTE, 'updated_at' => self::HEUTE],
        ]);
        try {
            $bookings = \Platform\Recruiting\Models\RecInterviewBooking::with('applicant')->whereIn('id', [self::BOOKING, 7002, 7003])->get();
            $daten = [self::APPLICANT => ['vertragsbeginn' => '2026-11-01'], 5002 => ['vertragsbeginn' => '2026-11-01'], 5003 => ['vertragsbeginn' => '2026-11-01']];

            $ergebnis = $this->klickLauf()->ausfuehren($bookings, $daten, null, 7, 'Clara', 'nachbereitung');

            $this->assertSame([5002], $ergebnis->versendet);
            $this->assertSame([self::APPLICANT], $ergebnis->vorgemerkt);
            $this->assertArrayHasKey(5003, $ergebnis->gesperrt);
            $this->assertSame([], $ergebnis->fehler);
            $this->assertNotNull($this->bewerber()->offeneVersandVormerkung());
            $this->assertSame('2026-11-01', $this->bewerber()->offeneVersandVormerkung()->vertragsbeginn->format('Y-m-d'));
            $this->assertStringContainsString('1 versendet', $ergebnis->meldung());
            $this->assertStringContainsString('1 vorgemerkt', $ergebnis->meldung());
            $this->assertStringContainsString('1 gesperrt', $ergebnis->meldung());
        } finally {
            Capsule::table('rec_interview_bookings')->whereIn('id', [7002, 7003])->delete();
            Capsule::table('rec_applicants')->whereIn('id', [5002, 5003])->delete();
        }
    }

    public function test_klick_ohne_vertragsbeginn_merkt_nicht_vor(): void
    {
        $bookings = \Platform\Recruiting\Models\RecInterviewBooking::with('applicant')->whereKey(self::BOOKING)->get();

        $ergebnis = $this->klickLauf()->ausfuehren($bookings, [self::APPLICANT => ['vertragsbeginn' => null]], null, 7, 'Clara', 'nachbereitung');

        $this->assertSame([], $ergebnis->vorgemerkt);
        $this->assertArrayHasKey(self::APPLICANT, $ergebnis->fehler);
        $this->assertStringContainsString('Vertragsbeginn', $ergebnis->fehler[self::APPLICANT]);
        $this->assertNull($this->bewerber()->offeneVersandVormerkung());
    }

    public function test_klick_meldet_gesperrte_auch_ohne_vertragsbeginn_als_gesperrt(): void
    {
        Capsule::table('rec_applicants')->where('id', self::APPLICANT)->update(['rec_phase_id' => self::PHASE_FREMD]);
        $bookings = \Platform\Recruiting\Models\RecInterviewBooking::with('applicant')->whereKey(self::BOOKING)->get();

        $ergebnis = $this->klickLauf()->ausfuehren($bookings, [self::APPLICANT => ['vertragsbeginn' => null]], null, 7, 'Clara', 'nachbereitung');

        $this->assertArrayHasKey(self::APPLICANT, $ergebnis->gesperrt);
        $this->assertSame([], $ergebnis->fehler);
        $this->assertFalse($ergebnis->hatFehler());
    }

    public function test_fehlende_pflichtfelder_nutzt_vorgeladene_werte_ohne_eigene_abfrage(): void
    {
        $ohneVorladen = $this->bewerber()->fehlendePflichtfelder();
        $this->assertContains('Straße', $ohneVorladen);

        $applicant = $this->bewerber();
        $applicant->load('extraFieldValues');

        $werteAbfragen = 0;
        $zaehlen = true;
        Capsule::connection()->listen(function ($q) use (&$werteAbfragen, &$zaehlen) {
            if ($zaehlen && str_contains($q->sql, 'core_extra_field_values')) {
                $werteAbfragen++;
            }
        });
        try {
            $this->assertSame($ohneVorladen, $applicant->fehlendePflichtfelder());
            $this->assertSame(0, $werteAbfragen, 'Werte-Abfrage trotz vorgeladener Relation');
        } finally {
            $zaehlen = false;
        }

        // Gefuellte Strasse: vorgeladen und frisch gleich, Strasse fehlt nicht mehr.
        $this->strasseAusfuellen();
        $frisch = $this->bewerber()->fehlendePflichtfelder();
        $this->assertNotContains('Straße', $frisch);
        $applicant = $this->bewerber();
        $applicant->load('extraFieldValues');
        $this->assertSame($frisch, $applicant->fehlendePflichtfelder());
    }

    public function test_direktversand_schliesst_offene_vormerkung(): void
    {
        $r = $this->dienst()->vormerken($this->bewerber(), self::BOOKING, '2026-11-01', null, 'nachbereitung', 7, 'Clara', ['Straße']);
        Capsule::table('rec_applicants')->where('id', self::APPLICANT)->update(['rec_phase_id' => self::PHASE_4]);
        $this->strasseAusfuellen();
        $bookings = \Platform\Recruiting\Models\RecInterviewBooking::with('applicant')->whereKey(self::BOOKING)->get();

        $ergebnis = $this->klickLauf()->ausfuehren($bookings, [self::APPLICANT => ['vertragsbeginn' => '2026-11-01']], null, 7, 'Clara', 'nachbereitung');

        $this->assertSame([self::APPLICANT], $ergebnis->versendet);
        $this->assertNotNull($r->fresh()->completed_at);
        $this->assertNull($this->bewerber()->offeneVersandVormerkung());
    }
}
