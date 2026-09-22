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
 * Auswahl nach Phase fuer recruiting:reconcile-applicant-positions.
 *
 * Anlass (22.09.2026): Der Trockenlauf ueber das ganze Team meldete 434
 * Aenderungen an 1752 geprueften Bewerbungen — die Masse davon Altbestand aus
 * den archivierten Stellen "... bis 22.05.26". Behandelt werden sollte aber
 * genau EIN Topf: die Bewerbungen, die noch in der Phase der Sammel-Stelle
 * "Sonstiges" (Phase 45) stehen, obwohl ihre Anzeige laengst zu einer echten
 * Stelle gehoert. --limit taugt dafuer nicht: es schneidet in Query-Reihenfolge
 * ab und erwischt damit zuerst genau den Altbestand, den niemand anfassen will.
 *
 * Der Test belegt beide Haelften am selben Bestand, sonst waere die Einschraenkung
 * nicht von "tut ueberhaupt nichts" zu unterscheiden:
 *  - Vorflug ohne Filter: BEIDE Bewerbungen sind Heil-Kandidaten.
 *  - Mit --phase-id: nur die aus der gefilterten Phase wird angefasst, die
 *    andere bleibt Zeile fuer Zeile unveraendert stehen.
 *
 * Aufbau wie ReconcileApplicantPositionsGateTest (Container + Capsule von Hand,
 * ECHTE Migrationen per glob, auth() als Attrappe, feste Uhr, Probe-Unterklasse
 * statt Artisan-Lebenszyklus).
 */
class ReconcilePhaseFilterTest extends TestCase
{
    private const TEAM = 9;

    /** Sammel-Stelle: traegt die Phase, in der die Bewerbungen haengenbleiben. */
    private const POSITION_SAMMEL = 91;

    /** Echte Stelle: Ziel beider Anzeigen. */
    private const POSITION_KOELN = 92;

    /** Dritte Stelle — nur als alte Heimat der Vergleichs-Bewerbung. */
    private const POSITION_ALT = 93;

    private const PHASE_SAMMEL = 191;
    private const PHASE_ALT = 193;

    private const POSTING_KOELN = 920;

    /** steht in der Sammel-Phase, Anzeige zeigt auf Koeln → Ziel des Filters. */
    private const APPLICANT_IN_SAMMELPHASE = 3110;

    /** steht in einer FREMDEN Phase, waere ohne Filter ebenfalls ein Kandidat. */
    private const APPLICANT_IN_ANDERER_PHASE = 3112;

    private const HEUTE = '2026-09-22 10:00:00';

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
                // nicht benutzt: dieser Test ruft nur Kommando-Methoden auf
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

    protected function setUp(): void
    {
        parent::setUp();

        self::setzeBestandAufAusgangszustand();
    }

    public function test_phasen_filter_heilt_nur_die_angegebene_phase(): void
    {
        // Vorflug: ohne Filter sind BEIDE Bewerbungen Heil-Kandidaten — sonst
        // waere jede Aussage ueber den Filter unten wertlos belegt.
        $ohneFilter = (new ReconcilePhaseFilterProbe())
            ->probeReconcile(dryRun: true, teamId: (string) self::TEAM, limit: 0, includeInactive: false);

        $this->assertSame(2, $ohneFilter['checked'], 'Vorflug: ohne Filter werden beide Bewerbungen geprueft');
        $this->assertSame(2, $ohneFilter['phaseAligned'], 'Vorflug: ohne Filter waeren beide Phasen auszurichten');

        $mitFilter = (new ReconcilePhaseFilterProbe())->probeReconcile(
            dryRun: false,
            teamId: (string) self::TEAM,
            limit: 0,
            includeInactive: false,
            phaseId: self::PHASE_SAMMEL,
        );

        $this->assertSame(1, $mitFilter['checked'], 'mit --phase-id wird nur die gefilterte Phase geprueft');
        $this->assertSame(1, $mitFilter['phaseAligned'], 'genau eine Phase wurde ausgerichtet');

        $this->assertNotSame(
            self::PHASE_SAMMEL,
            (int) RecApplicant::find(self::APPLICANT_IN_SAMMELPHASE)->rec_phase_id,
            'die Bewerbung aus der gefilterten Phase wurde auf die Phase ihrer Stelle gezogen'
        );

        $this->assertSame(
            self::PHASE_ALT,
            (int) RecApplicant::find(self::APPLICANT_IN_ANDERER_PHASE)->rec_phase_id,
            'die Bewerbung ausserhalb der gefilterten Phase bleibt unangetastet'
        );
        $this->assertSame(
            self::POSITION_ALT,
            (int) RecApplicant::find(self::APPLICANT_IN_ANDERER_PHASE)->rec_position_id,
            'ausserhalb der gefilterten Phase wird auch die Stelle nicht nachgezogen'
        );
    }

    private static function setzeBestandAufAusgangszustand(): void
    {
        Capsule::table('rec_applicants')
            ->where('id', self::APPLICANT_IN_SAMMELPHASE)
            ->update([
                'rec_position_id' => self::POSITION_SAMMEL,
                'rec_phase_id' => self::PHASE_SAMMEL,
                'owned_by_user_id' => null,
                'is_unrouted' => 0,
            ]);

        Capsule::table('rec_applicants')
            ->where('id', self::APPLICANT_IN_ANDERER_PHASE)
            ->update([
                'rec_position_id' => self::POSITION_ALT,
                'rec_phase_id' => self::PHASE_ALT,
                'owned_by_user_id' => null,
                'is_unrouted' => 0,
            ]);

        Capsule::table('rec_auto_pilot_logs')->delete();
        Capsule::table('core_extra_field_values')->delete();
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
            ['id' => self::POSITION_SAMMEL, 'uuid' => 'rpf-pos-91', 'team_id' => self::TEAM,
             'title' => 'Sonstiges', 'location' => '', 'is_active' => 1,
             'created_at' => $now, 'updated_at' => $now],
            ['id' => self::POSITION_KOELN, 'uuid' => 'rpf-pos-92', 'team_id' => self::TEAM,
             'title' => 'Koeln allgemein', 'location' => 'Koeln', 'is_active' => 1,
             'created_at' => $now, 'updated_at' => $now],
            ['id' => self::POSITION_ALT, 'uuid' => 'rpf-pos-93', 'team_id' => self::TEAM,
             'title' => 'Koeln bis 22.05.26', 'location' => 'Koeln', 'is_active' => 1,
             'created_at' => $now, 'updated_at' => $now],
        ]);

        Capsule::table('rec_phases')->insert([
            ['id' => self::PHASE_SAMMEL, 'uuid' => 'rpf-ph-191', 'team_id' => self::TEAM,
             'rec_position_id' => self::POSITION_SAMMEL, 'name' => 'Bewerbung', 'order' => 1,
             'is_active' => 1, 'created_at' => $now, 'updated_at' => $now],
            ['id' => 192, 'uuid' => 'rpf-ph-192', 'team_id' => self::TEAM,
             'rec_position_id' => self::POSITION_KOELN, 'name' => 'Bewerbung', 'order' => 1,
             'is_active' => 1, 'created_at' => $now, 'updated_at' => $now],
            ['id' => self::PHASE_ALT, 'uuid' => 'rpf-ph-193', 'team_id' => self::TEAM,
             'rec_position_id' => self::POSITION_ALT, 'name' => 'Bewerbung', 'order' => 1,
             'is_active' => 1, 'created_at' => $now, 'updated_at' => $now],
        ]);

        // EINE Anzeige, sie gehoert zur echten Stelle Koeln — beide Bewerbungen
        // haengen daran, ihre Phase steht jeweils woanders.
        Capsule::table('rec_postings')->insert([
            'id' => self::POSTING_KOELN, 'uuid' => 'rpf-pstg-920', 'rec_position_id' => self::POSITION_KOELN,
            'team_id' => self::TEAM, 'title' => 'Koeln Anzeige', 'status' => 'published',
            'is_active' => 1, 'created_at' => $now, 'updated_at' => $now,
        ]);

        Capsule::table('rec_applicants')->insert([
            ['id' => self::APPLICANT_IN_SAMMELPHASE, 'uuid' => 'rpf-app-3110', 'team_id' => self::TEAM,
             'applied_at' => '2026-09-01', 'rec_phase_id' => self::PHASE_SAMMEL, 'rec_position_id' => self::POSITION_SAMMEL,
             'is_test' => 0, 'is_active' => 1, 'created_at' => $now, 'updated_at' => $now],
            ['id' => self::APPLICANT_IN_ANDERER_PHASE, 'uuid' => 'rpf-app-3112', 'team_id' => self::TEAM,
             'applied_at' => '2026-05-01', 'rec_phase_id' => self::PHASE_ALT, 'rec_position_id' => self::POSITION_ALT,
             'is_test' => 0, 'is_active' => 1, 'created_at' => $now, 'updated_at' => $now],
        ]);

        Capsule::table('rec_applicant_posting')->insert([
            ['rec_applicant_id' => self::APPLICANT_IN_SAMMELPHASE, 'rec_posting_id' => self::POSTING_KOELN,
             'applied_at' => '2026-09-01', 'created_at' => $now, 'updated_at' => $now],
            ['rec_applicant_id' => self::APPLICANT_IN_ANDERER_PHASE, 'rec_posting_id' => self::POSTING_KOELN,
             'applied_at' => '2026-05-01', 'created_at' => $now, 'updated_at' => $now],
        ]);
    }
}

/**
 * Reicht ReconcileApplicantPositions::reconcile() heraus — dieselbe duenne
 * Probe wie ReconcileApplicantPositionsProbe, hier mit dem Phasen-Filter.
 */
final class ReconcilePhaseFilterProbe extends ReconcileApplicantPositions
{
    /** @return array{checked:int,phaseAligned:int,ownerFilled:int,changed:int,errors:int,festgelegtSkipped:int,multiPosting:list<string>} */
    public function probeReconcile(
        bool $dryRun,
        ?string $teamId,
        int $limit,
        bool $includeInactive,
        ?int $phaseId = null,
    ): array {
        return $this->reconcile($dryRun, $teamId, $limit, $includeInactive, null, $phaseId);
    }
}
