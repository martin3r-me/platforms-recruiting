<?php

namespace Platform\Recruiting\Tests\Integration;

use Illuminate\Container\Container;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Models\RecApplicant;

/**
 * Uebergabe aus der Sammel-Stelle ("Sonstiges") an die echte Stelle.
 *
 * Ausgangsfall (Bewerber #3342 live): die Anzeige wurde auf "Duesseldorf
 * allgemein" umgeschluesselt, die PHASE blieb aber die der Sammel-Stelle.
 * Da Extra-Felder, Fortschritt und Abschluss alle an der PHASE haengen und
 * die Sammel-Phase keine Felder hat, galt der Bewerber sofort als fertig —
 * mit Terminlink und Phantom-Jugendschutzfall als Folge.
 *
 * reconcilePositionState() konnte das nicht auffangen, weil der Abgleich der
 * Phase auf den EINDEUTIGEN Einzel-Posting-Fall beschraenkt war. Diese
 * Einschraenkung stammt aus der Zeit, in der die Stelle einer Bewerbung noch
 * aus der fruehesten Anzeige ERRATEN wurde (siehe ApplicantPositionFieldTest):
 * bei mehreren Anzeigen war die "primaere Stelle" tatsaechlich mehrdeutig.
 * Seit rec_position_id ein eigenes Feld ist, ist sie es nicht mehr — steht das
 * Feld, ist die Stelle gesagt, und die Phase hat ihr zu folgen.
 *
 * Diese Klasse pinnt die drei Faelle, die dabei auseinandergehalten werden
 * muessen:
 *
 *  1. Stelle gesetzt  → Phase folgt, auch bei mehreren Anzeigen (neu)
 *  2. Festgelegt      → Phase bleibt (schuetzt switchToPosition, s.u.)
 *  3. Stelle leer      → Phase bleibt bei mehreren Anzeigen stehen (alte Vorsicht)
 *
 * Zu Fall 2: switchToPosition() hat GENAU EINEN Aufrufer, maybeSwitchPosition()
 * in Livewire\Public\InterviewBooking (per grep verifiziert), und der laeuft nur
 * bei einer erfolgreichen Buchung. Wer die Stelle per Buchung gewechselt hat,
 * haelt also immer eine aktive Buchung und ist damit festgelegt. Ohne diesen
 * Riegel wuerde der Abgleich so jemanden an die Stelle seiner ALTEN Anzeige
 * zuruecksetzen — der Pivot bleibt beim Wechsel bewusst unangetastet, weil er
 * die Herkunft der Bewerbung ist.
 *
 * Aufbau wie die anderen Integrationstests des Moduls (Container + Capsule von
 * Hand, ECHTE Migrationen per glob, auth() als Attrappe, feste Uhr) — Kopf aus
 * ApplicantPositionFieldTest uebernommen.
 */
class SammelstelleUebergabeTest extends TestCase
{
    private const TEAM = 9;

    /** Sammel-Stelle: eine Phase, keine Felder — das Vorbild ist "Sonstiges" (#13). */
    private const POSITION_SAMMEL = 91;

    /** Echte Stelle mit voller Phasenkette (order 1 und 3). */
    private const POSITION_DUESSELDORF = 92;

    private const PHASE_SAMMEL = 191;
    private const PHASE_DUS_EINGANG = 192;
    private const PHASE_DUS_ONBOARDING = 193;

    private const POSTING_SAMMEL = 910;
    private const POSTING_DUESSELDORF = 920;

    private const INTERVIEW = 930;

    /**
     * Momos Zustand (#3342): EINE Anzeige, die der echten Stelle — die
     * Sammel-Anzeige ist weg —, aber die Phase ist noch die der Sammel-Stelle,
     * und die Stelle zeigt auch noch dorthin.
     */
    private const APPLICANT_EINE_ANZEIGE = 1101;

    /** Wie 1101, aber die Stelle ist leer (Altbestand ohne rec_position_id). */
    private const APPLICANT_STELLE_LEER = 1102;

    /** Wie 1101, aber mit aktiver Buchung → festgelegt. */
    private const APPLICANT_FESTGELEGT = 1103;

    /** Zwei Anzeigen, Sammel-Anzeige ist die fruehere → Uebergangszustand. */
    private const APPLICANT_ZWEI_ANZEIGEN = 1104;

    private const HEUTE = '2026-09-07 09:00:00';

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

        // Mini-Shims fuer die vier fremden Tabellen, auf die Migrationen dieses
        // Moduls per constrained() zeigen (identisch zu ApplicantPositionFieldTest).
        $schema = $capsule->getConnection()->getSchemaBuilder();
        $schema->create('teams', fn ($table) => $table->id());
        $schema->create('users', fn ($table) => $table->id());
        $schema->create('hcm_job_titles', fn ($table) => $table->id());
        $schema->create('comms_channels', fn ($table) => $table->id());

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
                // nicht benutzt: dieser Test ruft nur Model-Methoden auf
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

    /**
     * Der Zielzustand: nach dem Umschluesseln haengt nur die echte Anzeige, und
     * Stelle UND Phase folgen ihr. Das ist Momos Fall — er steht live falsch,
     * also muss dieser Test entweder rot sein (dann ist die Regel schuld) oder
     * gruen (dann hat den Abgleich nie jemand aufgerufen, und der Fix gehoert
     * an den Aufrufer). Der Lauf entscheidet das, nicht meine Vermutung.
     */
    public function test_eine_echte_anzeige_zieht_stelle_und_phase_nach(): void
    {
        RecApplicant::find(self::APPLICANT_EINE_ANZEIGE)->reconcilePositionState();

        $applicant = RecApplicant::find(self::APPLICANT_EINE_ANZEIGE);

        $this->assertSame(
            self::POSITION_DUESSELDORF,
            (int) $applicant->rec_position_id,
            'die Stelle folgt der verbliebenen Anzeige'
        );
        $this->assertSame(
            self::PHASE_DUS_EINGANG,
            (int) $applicant->rec_phase_id,
            'und die Phase folgt der Stelle, auf die Phase gleicher Ordnung'
        );
    }

    /** Dasselbe fuer Altbestand, dessen Stellen-Feld noch leer ist. */
    public function test_bei_leerer_stelle_zieht_die_phase_ebenfalls_nach(): void
    {
        RecApplicant::find(self::APPLICANT_STELLE_LEER)->reconcilePositionState();

        $this->assertSame(
            self::PHASE_DUS_EINGANG,
            (int) RecApplicant::find(self::APPLICANT_STELLE_LEER)->rec_phase_id,
            'ohne Stellen-Feld faellt der Abgleich auf die Anzeige zurueck'
        );
    }

    /**
     * Gegenprobe: wer sich per Buchung auf eine Filiale festgelegt hat, behaelt
     * Stelle und Phase. Sonst zoege der Abgleich ihn an die Stelle seiner alten
     * Anzeige zurueck — genau der Datenverlust, den switchToPosition vermeidet,
     * indem es den Pivot nicht anfasst.
     */
    public function test_festgelegte_behalten_ihre_phase(): void
    {
        RecApplicant::find(self::APPLICANT_FESTGELEGT)->reconcilePositionState();

        $this->assertSame(
            self::PHASE_SAMMEL,
            (int) RecApplicant::find(self::APPLICANT_FESTGELEGT)->rec_phase_id,
            'aktive Buchung → die Festlegung gewinnt gegen den Phasen-Abgleich'
        );
    }

    /**
     * Uebergangszustand mit beiden Anzeigen: die frueheste ist die Sammel-Anzeige,
     * und "woher kam die Bewerbung" ist genau ihre Definition — Stelle und Phase
     * bleiben deshalb bei der Sammel-Stelle. Das ist gewollt und harmlos, solange
     * dort nichts automatisch passiert; aufgeloest wird es, sobald HR die
     * Sammel-Anzeige abhaengt (dann greift der erste Test).
     */
    public function test_solange_die_sammel_anzeige_haengt_bleibt_alles_dort(): void
    {
        RecApplicant::find(self::APPLICANT_ZWEI_ANZEIGEN)->reconcilePositionState();

        $applicant = RecApplicant::find(self::APPLICANT_ZWEI_ANZEIGEN);

        $this->assertSame(self::POSITION_SAMMEL, (int) $applicant->rec_position_id);
        $this->assertSame(self::PHASE_SAMMEL, (int) $applicant->rec_phase_id);
    }

    // -----------------------------------------------------------------
    // Werkzeug
    // -----------------------------------------------------------------

    /**
     * Jeder Test veraendert Phase und/oder Stelle seines Bewerbers. Ohne Reset
     * saehe ein nachfolgender Test je nach Reihenfolge einen bereits
     * umgehaengten Bestand. Muster aus ApplicantPositionFieldTest.
     */
    private static function setzeBestandAufAusgangszustand(): void
    {
        foreach ([self::APPLICANT_EINE_ANZEIGE, self::APPLICANT_FESTGELEGT, self::APPLICANT_ZWEI_ANZEIGEN] as $id) {
            Capsule::table('rec_applicants')->where('id', $id)->update([
                'rec_phase_id' => self::PHASE_SAMMEL,
                'rec_position_id' => self::POSITION_SAMMEL,
                'owned_by_user_id' => 1,
            ]);
        }

        Capsule::table('rec_applicants')->where('id', self::APPLICANT_STELLE_LEER)->update([
            'rec_phase_id' => self::PHASE_SAMMEL,
            'rec_position_id' => null,
            'owned_by_user_id' => 1,
        ]);
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
            ['id' => self::POSITION_SAMMEL, 'uuid' => 'suepos-91', 'team_id' => self::TEAM,
             'title' => 'Sonstiges', 'location' => '', 'is_active' => 1,
             'created_at' => $now, 'updated_at' => $now],
            ['id' => self::POSITION_DUESSELDORF, 'uuid' => 'suepos-92', 'team_id' => self::TEAM,
             'title' => 'Duesseldorf allgemein', 'location' => 'Duesseldorf', 'is_active' => 1,
             'created_at' => $now, 'updated_at' => $now],
        ]);

        // Die Sammel-Stelle hat GENAU EINE Phase der Ordnung 1 und keine Felder —
        // wie "Sonstiges" live. Die echte Stelle hat die Phase gleicher Ordnung,
        // auf die der Abgleich abbilden muss, plus eine spaetere.
        Capsule::table('rec_phases')->insert([
            ['id' => self::PHASE_SAMMEL, 'uuid' => 'sueph-191', 'team_id' => self::TEAM,
             'rec_position_id' => self::POSITION_SAMMEL, 'name' => 'Bewerbung', 'order' => 1,
             'is_active' => 1, 'created_at' => $now, 'updated_at' => $now],
            ['id' => self::PHASE_DUS_EINGANG, 'uuid' => 'sueph-192', 'team_id' => self::TEAM,
             'rec_position_id' => self::POSITION_DUESSELDORF, 'name' => 'Bewerbung', 'order' => 1,
             'is_active' => 1, 'created_at' => $now, 'updated_at' => $now],
            ['id' => self::PHASE_DUS_ONBOARDING, 'uuid' => 'sueph-193', 'team_id' => self::TEAM,
             'rec_position_id' => self::POSITION_DUESSELDORF, 'name' => 'Onboarding', 'order' => 3,
             'is_active' => 1, 'created_at' => $now, 'updated_at' => $now],
        ]);

        Capsule::table('rec_postings')->insert([
            ['id' => self::POSTING_SAMMEL, 'uuid' => 'suepstg-910', 'rec_position_id' => self::POSITION_SAMMEL,
             'team_id' => self::TEAM, 'title' => 'Sonstiges', 'status' => 'published',
             'is_active' => 1, 'created_at' => $now, 'updated_at' => $now],
            ['id' => self::POSTING_DUESSELDORF, 'uuid' => 'suepstg-920', 'rec_position_id' => self::POSITION_DUESSELDORF,
             'team_id' => self::TEAM, 'title' => 'Servicekraefte Duesseldorf', 'status' => 'published',
             'is_active' => 1, 'created_at' => $now, 'updated_at' => $now],
        ]);

        Capsule::table('rec_interviews')->insert([
            ['id' => self::INTERVIEW, 'uuid' => 'sueiv-930', 'team_id' => self::TEAM,
             'interview_type_id' => null, 'rec_position_id' => self::POSITION_DUESSELDORF,
             'title' => 'Schulung Duesseldorf', 'location' => 'Duesseldorf',
             'starts_at' => '2026-09-20 10:00:00', 'max_participants' => 5,
             'is_active' => 1, 'created_at' => $now, 'updated_at' => $now],
        ]);

        $applicants = [];
        foreach ([
            self::APPLICANT_EINE_ANZEIGE => 'sueapp-1101',
            self::APPLICANT_STELLE_LEER => 'sueapp-1102',
            self::APPLICANT_FESTGELEGT => 'sueapp-1103',
            self::APPLICANT_ZWEI_ANZEIGEN => 'sueapp-1104',
        ] as $id => $uuid) {
            $applicants[] = ['id' => $id, 'uuid' => $uuid, 'team_id' => self::TEAM,
                'applied_at' => '2026-09-01', 'rec_phase_id' => self::PHASE_SAMMEL,
                'rec_position_id' => $id === self::APPLICANT_STELLE_LEER ? null : self::POSITION_SAMMEL,
                'owned_by_user_id' => 1, 'is_test' => 0,
                'created_at' => $now, 'updated_at' => $now];
        }
        Capsule::table('rec_applicants')->insert($applicants);

        // Drei von vier haben NUR die echte Anzeige — so sieht Momos Zustand aus:
        // die Sammel-Anzeige ist beim Umschluesseln verschwunden, die Phase nicht
        // mitgekommen. Nur 1104 haelt beide, mit der Sammel-Anzeige als der
        // fruehesten (so entsteht der Uebergangszustand).
        $pivots = [];
        foreach ([self::APPLICANT_EINE_ANZEIGE, self::APPLICANT_STELLE_LEER, self::APPLICANT_FESTGELEGT] as $id) {
            $pivots[] = ['rec_applicant_id' => $id, 'rec_posting_id' => self::POSTING_DUESSELDORF,
                         'applied_at' => '2026-09-04', 'created_at' => $now, 'updated_at' => $now];
        }
        $pivots[] = ['rec_applicant_id' => self::APPLICANT_ZWEI_ANZEIGEN, 'rec_posting_id' => self::POSTING_SAMMEL,
                     'applied_at' => '2026-09-01', 'created_at' => $now, 'updated_at' => $now];
        $pivots[] = ['rec_applicant_id' => self::APPLICANT_ZWEI_ANZEIGEN, 'rec_posting_id' => self::POSTING_DUESSELDORF,
                     'applied_at' => '2026-09-04', 'created_at' => $now, 'updated_at' => $now];
        Capsule::table('rec_applicant_posting')->insert($pivots);

        // Nur 1103 ist festgelegt — aktive Buchung (nicht storniert).
        Capsule::table('rec_interview_bookings')->insert([
            ['uuid' => 'suebk-1103', 'rec_interview_id' => self::INTERVIEW,
             'rec_applicant_id' => self::APPLICANT_FESTGELEGT, 'status' => 'booked',
             'is_active' => 1, 'team_id' => self::TEAM, 'cancelled_by' => null, 'cancelled_at' => null,
             'created_at' => $now, 'updated_at' => $now],
        ]);
    }
}
