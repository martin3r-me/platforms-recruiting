<?php

namespace Platform\Recruiting\Tests\Integration;

use Illuminate\Container\Container;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Console\Commands\ReconcileApplicantPositions;
use Platform\Recruiting\Models\RecApplicant;

/**
 * Das Heil-Kommando recruiting:reconcile-applicant-positions zieht Bewerbungen
 * auf viele Datensaetze gleichzeitig um — anders als der Live-Fix in
 * RecApplicant::reconcilePositionState() (siehe ApplicantPositionFieldTest)
 * trifft ein Fehler hier nicht einen Bewerber, sondern potenziell alle in
 * einem Team. Deshalb ein eigener Test fuer das Festlegungs-Gate GENAU an
 * dieser Stelle (ReconcileApplicantPositions::reconcile(), Zeilen ~85-105):
 * eine festgelegte Bewerbung mit korrigierter Anzeige bleibt stehen und wird
 * im $festgelegtSkipped-Zaehler ausgewiesen, eine NICHT festgelegte wird im
 * selben Lauf nachgezogen — beide zusammen belegen, dass das Kommando nicht
 * pauschal alles ueberspringt.
 *
 * reconcile() wurde aus handle() herausgehoben (reine Abgleichs-Logik ohne
 * Konsolen-I/O), damit sie hier ohne Artisan-Lebenszyklus (kein Input/Output,
 * kein Service Container) aufrufbar ist — siehe ReconcileApplicantPositionsProbe
 * unten, Muster aus PostingFormProbe (PostingTargetFieldsTest) und
 * ScheduleScopeProbe (InterviewPostingTeamScopeTest).
 *
 * Aufbau wie die anderen Integrationstests des Moduls (Container + Capsule
 * von Hand, ECHTE Migrationen per glob, auth() als Attrappe, feste Uhr).
 */
class ReconcileApplicantPositionsGateTest extends TestCase
{
    private const TEAM = 8;

    private const POSITION_DUESSELDORF = 81;
    private const POSITION_MOENCHENGLADBACH = 82;

    /** Anzeige der Stelle 82 — Ziel der HR-Korrektur in beiden Faellen. */
    private const POSTING_MOENCHENGLADBACH = 820;

    private const PHASE_EINGANG = 101;

    private const INTERVIEW = 830;

    /** rec_position_id=81, Posting zeigt auf 82, Phase 1, keine Buchung. */
    private const APPLICANT_OHNE_FESTLEGUNG = 3010;

    /** rec_position_id=81, Posting zeigt auf 82, AKTIVE Buchung -> festgelegt. */
    private const APPLICANT_FESTGELEGT = 3012;

    private const HEUTE = '2026-08-18 10:00:00';

    public static function setUpBeforeClass(): void
    {
        $container = Container::getInstance();

        $capsule = new Capsule();
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $capsule->setAsGlobal();
        $capsule->bootEloquent();
        Model::unguard();
        Model::unsetEventDispatcher();

        $container->instance('db', $capsule->getDatabaseManager());
        $container->instance('db.schema', $capsule->getConnection()->getSchemaBuilder());
        Facade::setFacadeApplication($container);

        // Dieselben vier fremden Tabellen wie ApplicantPositionFieldTest (per
        // grep verifiziert: teams, users, hcm_job_titles, comms_channels) plus
        // crm_contact_links — das Kommando ruft displayName() ueber
        // crmContactLinks(), eine MorphMany, fuer jede ausgegebene Zeile.
        $schema = $capsule->getConnection()->getSchemaBuilder();
        $schema->create('teams', fn ($table) => $table->id());
        $schema->create('users', fn ($table) => $table->id());
        $schema->create('hcm_job_titles', fn ($table) => $table->id());
        $schema->create('comms_channels', fn ($table) => $table->id());
        $schema->create('crm_contact_links', function ($table) {
            $table->id();
            $table->string('linkable_type');
            $table->unsignedBigInteger('linkable_id');
            $table->timestamps();
        });

        $container->instance(AuthFactory::class, new class(self::TEAM) implements AuthFactory
        {
            public function __construct(private int $teamId) {}

            public function user(): object
            {
                return new class($this->teamId)
                {
                    public object $currentTeam;

                    public function __construct(int $teamId)
                    {
                        $this->currentTeam = (object) ['id' => $teamId];
                    }
                };
            }

            public function guard($name = null)
            {
                return $this;
            }

            public function shouldUse($name)
            {
                // nicht benutzt: dieser Test ruft nur Model-/Kommando-Methoden auf
            }
        });

        Carbon::setTestNow(Carbon::parse(self::HEUTE));

        self::runRealMigrations();
        self::seed();
    }

    public static function tearDownAfterClass(): void
    {
        Facade::clearResolvedInstances();
        Container::getInstance()->forgetInstance(AuthFactory::class);
        Carbon::setTestNow();
    }

    public function test_festgelegte_bewerbung_bleibt_stehen_nicht_festgelegte_wird_nachgezogen(): void
    {
        $probe = new ReconcileApplicantPositionsProbe();

        $report = $probe->probeReconcile(dryRun: false, teamId: (string) self::TEAM, limit: 0, includeInactive: false);

        $this->assertSame(2, $report['checked'], 'beide Bewerbungen wurden erfasst');
        $this->assertSame(
            1,
            $report['festgelegtSkipped'],
            'genau die festgelegte Bewerbung wurde uebersprungen, nicht beide und nicht keine'
        );

        $this->assertSame(
            self::POSITION_MOENCHENGLADBACH,
            (int) RecApplicant::find(self::APPLICANT_OHNE_FESTLEGUNG)->rec_position_id,
            'ohne Festlegung zieht das Kommando die Stelle aus der korrigierten Anzeige nach'
        );

        $this->assertSame(
            self::POSITION_DUESSELDORF,
            (int) RecApplicant::find(self::APPLICANT_FESTGELEGT)->rec_position_id,
            'die Festlegung (aktive Buchung) gewinnt gegen die Anzeigen-Korrektur — das Kommando darf sie nicht zuruecksetzen'
        );
    }

    // -----------------------------------------------------------------
    // Schema und Datenbestand
    // -----------------------------------------------------------------

    private static function runRealMigrations(): void
    {
        $core = self::packageRootOf(\Platform\Core\Models\CoreExtraFieldDefinition::class);

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
            ['id' => self::POSITION_DUESSELDORF, 'uuid' => 'rapg-pos-81', 'team_id' => self::TEAM,
             'title' => 'Duesseldorf', 'location' => 'Duesseldorf', 'is_active' => 1,
             'created_at' => $now, 'updated_at' => $now],
            ['id' => self::POSITION_MOENCHENGLADBACH, 'uuid' => 'rapg-pos-82', 'team_id' => self::TEAM,
             'title' => 'Moenchengladbach', 'location' => 'Moenchengladbach', 'is_active' => 1,
             'created_at' => $now, 'updated_at' => $now],
        ]);

        Capsule::table('rec_phases')->insert([
            ['id' => self::PHASE_EINGANG, 'uuid' => 'rapg-ph-101', 'team_id' => self::TEAM,
             'rec_position_id' => self::POSITION_DUESSELDORF, 'name' => 'Eingang', 'order' => 1,
             'is_active' => 1, 'created_at' => $now, 'updated_at' => $now],
        ]);

        Capsule::table('rec_interviews')->insert([
            ['id' => self::INTERVIEW, 'uuid' => 'rapg-iv-830', 'team_id' => self::TEAM,
             'interview_type_id' => null, 'rec_position_id' => self::POSITION_MOENCHENGLADBACH,
             'title' => 'Schulung MG', 'location' => 'Moenchengladbach, Zentrale',
             'starts_at' => '2026-08-20 10:00:00', 'max_participants' => 5,
             'is_active' => 1, 'created_at' => $now, 'updated_at' => $now],
        ]);

        // Anzeige der Stelle 82 — Ziel der HR-Korrektur in beiden Faellen.
        Capsule::table('rec_postings')->insert([
            'id' => self::POSTING_MOENCHENGLADBACH, 'uuid' => 'rapg-pstg-820', 'rec_position_id' => self::POSITION_MOENCHENGLADBACH,
            'team_id' => self::TEAM, 'title' => 'Moenchengladbach Anzeige', 'status' => 'published',
            'is_active' => 1, 'created_at' => $now, 'updated_at' => $now,
        ]);

        // Beide Bewerbungen: rec_position_id zeigt (noch) auf 81, die Anzeige
        // (Posting) auf 82 — genau das Bild einer HR-Korrektur der Ausschreibung.
        Capsule::table('rec_applicants')->insert([
            ['id' => self::APPLICANT_OHNE_FESTLEGUNG, 'uuid' => 'rapg-app-3010', 'team_id' => self::TEAM,
             'applied_at' => '2026-07-01', 'rec_phase_id' => self::PHASE_EINGANG, 'rec_position_id' => self::POSITION_DUESSELDORF,
             'is_test' => 0, 'is_active' => 1, 'created_at' => $now, 'updated_at' => $now],
            ['id' => self::APPLICANT_FESTGELEGT, 'uuid' => 'rapg-app-3012', 'team_id' => self::TEAM,
             'applied_at' => '2026-07-01', 'rec_phase_id' => self::PHASE_EINGANG, 'rec_position_id' => self::POSITION_DUESSELDORF,
             'is_test' => 0, 'is_active' => 1, 'created_at' => $now, 'updated_at' => $now],
        ]);

        Capsule::table('rec_applicant_posting')->insert([
            ['rec_applicant_id' => self::APPLICANT_OHNE_FESTLEGUNG, 'rec_posting_id' => self::POSTING_MOENCHENGLADBACH,
             'applied_at' => '2026-07-01', 'created_at' => $now, 'updated_at' => $now],
            ['rec_applicant_id' => self::APPLICANT_FESTGELEGT, 'rec_posting_id' => self::POSTING_MOENCHENGLADBACH,
             'applied_at' => '2026-07-01', 'created_at' => $now, 'updated_at' => $now],
        ]);

        Capsule::table('rec_interview_bookings')->insert([
            'uuid' => 'rapg-bk-3012', 'rec_interview_id' => self::INTERVIEW, 'rec_applicant_id' => self::APPLICANT_FESTGELEGT,
            'status' => 'booked', 'is_active' => 1, 'team_id' => self::TEAM,
            'cancelled_by' => null, 'cancelled_at' => null,
            'created_at' => $now, 'updated_at' => $now,
        ]);
    }
}

/**
 * Reicht ReconcileApplicantPositions::reconcile() heraus — die reine
 * Abgleichs-Logik ohne Konsolen-I/O (siehe Klassendoc dort). Muster aus
 * PostingFormProbe (PostingTargetFieldsTest) und ScheduleScopeProbe
 * (InterviewPostingTeamScopeTest): eine duenne Probe-Unterklasse statt
 * Reflection, kein Artisan-/Livewire-Lebenszyklus noetig.
 */
final class ReconcileApplicantPositionsProbe extends ReconcileApplicantPositions
{
    /** @return array{checked:int,phaseAligned:int,ownerFilled:int,changed:int,errors:int,festgelegtSkipped:int,multiPosting:list<string>} */
    public function probeReconcile(bool $dryRun, ?string $teamId, int $limit, bool $includeInactive): array
    {
        return $this->reconcile($dryRun, $teamId, $limit, $includeInactive);
    }
}
