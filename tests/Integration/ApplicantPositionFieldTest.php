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
 * Die Stelle einer Bewerbung wurde bisher nur ueber die verknuepfte Anzeige
 * ERRATEN (RecApplicant::positions() liest sie aus den postings()) — genau
 * deshalb musste ein Stellenwechsel die Pivot-Verknuepfung umschreiben und
 * verfaelschte dabei die activity-Dimension der KPI-Statistik. Dieser Test
 * belegt das neue, EIGENE Feld `rec_position_id` samt seiner beiden Regeln:
 *
 *  - nullOnDelete statt cascadeOnDelete: eine geloeschte Stelle darf keine
 *    Bewerbung mitnehmen (Model-Events feuern bei DB-Kaskaden ausserdem
 *    nicht, daher braucht das Modul fuer Phasen einen eigenen Observer).
 *  - istFestgelegt(): Phase >= 3 ODER eine AKTIVE Buchung — eine STORNIERTE
 *    Buchung zaehlt nicht (dieselbe Bedingung wie RecApplicant::702,
 *    `whereNotIn('status', ['cancelled'])`, wortgleich uebernommen).
 *
 * Aufbau wie die anderen Integrationstests des Moduls (Container + Capsule
 * von Hand, ECHTE Migrationen per glob, auth() als Attrappe, feste Uhr) —
 * Kopf aus InterviewPostingTeamScopeTest kopiert, nur der Bestand ist neu.
 */
class ApplicantPositionFieldTest extends TestCase
{
    private const TEAM = 8;

    private const POSITION_DUESSELDORF = 81;
    private const POSITION_MOENCHENGLADBACH = 82;

    /** Anzeige der Stelle 81, mit Bewerber 1010 verpivotet (bisheriger Weg). */
    private const POSTING_DUESSELDORF = 810;

    /** Anzeige der Stelle 82 — Ziel der HR-Korrektur in den Nachzieh-Tests. */
    private const POSTING_MOENCHENGLADBACH = 820;

    private const PHASE_EINGANG = 101;
    private const PHASE_ONBOARDING = 103;

    private const INTERVIEW = 830;

    /** Phase 1, keine Buchung. */
    private const APPLICANT_OHNE_FESTLEGUNG = 1010;

    /** Phase 3, keine Buchung — Phase allein reicht. */
    private const APPLICANT_PHASE_DREI = 1011;

    /** Phase 1, AKTIVE Buchung — Buchung allein reicht. */
    private const APPLICANT_AKTIVE_BUCHUNG = 1012;

    /** Phase 1, STORNIERTE Buchung — zaehlt NICHT. */
    private const APPLICANT_STORNIERTE_BUCHUNG = 1013;

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

        // Mini-Shims fuer die einzigen VIER fremden Tabellen, auf die IRGENDEINE
        // Migration dieses Moduls per constrained() zeigt (per grep verifiziert:
        // teams, users, hcm_job_titles, comms_channels — alles andere sind
        // eigene rec_*-Tabellen, die runRealMigrations() ohnehin anlegt).
        // Gebraucht NUR fuer test_eine_geloeschte_stelle_nimmt_die_bewerbung_
        // nicht_mit(): dort wird PRAGMA foreign_keys kurz auf ON gesetzt (siehe
        // dort), und SQLite validiert beim Vorbereiten der SET-NULL-Aktion auf
        // einer betroffenen Tabelle ALLE ihre FK-Definitionen — auch die, deren
        // Spalte hier immer NULL bleibt. Leere Tabellen (keine Zeilen noetig)
        // reichen, weil dabei kein bestehender Wert neu befuellt wird.
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

    /**
     * Zwei Tests veraendern den gemeinsamen Bestand (rec_position_id auf
     * 1010 setzen, Stelle 82 loeschen) — ohne Reset saehen nachfolgende
     * Tests je nach Ausfuehrungsreihenfolge eine bereits geloeschte Stelle
     * 82 oder ein bereits gesetztes Feld. Muster aus
     * PositionSwitchPostingChoiceTest::setzeBewerberAufAusgangszustand().
     */
    protected function setUp(): void
    {
        parent::setUp();

        self::setzeBestandAufAusgangszustand();
    }

    public function test_die_stelle_ist_ein_eigenes_feld_mit_relation(): void
    {
        Capsule::table('rec_applicants')->where('id', 1010)->update(['rec_position_id' => 82]);

        $applicant = RecApplicant::find(1010);

        $this->assertSame(82, (int) $applicant->rec_position_id);
        $this->assertSame('Moenchengladbach', $applicant->position?->title);
    }

    public function test_eine_geloeschte_stelle_nimmt_die_bewerbung_nicht_mit(): void
    {
        // nullOnDelete statt cascadeOnDelete: eine Stelle zu loeschen darf keine
        // Bewerbung vernichten. (Und Model-Events feuern bei DB-Kaskaden nicht.)
        //
        // In dieser Testumgebung ist PRAGMA foreign_keys sonst AUS (Konvention
        // des Moduls, siehe TestSchema.php) — genau das SET NULL ist hier aber
        // der Pruefgegenstand, also kurz scharf stellen. Aus/An statt dauerhaft
        // AN, damit die anderen Tests dieser Klasse unberuehrt bleiben.
        Capsule::table('rec_applicants')->where('id', 1010)->update(['rec_position_id' => 82]);
        Capsule::connection()->statement('PRAGMA foreign_keys = ON');
        try {
            Capsule::table('rec_positions')->where('id', 82)->delete();
        } finally {
            Capsule::connection()->statement('PRAGMA foreign_keys = OFF');
        }

        $applicant = RecApplicant::find(1010);

        $this->assertNotNull($applicant, 'die Bewerbung bleibt');
        $this->assertNull($applicant->rec_position_id, 'die Stelle wird geleert');
    }

    public function test_festgelegt_gilt_ab_phase_drei_oder_aktiver_buchung(): void
    {
        $this->assertFalse(RecApplicant::find(1010)->istFestgelegt(), 'Phase 1, keine Buchung');
        $this->assertTrue(RecApplicant::find(1011)->istFestgelegt(), 'Phase 3 ohne Buchung');
        $this->assertTrue(RecApplicant::find(1012)->istFestgelegt(), 'Phase 1 mit aktiver Buchung');
        $this->assertFalse(RecApplicant::find(1013)->istFestgelegt(), 'eine STORNIERTE Buchung zaehlt nicht');
    }

    public function test_die_fassade_liest_das_feld(): void
    {
        // Pivot zeigt auf Stelle 81, Feld auf 82 — das FELD gewinnt.
        Capsule::table('rec_applicants')->where('id', 1010)->update(['rec_position_id' => 82]);

        $this->assertSame(82, RecApplicant::find(1010)->primaryPosition()?->id);
    }

    public function test_ohne_feld_gilt_der_bisherige_weg(): void
    {
        // Bestandsdaten vor dem Backfill: das Feld ist leer, die Antwort muss
        // exakt die von heute sein (Stelle der fruehesten Anzeige).
        Capsule::table('rec_applicants')->where('id', 1010)->update(['rec_position_id' => null]);

        $this->assertSame(81, RecApplicant::find(1010)->primaryPosition()?->id);
    }

    public function test_beim_verknuepfen_einer_anzeige_wird_die_stelle_gesetzt(): void
    {
        // Der gemeinsame Nenner aller fuenf Anlege-Wege: sie haengen eine Anzeige
        // an. Geprueft wird der Effekt, nicht jeder Weg einzeln — die Wege selbst
        // brauchen eine gebootete App.
        $applicant = RecApplicant::create([
            'uuid' => 'apf-neu-1', 'team_id' => self::TEAM, 'applied_at' => '2026-08-01',
        ]);
        $applicant->postings()->attach(810, ['applied_at' => '2026-08-01']);
        $applicant->refresh()->stelleAusAnzeigeUebernehmen();

        $this->assertSame(81, (int) $applicant->fresh()->rec_position_id);
    }

    public function test_ohne_anzeige_bleibt_die_stelle_leer(): void
    {
        // Import ohne Bindung, Inbound ohne Match: kein Raten, kein Default.
        // "Leer heisst nicht gepflegt" — die Statistik benennt diese Faelle.
        $applicant = RecApplicant::create([
            'uuid' => 'apf-neu-2', 'team_id' => self::TEAM, 'applied_at' => '2026-08-01',
        ]);
        $applicant->stelleAusAnzeigeUebernehmen();

        $this->assertNull($applicant->fresh()->rec_position_id);
    }

    public function test_eine_bereits_gesetzte_stelle_wird_nicht_ueberschrieben(): void
    {
        // Die tragende Nicht-Ueberschreib-Regel: eine SPAETER verknuepfte Anzeige
        // einer ANDEREN Stelle darf eine bereits FESTGELEGTE Stelle nicht
        // zurueckdrehen — sonst wuerde ein nachtraeglich verknuepftes Posting eine
        // Festlegung rueckgaengig machen.
        Capsule::table('rec_postings')->insert([
            'id' => 811, 'uuid' => 'spstg-82', 'rec_position_id' => self::POSITION_MOENCHENGLADBACH,
            'team_id' => self::TEAM, 'title' => 'Moenchengladbach Anzeige', 'status' => 'published',
            'is_active' => 1, 'created_at' => self::HEUTE, 'updated_at' => self::HEUTE,
        ]);

        $applicant = RecApplicant::create([
            'uuid' => 'apf-neu-3', 'team_id' => self::TEAM, 'applied_at' => '2026-08-01',
            'rec_position_id' => self::POSITION_DUESSELDORF,
        ]);
        $applicant->postings()->attach(811, ['applied_at' => '2026-08-01']);
        $applicant->refresh()->stelleAusAnzeigeUebernehmen();

        $this->assertSame(
            self::POSITION_DUESSELDORF,
            (int) $applicant->fresh()->rec_position_id,
            'die bereits gesetzte Stelle bleibt stehen'
        );

        // Gegenprobe im selben Test: bei LEERER Stelle setzt derselbe Aufruf sie
        // wirklich — sonst behauptet der Test oben bloss "nichts passiert".
        $applicantOhneFestlegung = RecApplicant::create([
            'uuid' => 'apf-neu-4', 'team_id' => self::TEAM, 'applied_at' => '2026-08-01',
        ]);
        $applicantOhneFestlegung->postings()->attach(811, ['applied_at' => '2026-08-01']);
        $applicantOhneFestlegung->refresh()->stelleAusAnzeigeUebernehmen();

        $this->assertSame(
            self::POSITION_MOENCHENGLADBACH,
            (int) $applicantOhneFestlegung->fresh()->rec_position_id,
            'ohne vorherige Festlegung setzt derselbe Aufruf die Stelle'
        );
    }

    public function test_vor_der_festlegung_zieht_die_stelle_mit(): void
    {
        // HR korrigiert eine falsch zugeordnete Anzeige: Stelle 81 -> 82.
        Capsule::table('rec_applicants')->where('id', 1010)->update(['rec_position_id' => 81]);
        Capsule::table('rec_applicant_posting')->where('rec_applicant_id', 1010)->delete();
        Capsule::table('rec_applicant_posting')->insert([
            'rec_applicant_id' => 1010, 'rec_posting_id' => 820,
            'created_at' => self::HEUTE, 'updated_at' => self::HEUTE,
        ]);

        RecApplicant::find(1010)->reconcilePositionState();

        $this->assertSame(82, (int) RecApplicant::find(1010)->rec_position_id);
    }

    public function test_nach_der_festlegung_bleibt_die_stelle_stehen(): void
    {
        // 1012 hat eine aktive Buchung. Eine Korrektur der Anzeige darf ihn nicht
        // aus der Filiale ziehen, in der er zur Schulung angemeldet ist.
        Capsule::table('rec_applicants')->where('id', 1012)->update(['rec_position_id' => 81]);
        Capsule::table('rec_applicant_posting')->where('rec_applicant_id', 1012)->delete();
        Capsule::table('rec_applicant_posting')->insert([
            'rec_applicant_id' => 1012, 'rec_posting_id' => 820,
            'created_at' => self::HEUTE, 'updated_at' => self::HEUTE,
        ]);

        RecApplicant::find(1012)->reconcilePositionState();

        $this->assertSame(81, (int) RecApplicant::find(1012)->rec_position_id,
            'die Festlegung gewinnt gegen die Anzeigen-Korrektur');
    }

    // -----------------------------------------------------------------
    // Werkzeug
    // -----------------------------------------------------------------

    /**
     * Setzt den Teil des Bestands zurueck, den die Tests dieser Klasse
     * veraendern: Stelle 82 (von test_eine_geloeschte_stelle_... geloescht),
     * rec_position_id von Bewerber 1010 und 1012 (von mehreren Tests gesetzt)
     * sowie die Anzeigen-Verknuepfung (Pivot) beider Bewerber (von den
     * Nachzieh-Tests test_vor/nach_der_festlegung umgehaengt auf Posting 820).
     *
     * Stolperfalle (per Debug gefunden, nicht nur vermutet): rec_postings.
     * rec_position_id ist cascadeOnDelete. Das Loeschen von Stelle 82 in
     * test_eine_geloeschte_stelle_... nimmt darum Posting 820 GLEICH MIT —
     * ohne diesen zweiten Wiederherstell-Block liefe test_vor_der_festlegung_
     * zieht_die_stelle_mit() je nach Ausfuehrungsreihenfolge gegen ein
     * verwaistes Pivot (Posting existiert nicht mehr) und postings() waere
     * durch den inneren Join der BelongsToMany leer.
     */
    private static function setzeBestandAufAusgangszustand(): void
    {
        if (!Capsule::table('rec_positions')->where('id', self::POSITION_MOENCHENGLADBACH)->exists()) {
            Capsule::table('rec_positions')->insert([
                'id' => self::POSITION_MOENCHENGLADBACH, 'uuid' => 'spos-82', 'team_id' => self::TEAM,
                'title' => 'Moenchengladbach', 'location' => 'Moenchengladbach', 'is_active' => 1,
                'created_at' => self::HEUTE, 'updated_at' => self::HEUTE,
            ]);
        }

        if (!Capsule::table('rec_postings')->where('id', self::POSTING_MOENCHENGLADBACH)->exists()) {
            Capsule::table('rec_postings')->insert([
                'id' => self::POSTING_MOENCHENGLADBACH, 'uuid' => 'spstg-820', 'rec_position_id' => self::POSITION_MOENCHENGLADBACH,
                'team_id' => self::TEAM, 'title' => 'Moenchengladbach Anzeige', 'status' => 'published',
                'is_active' => 1, 'created_at' => self::HEUTE, 'updated_at' => self::HEUTE,
            ]);
        }

        // nullOnDelete an rec_interviews.rec_position_id leert dieses Feld beim
        // Loeschen der Stelle mit — fuer den Termin-Bestand wiederherstellen.
        Capsule::table('rec_interviews')->where('id', self::INTERVIEW)->update([
            'rec_position_id' => self::POSITION_MOENCHENGLADBACH,
        ]);

        Capsule::table('rec_applicants')->where('id', 1010)->update([
            'rec_position_id' => null,
        ]);
        Capsule::table('rec_applicants')->where('id', 1012)->update([
            'rec_position_id' => null,
        ]);

        // Pivot von 1010 und 1012 auf den seed()-Ausgangszustand zuruecksetzen:
        // 1010 zeigt auf Posting 810 (Stelle 81), 1012 hat urspruenglich gar
        // keine Anzeige verknuepft.
        Capsule::table('rec_applicant_posting')->whereIn('rec_applicant_id', [1010, 1012])->delete();
        Capsule::table('rec_applicant_posting')->insert([
            'rec_applicant_id' => self::APPLICANT_OHNE_FESTLEGUNG, 'rec_posting_id' => self::POSTING_DUESSELDORF,
            'applied_at' => '2026-07-01', 'created_at' => self::HEUTE, 'updated_at' => self::HEUTE,
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

        Capsule::table('rec_phases')->insert([
            ['id' => self::PHASE_EINGANG, 'uuid' => 'sph-101', 'team_id' => self::TEAM,
             'rec_position_id' => self::POSITION_DUESSELDORF, 'name' => 'Eingang', 'order' => 1,
             'is_active' => 1, 'created_at' => $now, 'updated_at' => $now],
            ['id' => self::PHASE_ONBOARDING, 'uuid' => 'sph-103', 'team_id' => self::TEAM,
             'rec_position_id' => self::POSITION_DUESSELDORF, 'name' => 'Onboarding', 'order' => 3,
             'is_active' => 1, 'created_at' => $now, 'updated_at' => $now],
        ]);

        Capsule::table('rec_interviews')->insert([
            ['id' => self::INTERVIEW, 'uuid' => 'siv-830', 'team_id' => self::TEAM,
             'interview_type_id' => null, 'rec_position_id' => self::POSITION_MOENCHENGLADBACH,
             'title' => 'Schulung MG', 'location' => 'Moenchengladbach, Zentrale',
             'starts_at' => '2026-08-20 10:00:00', 'max_participants' => 5,
             'is_active' => 1, 'created_at' => $now, 'updated_at' => $now],
        ]);

        Capsule::table('rec_applicants')->insert([
            ['id' => self::APPLICANT_OHNE_FESTLEGUNG, 'uuid' => 'sapp-1010', 'team_id' => self::TEAM,
             'applied_at' => '2026-07-01', 'rec_phase_id' => self::PHASE_EINGANG, 'is_test' => 0,
             'created_at' => $now, 'updated_at' => $now],
            ['id' => self::APPLICANT_PHASE_DREI, 'uuid' => 'sapp-1011', 'team_id' => self::TEAM,
             'applied_at' => '2026-07-01', 'rec_phase_id' => self::PHASE_ONBOARDING, 'is_test' => 0,
             'created_at' => $now, 'updated_at' => $now],
            ['id' => self::APPLICANT_AKTIVE_BUCHUNG, 'uuid' => 'sapp-1012', 'team_id' => self::TEAM,
             'applied_at' => '2026-07-01', 'rec_phase_id' => self::PHASE_EINGANG, 'is_test' => 0,
             'created_at' => $now, 'updated_at' => $now],
            ['id' => self::APPLICANT_STORNIERTE_BUCHUNG, 'uuid' => 'sapp-1013', 'team_id' => self::TEAM,
             'applied_at' => '2026-07-01', 'rec_phase_id' => self::PHASE_EINGANG, 'is_test' => 0,
             'created_at' => $now, 'updated_at' => $now],
        ]);

        // Anzeige der Stelle 81, verknuepft mit Bewerber 1010 — der "bisherige
        // Weg" (Stelle der fruehesten verknuepften Anzeige), den primaryPosition()
        // als Fallback nutzt, solange rec_position_id leer ist.
        Capsule::table('rec_postings')->insert([
            'id' => self::POSTING_DUESSELDORF, 'uuid' => 'spstg-81', 'rec_position_id' => self::POSITION_DUESSELDORF,
            'team_id' => self::TEAM, 'title' => 'Duesseldorf Anzeige', 'status' => 'published',
            'is_active' => 1, 'created_at' => $now, 'updated_at' => $now,
        ]);

        Capsule::table('rec_applicant_posting')->insert([
            'rec_applicant_id' => self::APPLICANT_OHNE_FESTLEGUNG, 'rec_posting_id' => self::POSTING_DUESSELDORF,
            'applied_at' => '2026-07-01', 'created_at' => $now, 'updated_at' => $now,
        ]);

        // Anzeige der Stelle 82 — Ziel einer HR-Korrektur in den Nachzieh-Tests
        // (test_vor/nach_der_festlegung). Wird dort per Pivot verknuepft, nicht
        // hier — nur die Anzeige selbst muss existieren.
        Capsule::table('rec_postings')->insert([
            'id' => self::POSTING_MOENCHENGLADBACH, 'uuid' => 'spstg-820', 'rec_position_id' => self::POSITION_MOENCHENGLADBACH,
            'team_id' => self::TEAM, 'title' => 'Moenchengladbach Anzeige', 'status' => 'published',
            'is_active' => 1, 'created_at' => $now, 'updated_at' => $now,
        ]);

        Capsule::table('rec_interview_bookings')->insert([
            ['uuid' => 'sbk-1012', 'rec_interview_id' => self::INTERVIEW, 'rec_applicant_id' => self::APPLICANT_AKTIVE_BUCHUNG,
             'status' => 'booked', 'is_active' => 1, 'team_id' => self::TEAM,
             'cancelled_by' => null, 'cancelled_at' => null,
             'created_at' => $now, 'updated_at' => $now],
            ['uuid' => 'sbk-1013', 'rec_interview_id' => self::INTERVIEW, 'rec_applicant_id' => self::APPLICANT_STORNIERTE_BUCHUNG,
             'status' => 'cancelled', 'is_active' => 1, 'team_id' => self::TEAM,
             'cancelled_by' => 'system', 'cancelled_at' => $now,
             'created_at' => $now, 'updated_at' => $now],
        ]);
    }
}
