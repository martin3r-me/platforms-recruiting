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

    public function test_direktversand_zaehlt_nicht_als_fehler_wenn_nur_das_abschliessen_scheitert(): void
    {
        $this->dienst()->vormerken($this->bewerber(), self::BOOKING, '2026-11-01', null, 'nachbereitung', 7, 'Clara', ['Straße']);
        Capsule::table('rec_applicants')->where('id', self::APPLICANT)->update(['rec_phase_id' => self::PHASE_4]);
        $this->strasseAusfuellen();
        $bookings = \Platform\Recruiting\Models\RecInterviewBooking::with('applicant')->whereKey(self::BOOKING)->get();

        $calls = &$this->erinnerungen;
        $ergebnisRef = &$this->erinnerungsErgebnis;
        $wirftBeimAbschliessen = new class($calls, $ergebnisRef) extends \Platform\Recruiting\Services\ContractSendReservationService {
            public function __construct(private array &$calls, private array &$ergebnis) {}
            public function abschliessen(RecContractSendReservation $reservation, string $ergebnis): void
            {
                throw new \RuntimeException('DB weg');
            }
        };
        $gesendet = &$this->gesendet;
        $dispatch = new class($gesendet) extends \Platform\Recruiting\Services\ContractDispatchService {
            public function __construct(private array &$gesendet) {}
            public function sendForApplicant(RecApplicant $applicant, ?int $userId, ?array $contractFields, ?\Platform\Recruiting\Models\RecContractTemplate $defaultTemplate): array
            {
                $this->gesendet[] = $applicant->id;
                return ['status' => 'sent', 'portal_sent' => true, 'message' => null];
            }
        };

        $ergebnis = (new \Platform\Recruiting\Services\ContractSendRun($dispatch, $wirftBeimAbschliessen))
            ->ausfuehren($bookings, [self::APPLICANT => ['vertragsbeginn' => '2026-11-01']], null, 7, 'Clara', 'nachbereitung');

        $this->assertSame([self::APPLICANT], $ergebnis->versendet);
        $this->assertSame([], $ergebnis->fehler, 'versendet ist versendet — ein Buchhaltungsfehler danach ist kein Versandfehler');
        $this->assertStringNotContainsString('Fehler', $ergebnis->meldung());
    }

    /**
     * Sender mit Dispatch-Attrappe (protokolliert Aufrufe in $gesendet, legt
     * einen gesendeten Vertrag an). HrDeskRoutingService laeuft ECHT — er
     * braucht im Capsule-Aufbau weder auth() noch app(), nur Eloquent + now().
     */
    private function sender(?\Platform\Recruiting\Services\ContractDispatchService $andereAttrappe = null): \Platform\Recruiting\Services\ReservedContractSender
    {
        if ($andereAttrappe !== null) {
            return new \Platform\Recruiting\Services\ReservedContractSender($andereAttrappe, $this->dienst(), new \Platform\Recruiting\Services\HrDeskRoutingService());
        }
        $gesendet = &$this->gesendet;
        $dispatch = new class($gesendet) extends \Platform\Recruiting\Services\ContractDispatchService {
            public function __construct(private array &$gesendet) {}
            public function sendForApplicant(RecApplicant $applicant, ?int $userId, ?array $contractFields, ?\Platform\Recruiting\Models\RecContractTemplate $defaultTemplate): array
            {
                $this->gesendet[] = [$applicant->id, $userId, $contractFields];
                Capsule::table('rec_contracts')->insert(['uuid' => 'c-' . $applicant->id . '-' . count($this->gesendet), 'team_id' => $applicant->team_id, 'rec_applicant_id' => $applicant->id, 'rec_contract_template_id' => 900, 'status' => 'sent', 'sent_at' => Carbon::now()->format('Y-m-d H:i:s'), 'created_at' => VersandVormerkenTest::HEUTE_OEFFENTLICH, 'updated_at' => VersandVormerkenTest::HEUTE_OEFFENTLICH]);
                return ['status' => 'sent', 'portal_sent' => true, 'message' => null];
            }
        };

        return new \Platform\Recruiting\Services\ReservedContractSender($dispatch, $this->dienst(), new \Platform\Recruiting\Services\HrDeskRoutingService());
    }

    private function vormerkung(string $beginn = '2026-11-01'): RecContractSendReservation
    {
        return $this->dienst()->vormerken($this->bewerber(), self::BOOKING, $beginn, null, 'nachbereitung', 7, 'Clara', ['Straße']);
    }

    private function inVertragsphase(): void
    {
        $this->strasseAusfuellen();
        Capsule::table('rec_applicants')->where('id', self::APPLICANT)->update(['rec_phase_id' => self::PHASE_4]);
    }

    public function test_job_ohne_vormerkung_tut_nichts(): void
    {
        $this->assertSame('keine_vormerkung', $this->sender()->versuchen(self::APPLICANT));
        $this->assertSame([], $this->gesendet);
    }

    public function test_job_versendet_mit_den_vorgemerkten_daten_und_schliesst_ab(): void
    {
        $r = $this->vormerkung();
        $this->inVertragsphase();

        $this->assertSame('versendet', $this->sender()->versuchen(self::APPLICANT));

        $this->assertSame([[self::APPLICANT, 7, ['vertragsbeginn' => '2026-11-01', 'vertragsende' => null]]], $this->gesendet);
        $this->assertNotNull($r->fresh()->completed_at);
        $this->assertNull($r->fresh()->claimed_at, 'Beleg nach dem Versand geloest');
        $this->assertCount(1, $this->logs('contract_send_auto_sent'));
        $this->assertStringContainsString('Clara', $this->logs('contract_send_auto_sent')->first()->summary);
    }

    public function test_job_zweiter_lauf_versendet_nicht_noch_einmal(): void
    {
        $this->vormerkung();
        $this->inVertragsphase();
        $sender = $this->sender();
        $sender->versuchen(self::APPLICANT);

        $this->assertSame('keine_vormerkung', $sender->versuchen(self::APPLICANT));
        $this->assertCount(1, $this->gesendet);
    }

    public function test_job_schliesst_ab_wenn_vertrag_auf_anderem_weg_raus_ist(): void
    {
        $r = $this->vormerkung();
        Capsule::table('rec_contracts')->insert(['uuid' => 'c-fremd', 'team_id' => self::TEAM, 'rec_applicant_id' => self::APPLICANT, 'rec_contract_template_id' => 900, 'status' => 'sent', 'sent_at' => self::HEUTE, 'created_at' => self::HEUTE, 'updated_at' => self::HEUTE]);

        $this->assertSame('bereits_versendet', $this->sender()->versuchen(self::APPLICANT));
        $this->assertNotNull($r->fresh()->completed_at);
        $this->assertSame([], $this->gesendet);
    }

    public function test_job_wartet_bei_unvollstaendig(): void
    {
        $r = $this->vormerkung();

        $this->assertSame('wartet_unvollstaendig', $this->sender()->versuchen(self::APPLICANT));
        $this->assertTrue($r->fresh()->istOffen());
        $this->assertStringContainsString('Straße', $r->fresh()->last_attempt_result);
        $this->assertCount(1, $this->logs('contract_send_waiting'));
    }

    public function test_job_wartet_bei_gesperrt_nach_umsetzen_auf_fremde_phase(): void
    {
        $r = $this->vormerkung();
        Capsule::table('rec_applicants')->where('id', self::APPLICANT)->update(['rec_phase_id' => self::PHASE_FREMD]);

        $this->assertSame('wartet_gesperrt', $this->sender()->versuchen(self::APPLICANT));
        $this->assertTrue($r->fresh()->istOffen());
        $this->assertSame([], $this->gesendet);
    }

    public function test_job_wartet_bei_vergangenem_vertragsbeginn(): void
    {
        $r = $this->vormerkung('2026-10-01');
        $this->inVertragsphase();

        $this->assertSame('wartet_vertragsbeginn', $this->sender()->versuchen(self::APPLICANT));
        $this->assertStringContainsString('Vergangenheit', $r->fresh()->last_attempt_result);
        $this->assertSame([], $this->gesendet);
    }

    public function test_job_wartet_bei_fehlendem_zuschlag(): void
    {
        $this->vormerkung();
        $this->inVertragsphase();
        Capsule::table('rec_applicants')->where('id', self::APPLICANT)->update(['zuschlag' => null]);

        $this->assertSame('wartet_zuschlag', $this->sender()->versuchen(self::APPLICANT));
        $this->assertSame([], $this->gesendet);
    }

    public function test_job_nicht_eu_ungeprueft_routet_auf_hr_schreibtisch_und_wartet(): void
    {
        $r = $this->vormerkung();
        $this->inVertragsphase();
        Capsule::table('rec_applicant_legal_statuses')->insert(['rec_applicant_id' => self::APPLICANT, 'team_id' => self::TEAM, 'is_eu_citizen' => 0, 'created_at' => self::HEUTE, 'updated_at' => self::HEUTE]);

        $this->assertSame('wartet_hr', $this->sender()->versuchen(self::APPLICANT));

        $fall = Capsule::table('rec_hr_desk_cases')->where('rec_applicant_id', self::APPLICANT)->where('reason', 'non_eu_citizen')->first();
        $this->assertNotNull($fall, 'HR-Fall angelegt');
        $this->assertStringContainsString('vorgemerkt', (string) $fall->notes);
        $this->assertTrue($r->fresh()->istOffen());
        $this->assertSame([], $this->gesendet);
    }

    /** Controller-Entscheid 6: geprueft + offener Nicht-EU-Fall blockt nicht; der Fall wird nach dem Versand freigegeben. */
    public function test_job_nicht_eu_geprueft_mit_offenem_fall_versendet_und_gibt_fall_frei(): void
    {
        $r = $this->vormerkung();
        $this->inVertragsphase();
        Capsule::table('rec_applicant_legal_statuses')->insert(['rec_applicant_id' => self::APPLICANT, 'team_id' => self::TEAM, 'is_eu_citizen' => 0, 'legal_status_checked_at' => self::HEUTE, 'created_at' => self::HEUTE, 'updated_at' => self::HEUTE]);
        Capsule::table('rec_hr_desk_cases')->insert(['uuid' => 'vv-case-1', 'rec_applicant_id' => self::APPLICANT, 'team_id' => self::TEAM, 'reason' => 'non_eu_citizen', 'status' => 'open', 'notes' => null, 'opened_at' => self::HEUTE, 'created_at' => self::HEUTE, 'updated_at' => self::HEUTE]);

        $this->assertSame('versendet', $this->sender()->versuchen(self::APPLICANT));

        $this->assertCount(1, $this->gesendet);
        $this->assertNotNull($r->fresh()->completed_at);
        $fall = Capsule::table('rec_hr_desk_cases')->where('uuid', 'vv-case-1')->first();
        $this->assertSame('approved', $fall->status);
        $this->assertStringContainsString('Vormerkung', (string) $fall->resolution_notes);
        $this->assertSame(7, (int) $fall->resolved_by_user_id);
        $this->assertCount(0, $this->logs('contract_send_approve_failed'));
        $this->assertCount(1, $this->logs('hr_desk_approved'));
    }

    public function test_job_anderer_blockierender_fall_wartet_auch_bei_geprueftem_status(): void
    {
        $r = $this->vormerkung();
        $this->inVertragsphase();
        Capsule::table('rec_hr_desk_cases')->insert(['uuid' => 'vv-case-2', 'rec_applicant_id' => self::APPLICANT, 'team_id' => self::TEAM, 'reason' => \Platform\Recruiting\Models\RecHrDeskCase::REASON_MINOR, 'status' => 'open', 'notes' => null, 'opened_at' => self::HEUTE, 'created_at' => self::HEUTE, 'updated_at' => self::HEUTE]);

        $this->assertSame('wartet_hr', $this->sender()->versuchen(self::APPLICANT));
        $this->assertTrue($r->fresh()->istOffen());
        $this->assertSame([], $this->gesendet);
        $this->assertSame(0, Capsule::table('rec_hr_desk_cases')->where('reason', 'non_eu_citizen')->count(), 'kein zusaetzlicher Nicht-EU-Fall');
    }

    public function test_job_bricht_ab_wenn_bewerber_geparkt(): void
    {
        $r = $this->vormerkung();
        Capsule::table('rec_applicants')->where('id', self::APPLICANT)->update(['is_parked' => 1]);

        $this->assertSame('abgebrochen', $this->sender()->versuchen(self::APPLICANT));
        $this->assertNotNull($r->fresh()->cancelled_at);
    }

    public function test_trigger_ist_im_provider_gebunden(): void
    {
        $quelle = file_get_contents(dirname(__DIR__, 2) . '/src/RecruitingServiceProvider.php');
        $this->assertStringContainsString('ReservedSendTrigger::class', $quelle);
        $this->assertTrue(is_subclass_of(\Platform\Recruiting\Services\QueueReservedSendTrigger::class, \Platform\Recruiting\Services\ReservedSendTrigger::class));
        $job = new \Platform\Recruiting\Jobs\SendReservedContractsJob(self::APPLICANT, 'test');
        $this->assertInstanceOf(\Illuminate\Contracts\Queue\ShouldQueue::class, $job);
        $this->assertSame(1, $job->tries, 'kein automatischer Neuversuch (Doppelversand)');
    }

    /** Entscheid 9: Exception nach dem Senden — Vormerkung bleibt offen, Beleg geloest, Grund vermerkt; kein Doppelversand. */
    public function test_job_exception_nach_dem_senden_loest_beleg_und_vermerkt_grund(): void
    {
        $r = $this->vormerkung();
        $this->inVertragsphase();
        $werfend = new class extends \Platform\Recruiting\Services\ContractDispatchService {
            public function __construct() {}
            public function sendForApplicant(RecApplicant $applicant, ?int $userId, ?array $contractFields, ?\Platform\Recruiting\Models\RecContractTemplate $defaultTemplate): array
            {
                Capsule::table('rec_contracts')->insert(['uuid' => 'c-wurf', 'team_id' => $applicant->team_id, 'rec_applicant_id' => $applicant->id, 'rec_contract_template_id' => 900, 'status' => 'sent', 'sent_at' => VersandVormerkenTest::HEUTE_OEFFENTLICH, 'created_at' => VersandVormerkenTest::HEUTE_OEFFENTLICH, 'updated_at' => VersandVormerkenTest::HEUTE_OEFFENTLICH]);
                throw new \RuntimeException('Verbindung weg');
            }
        };

        try {
            $this->sender($werfend)->versuchen(self::APPLICANT);
            $this->fail('Exception muss weitergeworfen werden');
        } catch (\RuntimeException $e) {
            $this->assertSame('Verbindung weg', $e->getMessage());
        }

        $frisch = $r->fresh();
        $this->assertTrue($frisch->istOffen());
        $this->assertNull($frisch->claimed_at);
        $this->assertStringContainsString('Verbindung weg', $frisch->last_attempt_result);
        $this->assertSame(1, Capsule::table('rec_contracts')->where('rec_applicant_id', self::APPLICANT)->count(), 'Vertrag bleibt (kein Rollback)');

        // Naechster Ausloeser: Vertrag ist da → abschliessen, nicht noch einmal senden.
        $this->assertSame('bereits_versendet', $this->sender()->versuchen(self::APPLICANT));
        $this->assertSame([], $this->gesendet);
    }

    public function test_job_frischer_beleg_laeuft_bereits_alter_beleg_sendet(): void
    {
        $r = $this->vormerkung();
        $this->inVertragsphase();
        Capsule::table('rec_contract_send_reservations')->where('id', $r->id)->update(['claimed_at' => '2026-10-07 09:55:00']);
        $logsVorher = Capsule::table('rec_auto_pilot_logs')->count();

        $this->assertSame('laeuft_bereits', $this->sender()->versuchen(self::APPLICANT));
        $this->assertSame([], $this->gesendet);
        $this->assertSame($logsVorher, Capsule::table('rec_auto_pilot_logs')->count(), 'kein Verlaufs-Spam');

        Capsule::table('rec_contract_send_reservations')->where('id', $r->id)->update(['claimed_at' => '2026-10-07 09:40:00']);
        $this->assertSame('versendet', $this->sender()->versuchen(self::APPLICANT));
        $this->assertCount(1, $this->gesendet);
        $this->assertNull($r->fresh()->claimed_at);
    }

    /** Entscheid 10: ohne Benutzer an der Vormerkung keine Freigabe (FK resolved_by_user_id). */
    public function test_job_ohne_benutzer_an_der_vormerkung_gibt_fall_nicht_frei(): void
    {
        $r = $this->dienst()->vormerken($this->bewerber(), self::BOOKING, '2026-11-01', null, 'nachbereitung', null, 'System', ['Straße']);
        $this->inVertragsphase();
        Capsule::table('rec_applicant_legal_statuses')->insert(['rec_applicant_id' => self::APPLICANT, 'team_id' => self::TEAM, 'is_eu_citizen' => 0, 'legal_status_checked_at' => self::HEUTE, 'created_at' => self::HEUTE, 'updated_at' => self::HEUTE]);
        Capsule::table('rec_hr_desk_cases')->insert(['uuid' => 'vv-case-3', 'rec_applicant_id' => self::APPLICANT, 'team_id' => self::TEAM, 'reason' => 'non_eu_citizen', 'status' => 'open', 'notes' => null, 'opened_at' => self::HEUTE, 'created_at' => self::HEUTE, 'updated_at' => self::HEUTE]);

        $this->assertSame('versendet', $this->sender()->versuchen(self::APPLICANT));

        $this->assertNotNull($r->fresh()->completed_at);
        $this->assertSame('open', Capsule::table('rec_hr_desk_cases')->where('uuid', 'vv-case-3')->value('status'));
        $this->assertCount(0, $this->logs('hr_desk_approved'));
        $log = $this->logs('contract_send_approve_failed')->first();
        $this->assertNotNull($log);
        $this->assertStringContainsString('kein Benutzer an der Vormerkung', $log->summary);
    }

    public function test_job_failed_vermerkt_grund_an_der_offenen_vormerkung(): void
    {
        $r = $this->vormerkung();

        (new \Platform\Recruiting\Jobs\SendReservedContractsJob(self::APPLICANT, 'test'))->failed(new \RuntimeException('Worker-Timeout'));

        $this->assertTrue($r->fresh()->istOffen());
        $this->assertStringContainsString('Worker-Timeout', (string) $r->fresh()->last_attempt_result);
    }

    /** Entscheid 9 a/b: waehrend des Sendens ist der Beleg festgeschrieben und KEINE Transaktion offen. */
    public function test_job_sendet_ausserhalb_der_transaktion_mit_festgeschriebenem_beleg(): void
    {
        $r = $this->vormerkung();
        $this->inVertragsphase();
        $beobachtet = [];
        $sonde = new class($beobachtet, $r->id) extends \Platform\Recruiting\Services\ContractDispatchService {
            public function __construct(private array &$beobachtet, private int $reservationId) {}
            public function sendForApplicant(RecApplicant $applicant, ?int $userId, ?array $contractFields, ?\Platform\Recruiting\Models\RecContractTemplate $defaultTemplate): array
            {
                $this->beobachtet = [
                    'transaktionsebene' => Capsule::connection()->transactionLevel(),
                    'claimed_at' => Capsule::table('rec_contract_send_reservations')->where('id', $this->reservationId)->value('claimed_at'),
                ];
                return ['status' => 'sent', 'portal_sent' => true, 'message' => null];
            }
        };

        $this->assertSame('versendet', $this->sender($sonde)->versuchen(self::APPLICANT));

        $this->assertSame(0, $beobachtet['transaktionsebene']);
        $this->assertSame('2026-10-07 10:00:00', $beobachtet['claimed_at']);
        $this->assertNull($r->fresh()->claimed_at);
    }

    /** @var list<array{0:int,1:string}> */
    private array $angestossen = [];

    private function beobachterAktiv(): void
    {
        $calls = &$this->angestossen;
        Container::getInstance()->instance(\Platform\Recruiting\Services\ReservedSendTrigger::class, new class($calls) implements \Platform\Recruiting\Services\ReservedSendTrigger {
            public function __construct(private array &$calls) {}
            public function anstossen(int $applicantId, string $anlass): void { $this->calls[] = [$applicantId, $anlass]; }
        });
        \Platform\Recruiting\Observers\RecContractSendReservationObserver::register();
    }

    private function beobachterAus(): void
    {
        RecApplicant::flushEventListeners();
        \Platform\Recruiting\Models\RecInterviewBooking::flushEventListeners();
        \Platform\Recruiting\Models\RecApplicantLegalStatus::flushEventListeners();
        Container::getInstance()->forgetInstance(\Platform\Recruiting\Services\ReservedSendTrigger::class);
        Model::clearBootedModels(); // creating-Hooks (uuid) der Models wieder registrieren
    }

    public function test_phasenwechsel_in_die_anlage_phase_stoesst_den_versand_an(): void
    {
        $this->vormerkung();
        $this->beobachterAktiv();
        try {
            $a = $this->bewerber();
            $a->rec_phase_id = self::PHASE_4;
            $a->save();
            $this->assertSame([[self::APPLICANT, 'phase']], $this->angestossen);

            // Wechsel in eine Phase OHNE Anlage: kein Anstoss
            $a->rec_phase_id = self::PHASE_3;
            $a->save();
            $this->assertCount(1, $this->angestossen);
        } finally {
            $this->beobachterAus();
        }
    }

    public function test_ohne_vormerkung_kein_anstoss(): void
    {
        $this->beobachterAktiv();
        try {
            $a = $this->bewerber();
            $a->rec_phase_id = self::PHASE_4;
            $a->save();
            $this->assertSame([], $this->angestossen);
        } finally {
            $this->beobachterAus();
        }
    }

    public function test_absage_der_buchung_nimmt_die_vormerkung_zurueck(): void
    {
        $r = $this->vormerkung();
        $this->beobachterAktiv();
        try {
            $b = \Platform\Recruiting\Models\RecInterviewBooking::find(self::BOOKING);
            $b->status = 'cancelled';
            $b->save();
            $this->assertNotNull($r->fresh()->cancelled_at);
            $this->assertStringContainsString('Buchung', $r->fresh()->cancel_reason);
        } finally {
            $this->beobachterAus();
        }
    }

    public function test_parken_nimmt_die_vormerkung_zurueck(): void
    {
        $r = $this->vormerkung();
        $this->beobachterAktiv();
        try {
            $a = $this->bewerber();
            $a->is_parked = true;
            $a->save();
            $this->assertNotNull($r->fresh()->cancelled_at);
        } finally {
            $this->beobachterAus();
        }
    }

    public function test_nicht_eu_nach_vormerkung_landet_auf_dem_hr_schreibtisch_und_freigabe_stoesst_an(): void
    {
        $this->vormerkung();
        $this->beobachterAktiv();
        try {
            $legal = \Platform\Recruiting\Models\RecApplicantLegalStatus::create(['rec_applicant_id' => self::APPLICANT, 'team_id' => self::TEAM, 'is_eu_citizen' => null]);
            $legal->setEuCitizen(false, null);
            $legal->save();
            $faelle = Capsule::table('rec_hr_desk_cases')->where('rec_applicant_id', self::APPLICANT)->where('reason', 'non_eu_citizen')->get();
            $this->assertCount(1, $faelle, 'genau ein Nicht-EU-Fall');
            $fall = $faelle->first();
            $this->assertStringContainsString('vorgemerkt', (string) $fall->notes);

            $legal->legal_status_checked_at = Carbon::now();
            $legal->save();
            $this->assertSame([[self::APPLICANT, 'rechtsstatus']], $this->angestossen);
        } finally {
            $this->beobachterAus();
        }
    }

    public function test_hr_freigabe_stoesst_den_versand_an(): void
    {
        $this->vormerkung();
        Capsule::table('rec_applicant_legal_statuses')->insert(['rec_applicant_id' => self::APPLICANT, 'team_id' => self::TEAM, 'is_eu_citizen' => 0, 'legal_status_checked_at' => self::HEUTE, 'created_at' => self::HEUTE, 'updated_at' => self::HEUTE]);
        $fall = \Platform\Recruiting\Models\RecHrDeskCase::create(['uuid' => 'vv-case-1', 'rec_applicant_id' => self::APPLICANT, 'team_id' => self::TEAM, 'reason' => 'non_eu_citizen', 'status' => 'open', 'opened_at' => self::HEUTE]);
        $this->beobachterAktiv();
        try {
            (new \Platform\Recruiting\Services\HrDeskRoutingService())->approveCase($fall, 7, 'geprüft');
            $this->assertContains([self::APPLICANT, 'hr_freigabe'], $this->angestossen);
        } finally {
            $this->beobachterAus();
        }
    }

    public function test_beobachter_ist_im_provider_registriert(): void
    {
        $quelle = file_get_contents(dirname(__DIR__, 2) . '/src/RecruitingServiceProvider.php');
        $this->assertStringContainsString('RecContractSendReservationObserver::register()', $quelle);
    }

    /**
     * Dispatch-Attrappe wie der echte Versand mit Anlage-Phase: Vertrag raus,
     * MA angelegt, Bewerbung per Eloquent deaktiviert (CreateEmployeeFromApplicantService)
     * — alles INNERHALB von sendForApplicant, also vor dem Abschliessen der Vormerkung.
     */
    private function anlegendeAttrappe(): \Platform\Recruiting\Services\ContractDispatchService
    {
        return new class() extends \Platform\Recruiting\Services\ContractDispatchService {
            public function __construct() {}
            public function sendForApplicant(RecApplicant $applicant, ?int $userId, ?array $contractFields, ?\Platform\Recruiting\Models\RecContractTemplate $defaultTemplate): array
            {
                $jetzt = VersandVormerkenTest::HEUTE_OEFFENTLICH;
                Capsule::table('rec_contracts')->insert(['uuid' => 'c-anlage-' . $applicant->id, 'team_id' => $applicant->team_id, 'rec_applicant_id' => $applicant->id, 'rec_contract_template_id' => 900, 'status' => 'sent', 'sent_at' => $jetzt, 'created_at' => $jetzt, 'updated_at' => $jetzt]);
                Capsule::table('rec_employees')->insert(['uuid' => 'e-anlage-' . $applicant->id, 'team_id' => $applicant->team_id, 'rec_applicant_id' => $applicant->id, 'created_at' => $jetzt, 'updated_at' => $jetzt]);
                $applicant->update(['is_active' => false]);
                return ['status' => 'sent', 'portal_sent' => true, 'message' => null];
            }
        };
    }

    public function test_automatischer_versand_mit_ma_anlage_schliesst_ab_statt_zurueckzunehmen(): void
    {
        $r = $this->vormerkung();
        $this->inVertragsphase();
        $this->beobachterAktiv();
        try {
            $this->assertSame('versendet', $this->sender($this->anlegendeAttrappe())->versuchen(self::APPLICANT));
            $this->assertFalse((bool) $this->bewerber()->is_active, 'Attrappe hat deaktiviert');
            $this->assertNotNull($r->fresh()->completed_at);
            $this->assertNull($r->fresh()->cancelled_at);
            $this->assertCount(0, $this->logs('contract_send_cancelled'));
        } finally {
            $this->beobachterAus();
        }
    }

    public function test_klick_versand_mit_ma_anlage_schliesst_ab_statt_zurueckzunehmen(): void
    {
        $r = $this->vormerkung();
        $this->inVertragsphase();
        $this->beobachterAktiv();
        try {
            $bookings = \Platform\Recruiting\Models\RecInterviewBooking::with('applicant')->whereKey(self::BOOKING)->get();
            $lauf = new \Platform\Recruiting\Services\ContractSendRun($this->anlegendeAttrappe(), $this->dienst());
            $ergebnis = $lauf->ausfuehren($bookings, [self::APPLICANT => ['vertragsbeginn' => '2026-11-01']], null, 7, 'Clara', 'nachbereitung');
            $this->assertSame([self::APPLICANT], $ergebnis->versendet);
            $this->assertNotNull($r->fresh()->completed_at);
            $this->assertNull($r->fresh()->cancelled_at);
            $this->assertCount(0, $this->logs('contract_send_cancelled'));
        } finally {
            $this->beobachterAus();
        }
    }

    public function test_echte_deaktivierung_ohne_vertrag_und_ma_nimmt_zurueck(): void
    {
        $r = $this->vormerkung();
        $this->beobachterAktiv();
        try {
            $a = $this->bewerber();
            $a->is_active = false;
            $a->save();
            $this->assertNotNull($r->fresh()->cancelled_at);
        } finally {
            $this->beobachterAus();
        }
    }

    // --- Abschluss-Review: Fixes ---

    /** Alle Pflichtfelder bis Phase 3 gefuellt (Vorname aus Phase 1 + Strasse). */
    private function allePflichtfelderAusfuellen(): void
    {
        $this->strasseAusfuellen();
        Capsule::table('core_extra_field_values')->insert([
            'definition_id' => self::definitionId(self::PHASE_1, 'vorname'),
            'fieldable_type' => (new RecApplicant())->getMorphClass(), 'fieldable_id' => self::APPLICANT,
            'value' => 'Clara', 'created_at' => self::HEUTE, 'updated_at' => self::HEUTE,
        ]);
    }

    public function test_hydrate_fuellt_leere_daten_aus_offener_vormerkung(): void
    {
        $r = $this->vormerkung('2026-11-01');
        $r->forceFill(['vertragsende' => '2027-10-31'])->save();
        $Index = \Platform\Recruiting\Livewire\InterviewBookings\Index::class;

        $this->assertSame(
            ['vertragsbeginn' => '2026-11-01', 'vertragsende' => '2027-10-31'],
            $Index::datenAusVormerkung(null, $r->fresh()),
        );
        $this->assertSame(
            ['vertragsbeginn' => '2026-12-01', 'vertragsende' => '2027-10-31'],
            $Index::datenAusVormerkung(['vertragsbeginn' => '2026-12-01', 'vertragsende' => ''], $r->fresh()),
            'getippter Wert gewinnt, leerer wird gefuellt',
        );

        $r->forceFill(['completed_at' => self::HEUTE])->save();
        $this->assertSame(['vertragsbeginn' => null, 'vertragsende' => null], $Index::datenAusVormerkung(null, $r->fresh()), 'abgeschlossene zaehlt nicht');
        $r->forceFill(['completed_at' => null, 'cancelled_at' => self::HEUTE])->save();
        $this->assertSame(['vertragsbeginn' => null, 'vertragsende' => null], $Index::datenAusVormerkung(null, $r->fresh()), 'zurueckgenommene zaehlt nicht');
        $this->assertSame(['vertragsbeginn' => null, 'vertragsende' => null], $Index::datenAusVormerkung(null, null));
    }

    public function test_erinnern_entfaellt_wenn_keine_pflichtfelder_offen(): void
    {
        $r = $this->vormerkung();
        $this->assertCount(1, $this->erinnerungen);
        $this->allePflichtfelderAusfuellen();
        $this->assertSame([], $this->bewerber()->fehlendePflichtfelder());

        $dienst = $this->dienst();
        $this->assertFalse($dienst->erinnern($r->fresh(), force: true));
        $this->assertCount(1, $this->erinnerungen, 'keine weitere Erinnerung');

        // Neu vormerken ohne offene Felder: Vormerkung ja, Erinnerung nein, ehrlich gemeldet.
        Carbon::setTestNow('2026-10-09 10:00:00');
        $dienst->vormerken($this->bewerber(), self::BOOKING, '2026-11-01', null, 'hr_desk', 7, 'Clara', []);
        $this->assertFalse($dienst->letzteErinnerungGesendet);
        $this->assertCount(1, $this->erinnerungen);
    }

    public function test_kurztext_ohne_offene_felder_wartet_auf_freigabe(): void
    {
        $this->allePflichtfelderAusfuellen();
        $b = $this->bewerber()->versandBereitschaft();

        $this->assertSame('unvollstaendig', $b->status);
        $this->assertSame('Daten vollständig — wartet auf Phasenwechsel bzw. Freigabe', $b->kurztext());
    }

    public function test_erinnern_mit_wurf_gilt_als_gescheitert(): void
    {
        $r = $this->vormerkung();
        $dienst = new class extends \Platform\Recruiting\Services\ContractSendReservationService {
            protected function erinnerungSenden(RecApplicant $a): array
            {
                throw new \RuntimeException('Meta down');
            }
        };

        $this->assertFalse($dienst->erinnern($r->fresh(), force: true));
        $this->assertTrue($r->fresh()->istOffen());
        $this->assertTrue($this->logs('contract_send_reminder')->contains(fn ($l) => str_contains($l->summary, 'Meta down')));
    }

    public function test_klick_lauf_faengt_wurf_je_person_und_macht_weiter(): void
    {
        Capsule::table('rec_applicants')->insert([
            ['id' => 5002, 'uuid' => 'vv-app-5002', 'team_id' => self::TEAM, 'rec_position_id' => self::POSITION, 'rec_phase_id' => self::PHASE_4, 'contract_template_id' => 900, 'zuschlag' => 1.1, 'is_test' => 0, 'is_active' => 1, 'created_at' => self::HEUTE, 'updated_at' => self::HEUTE],
        ]);
        Capsule::table('rec_interview_bookings')->insert([
            ['id' => 7002, 'uuid' => 'vv-bk-7002', 'team_id' => self::TEAM, 'rec_interview_id' => 8001, 'rec_applicant_id' => 5002, 'status' => 'attended', 'created_at' => self::HEUTE, 'updated_at' => self::HEUTE],
        ]);
        try {
            $werfend = new class extends \Platform\Recruiting\Services\ContractSendReservationService {
                public function vormerken(RecApplicant $applicant, ?int $bookingId, ?string $vertragsbeginn, ?string $vertragsende, string $source, ?int $userId, string $userName, array $fehlendeFelder): RecContractSendReservation
                {
                    throw new \RuntimeException('DB weg');
                }
            };
            $gesendet = [];
            $dispatch = new class($gesendet) extends \Platform\Recruiting\Services\ContractDispatchService {
                public function __construct(private array &$gesendet) {}
                public function sendForApplicant(RecApplicant $applicant, ?int $userId, ?array $contractFields, ?\Platform\Recruiting\Models\RecContractTemplate $defaultTemplate): array
                {
                    $this->gesendet[] = $applicant->id;
                    return ['status' => 'sent', 'portal_sent' => true, 'message' => null];
                }
            };
            // 5001 (unvollstaendig → vormerken wirft) zuerst, 5002 (bereit) danach.
            $bookings = \Platform\Recruiting\Models\RecInterviewBooking::with('applicant')->whereIn('id', [self::BOOKING, 7002])->orderBy('id')->get();
            $daten = [self::APPLICANT => ['vertragsbeginn' => '2026-11-01'], 5002 => ['vertragsbeginn' => '2026-11-01']];

            $ergebnis = (new \Platform\Recruiting\Services\ContractSendRun($dispatch, $werfend))->ausfuehren($bookings, $daten, null, 7, 'Clara', 'nachbereitung');

            $this->assertSame([5002], $ergebnis->versendet, 'die anderen laufen weiter');
            $this->assertSame([5002], $gesendet);
            $this->assertArrayHasKey(self::APPLICANT, $ergebnis->fehler);
            $this->assertStringContainsString('DB weg', $ergebnis->fehler[self::APPLICANT]);
        } finally {
            Capsule::table('rec_interview_bookings')->whereIn('id', [7002])->delete();
            Capsule::table('rec_applicants')->whereIn('id', [5002])->delete();
        }
    }

    public function test_klick_meldung_zaehlt_nur_echte_erinnerungen(): void
    {
        $this->erinnerungsErgebnis = ['ok' => false, 'error' => 'Keine Telefonnummer.'];
        $bookings = \Platform\Recruiting\Models\RecInterviewBooking::with('applicant')->whereKey(self::BOOKING)->get();

        $ergebnis = $this->klickLauf()->ausfuehren($bookings, [self::APPLICANT => ['vertragsbeginn' => '2026-11-01']], null, 7, 'Clara', 'nachbereitung');

        $this->assertSame([self::APPLICANT], $ergebnis->vorgemerkt);
        $this->assertSame([], $ergebnis->erinnert);
        $this->assertStringContainsString('1 vorgemerkt (0 erinnert', $ergebnis->meldung());
        $this->assertStringNotContainsString('Erinnerung geschickt', $ergebnis->meldung());
    }

    public function test_klick_meldung_zaehlt_gesendete_erinnerung(): void
    {
        $bookings = \Platform\Recruiting\Models\RecInterviewBooking::with('applicant')->whereKey(self::BOOKING)->get();

        $ergebnis = $this->klickLauf()->ausfuehren($bookings, [self::APPLICANT => ['vertragsbeginn' => '2026-11-01']], null, 7, 'Clara', 'nachbereitung');

        $this->assertSame([self::APPLICANT], $ergebnis->erinnert);
        $this->assertStringContainsString('1 vorgemerkt (1 erinnert', $ergebnis->meldung());
    }
}
