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
use Platform\Recruiting\Models\RecPhase;
use Platform\Recruiting\Models\RecPosition;

/**
 * Wenn ein Bewerber eine Schulung in einer anderen Filiale bucht, wechselt er die
 * Stelle — und dabei wurde ihm bislang eine ZUFAELLIGE Ausschreibung der neuen
 * Stelle angehaengt (`->where('is_active', true)->first()` ohne Sortierung). Das
 * verfaelscht die activity-Dimension der KPI-Statistik, weil die Bewerbung in
 * einer Anzeigen-Zeile landet, die nichts mit dem gebuchten Termin zu tun hat.
 *
 * Diese Fassung des Tests prueft die neue Antwort auf das Problem: keine
 * Ausschreibung wird mehr ausgewaehlt, weil der Pivot beim Stellenwechsel gar
 * nicht mehr angefasst wird. Die Bewerbung bleibt bei der Anzeige, die sie
 * tatsaechlich gebracht hat — das ist die einzige nicht geratene Zuordnung.
 *
 * Aufbau wie die anderen Integrationstests des Moduls (Container + Capsule von
 * Hand, ECHTE Migrationen per glob, auth() als Attrappe, feste Uhr) — Kopf aus
 * InterviewPostingTeamScopeTest kopiert, nur der Bestand ist neu.
 */
class PositionSwitchPostingChoiceTest extends TestCase
{
    private const TEAM = 8;

    /** Alte Stelle: eine Ausschreibung. */
    private const POSITION_DUESSELDORF = 81;
    private const POSTING_DUESSELDORF = 810;

    /** Neue Stelle: DREI aktive Ausschreibungen — die Zufalls-Falle. */
    private const POSITION_MOENCHENGLADBACH = 82;
    private const POSTING_MG_1 = 820;
    private const POSTING_MG_2 = 821;
    private const POSTING_MG_3 = 822;

    /** Termin mit gepflegter Ausschreibung — die einzige nicht geratene Antwort. */
    private const INTERVIEW_MIT_POSTING = 830;

    /** Termin OHNE gepflegte Ausschreibung — Fallback muss reproduzierbar sein. */
    private const INTERVIEW_OHNE_POSTING = 831;

    private const PHASE_DUESSELDORF = 101;
    private const PHASE_MOENCHENGLADBACH = 102;

    private const APPLICANT = 1010;

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
                // nicht benutzt: switchToPosition() ruft nur auth()->user() indirekt
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

    /**
     * Jeder Test faengt beim Bestand aus seed() an: Bewerber 1010 auf Stelle 81
     * (Duesseldorf), Pivot auf Ausschreibung 810. Ohne diesen Reset saehe z.B.
     * test_der_log_nennt_die_alte_stelle_und_anzeige die Spuren des VORHERIGEN
     * Tests (der Bewerber waere schon nach Moenchengladbach gewechselt) — die
     * Suite liefe in Deklarationsreihenfolge grün, aber nur zufaellig und nicht
     * pro Test isoliert.
     */
    protected function setUp(): void
    {
        parent::setUp();

        self::setzeBewerberAufAusgangszustand();
    }

    public function test_der_wechsel_setzt_die_stelle(): void
    {
        $applicant = RecApplicant::find(1010);

        $applicant->switchToPosition(RecPosition::find(82));

        $this->assertSame(82, (int) $applicant->fresh()->rec_position_id);
        $this->assertSame(82, $applicant->fresh()->primaryPosition()?->id);
    }

    public function test_der_wechsel_laesst_die_herkunft_unberuehrt(): void
    {
        // Das ist der Kern des ganzen Umbaus: die Bewerbung bleibt bei der Anzeige,
        // die sie gebracht hat. Vorher wurde sie geloescht und durch eine beliebige
        // Anzeige der neuen Stelle ersetzt.
        $vorher = Capsule::table('rec_applicant_posting')
            ->where('rec_applicant_id', 1010)->get()->toArray();

        RecApplicant::find(1010)->switchToPosition(RecPosition::find(82));

        $nachher = Capsule::table('rec_applicant_posting')
            ->where('rec_applicant_id', 1010)->get()->toArray();

        $this->assertEquals($vorher, $nachher, 'kein detach, kein attach, kein neues applied_at');
    }

    public function test_die_phase_wandert_weiter_mit(): void
    {
        RecApplicant::find(1010)->switchToPosition(RecPosition::find(82));

        $phase = RecPhase::find(RecApplicant::find(1010)->rec_phase_id);

        $this->assertSame(82, (int) $phase->rec_position_id, 'Phase gehoert jetzt zur neuen Stelle');
        $this->assertSame(1, (int) $phase->order, 'dieselbe order wie vorher');
    }

    /**
     * Bewerber 1010 zurueck auf den Bestand aus seed(): Pivot einzig auf
     * Ausschreibung 810 (Duesseldorf), Phase 101. Gemeinsame Stelle fuer setUp()
     * (Isolation zwischen den vier Testmethoden) und wechselMitTermin() (zwei
     * Laeufe INNERHALB einer Testmethode) — vorher stand dieselbe Reset-Logik an
     * beiden Stellen wortgleich.
     */
    private static function setzeBewerberAufAusgangszustand(): void
    {
        Capsule::table('rec_applicant_posting')->where('rec_applicant_id', self::APPLICANT)->delete();
        Capsule::table('rec_applicant_posting')->insert([
            'rec_applicant_id' => self::APPLICANT, 'rec_posting_id' => self::POSTING_DUESSELDORF,
            'created_at' => self::HEUTE, 'updated_at' => self::HEUTE,
        ]);
        Capsule::table('rec_applicants')->where('id', self::APPLICANT)->update([
            'rec_phase_id' => self::PHASE_DUESSELDORF,
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
            ['id' => self::POSITION_DUESSELDORF, 'uuid' => 'spos-81', 'team_id' => self::TEAM,
             'title' => 'Duesseldorf', 'location' => 'Duesseldorf', 'is_active' => 1,
             'created_at' => $now, 'updated_at' => $now],
            ['id' => self::POSITION_MOENCHENGLADBACH, 'uuid' => 'spos-82', 'team_id' => self::TEAM,
             'title' => 'Moenchengladbach', 'location' => 'Moenchengladbach', 'is_active' => 1,
             'created_at' => $now, 'updated_at' => $now],
        ]);

        Capsule::table('rec_postings')->insert([
            ['id' => self::POSTING_DUESSELDORF, 'uuid' => 'spost-810', 'team_id' => self::TEAM,
             'rec_position_id' => self::POSITION_DUESSELDORF, 'title' => 'Kellner (m/w/d)',
             'activity' => 'Service', 'status' => 'published', 'is_active' => 1,
             'published_at' => null, 'closes_at' => null, 'bedarf' => null,
             'bewerbungs_faktor' => null, 'created_at' => $now, 'updated_at' => $now],
            ['id' => self::POSTING_MG_1, 'uuid' => 'spost-820', 'team_id' => self::TEAM,
             'rec_position_id' => self::POSITION_MOENCHENGLADBACH, 'title' => 'Kellner MG (m/w/d)',
             'activity' => 'Service', 'status' => 'published', 'is_active' => 1,
             'published_at' => null, 'closes_at' => null, 'bedarf' => null,
             'bewerbungs_faktor' => null, 'created_at' => $now, 'updated_at' => $now],
            ['id' => self::POSTING_MG_2, 'uuid' => 'spost-821', 'team_id' => self::TEAM,
             'rec_position_id' => self::POSITION_MOENCHENGLADBACH, 'title' => 'Kueche MG (m/w/d)',
             'activity' => 'Kueche', 'status' => 'published', 'is_active' => 1,
             'published_at' => null, 'closes_at' => null, 'bedarf' => null,
             'bewerbungs_faktor' => null, 'created_at' => $now, 'updated_at' => $now],
            ['id' => self::POSTING_MG_3, 'uuid' => 'spost-822', 'team_id' => self::TEAM,
             'rec_position_id' => self::POSITION_MOENCHENGLADBACH, 'title' => 'Spueler MG (m/w/d)',
             'activity' => 'Kueche', 'status' => 'published', 'is_active' => 1,
             'published_at' => null, 'closes_at' => null, 'bedarf' => null,
             'bewerbungs_faktor' => null, 'created_at' => $now, 'updated_at' => $now],
        ]);

        Capsule::table('rec_interviews')->insert([
            // Termin MIT gepflegter Ausschreibung — die einzige nicht geratene Antwort.
            ['id' => self::INTERVIEW_MIT_POSTING, 'uuid' => 'siv-830', 'team_id' => self::TEAM,
             'interview_type_id' => null, 'rec_position_id' => self::POSITION_MOENCHENGLADBACH,
             'rec_posting_id' => self::POSTING_MG_2, 'title' => 'Schulung MG',
             'location' => 'Moenchengladbach, Zentrale', 'starts_at' => '2026-08-20 10:00:00',
             'max_participants' => 5, 'is_active' => 1, 'created_at' => $now, 'updated_at' => $now],
            // Termin OHNE gepflegte Ausschreibung — Fallback muss greifen.
            ['id' => self::INTERVIEW_OHNE_POSTING, 'uuid' => 'siv-831', 'team_id' => self::TEAM,
             'interview_type_id' => null, 'rec_position_id' => self::POSITION_MOENCHENGLADBACH,
             'rec_posting_id' => null, 'title' => 'Schulung MG (2)',
             'location' => 'Moenchengladbach, Zentrale', 'starts_at' => '2026-08-21 10:00:00',
             'max_participants' => 5, 'is_active' => 1, 'created_at' => $now, 'updated_at' => $now],
        ]);

        Capsule::table('rec_phases')->insert([
            ['id' => self::PHASE_DUESSELDORF, 'uuid' => 'sph-101', 'team_id' => self::TEAM,
             'rec_position_id' => self::POSITION_DUESSELDORF, 'name' => 'Eingang', 'order' => 1,
             'is_active' => 1, 'created_at' => $now, 'updated_at' => $now],
            ['id' => self::PHASE_MOENCHENGLADBACH, 'uuid' => 'sph-102', 'team_id' => self::TEAM,
             'rec_position_id' => self::POSITION_MOENCHENGLADBACH, 'name' => 'Eingang', 'order' => 1,
             'is_active' => 1, 'created_at' => $now, 'updated_at' => $now],
        ]);

        Capsule::table('rec_applicants')->insert([
            ['id' => self::APPLICANT, 'uuid' => 'sapp-1010', 'team_id' => self::TEAM,
             'applied_at' => '2026-07-01', 'rec_phase_id' => self::PHASE_DUESSELDORF, 'is_test' => 0,
             'created_at' => $now, 'updated_at' => $now],
        ]);

        Capsule::table('rec_applicant_posting')->insert([
            ['rec_applicant_id' => self::APPLICANT, 'rec_posting_id' => self::POSTING_DUESSELDORF,
             'created_at' => $now, 'updated_at' => $now],
        ]);
    }
}
