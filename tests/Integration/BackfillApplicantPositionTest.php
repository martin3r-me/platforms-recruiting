<?php

namespace Platform\Recruiting\Tests\Integration;

use Illuminate\Container\Container;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Console\Commands\BackfillApplicantPosition;
use Platform\Recruiting\Models\RecApplicant;
use Platform\Recruiting\Support\PhaseTransitionTrigger;

/**
 * Der Backfill fuer den BESTAND: bis zu diesem Umbau war die Stelle einer
 * Bewerbung DEFINITIONSGEMAESS die der fruehesten verknuepften Anzeige — exakt
 * das, was RecApplicant::fruehesteAnzeige() berechnet (siehe deren Klassendoc
 * fuer die Extraktion aus vier vormals wortgleichen Kopien). Das Kommando
 * schreibt also keine Schaetzung, sondern macht explizit, was vorher implizit
 * galt — und darf deshalb nur LEERE Felder fuellen, nie einen gepflegten Wert
 * ueberschreiben.
 *
 * Zwei Bestandsfaelle:
 *  - 1010: normaler Fall, Pivot auf die Anzeige 810 (Stelle 81), Feld leer.
 *  - 1014: ALTFALL — seine Verknuepfung entstand durch einen Stellenwechsel vor
 *    dem Umbau (Transition-Log mit trigger=position_switch), die urspruengliche
 *    Anzeige ist geloescht und nicht rekonstruierbar. Die vorhandene
 *    Verknuepfung wird markiert (matched_via='position_switch'), damit die
 *    Statistik sie spaeter nicht als echte Bewerbung auf diese Anzeige zaehlt.
 *    Sein Feld ist bereits gesetzt (82) — das ist die Stelle, auf die er sich
 *    festgelegt hat, und bleibt unangetastet.
 *  - 1015: GEGENPROBE zur Markierung — sein Pivot traegt bereits eine ECHTE
 *    Match-Information aus dem Inbound-Matching (matched_via='inbound'). Auch
 *    er hat einen position_switch-Log-Eintrag, aber die Markierung darf diesen
 *    Wert nicht ueberschreiben (whereNull('matched_via') beim Markieren). 1014
 *    UND 1015 zusammen belegen ausserdem, dass der 'markiert'-Zaehler die
 *    tatsaechlich GESCHRIEBENEN Zeilen zaehlt (hier: 1), nicht die Kandidaten
 *    aus dem Transition-Log (hier: 2) — Review-Befund, siehe Fix-Bericht.
 *  - 1020: FREMDES TEAM — leeres Feld, eigene Anzeige, aber Team 9 statt 8.
 *    Belegt, dass --team-id wirklich eine Grenze zieht (mit Gegenprobe ohne
 *    Einschraenkung).
 *  - 1030: KEINE Anzeige verknuepft — die tragende Regel "bleibt leer, kein
 *    Raten" (ohneAnzeige-Zweig).
 *
 * Aufbau wie die anderen Integrationstests des Moduls (Container + Capsule von
 * Hand, ECHTE Migrationen per glob, auth() als Attrappe, feste Uhr) — Kopf aus
 * InterviewPostingTeamScopeTest kopiert.
 */
class BackfillApplicantPositionTest extends TestCase
{
    private const TEAM = 8;
    private const FREMDES_TEAM = 9;

    private const POSITION_ESSEN = 81;
    private const POSITION_KOELN = 82;
    private const POSITION_FREMD = 91;

    private const POSTING_ESSEN = 810;
    private const POSTING_ALTFALL = 814;
    private const POSTING_ECHTER_MATCH = 815;
    private const POSTING_FREMD = 910;

    private const PHASE_EINGANG = 101;

    /** Normalfall: Pivot auf 810 (Stelle 81), Feld leer. */
    private const APPLICANT_LEER = 1010;

    /** Altfall: Transition-Log position_switch, Feld bereits auf 82 festgelegt. */
    private const APPLICANT_ALTFALL = 1014;

    /** Gegenprobe: Transition-Log position_switch, ABER echter Match-Wert im Pivot. */
    private const APPLICANT_ECHTER_MATCH = 1015;

    /** Fremdes Team (9): leeres Feld, eigene Anzeige — belegt die --team-id-Grenze. */
    private const APPLICANT_FREMD = 1020;

    /** Kein Pivot-Eintrag ueberhaupt — der ohneAnzeige-Zweig. */
    private const APPLICANT_OHNE_ANZEIGE = 1030;

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
        Model::clearBootedModels();
        Carbon::setTestNow();
    }

    /**
     * Der Bestand wird geteilt (setUpBeforeClass) — fast jeder Test schreibt
     * rec_position_id und/oder matched_via um. Ohne diesen Reset waere das
     * Ergebnis von der Ausfuehrungsreihenfolge abhaengig (Muster aus
     * WaitlistUnchangedBySwitchTest::setzeBestandAufAusgangszustand()).
     */
    protected function setUp(): void
    {
        parent::setUp();

        self::setzeBestandAufAusgangszustand();
    }

    public function test_der_backfill_setzt_die_stelle_aus_der_fruehesten_anzeige(): void
    {
        // 1010 hat Pivot auf 810 (Stelle 81) und ein leeres Feld.
        $this->runBackfill();

        $this->assertSame(81, (int) RecApplicant::find(self::APPLICANT_LEER)->rec_position_id);
    }

    public function test_der_backfill_ueberschreibt_nichts(): void
    {
        Capsule::table('rec_applicants')->where('id', self::APPLICANT_LEER)->update(['rec_position_id' => 82]);

        $this->runBackfill();

        $this->assertSame(82, (int) RecApplicant::find(self::APPLICANT_LEER)->rec_position_id,
            'ein gepflegter Wert bleibt — der Backfill fuellt nur Luecken');
    }

    public function test_dry_run_schreibt_nicht(): void
    {
        $this->runBackfill(dryRun: true);

        $this->assertNull(RecApplicant::find(self::APPLICANT_LEER)->rec_position_id);
    }

    public function test_altfaelle_werden_als_wechsel_markiert(): void
    {
        // Bewerber 1014: seine Verknuepfung entstand durch einen Stellenwechsel
        // (erkennbar am Transition-Log mit trigger position_switch). Die
        // urspruengliche Anzeige ist geloescht und nicht rekonstruierbar — die
        // vorhandene wird markiert, damit die Statistik sie nicht als Bewerbung
        // dieser Anzeige zaehlt.
        $this->runBackfill();

        $pivot = Capsule::table('rec_applicant_posting')
            ->where('rec_applicant_id', self::APPLICANT_ALTFALL)->first();

        $this->assertSame('position_switch', $pivot->matched_via);
        $this->assertSame(82, (int) RecApplicant::find(self::APPLICANT_ALTFALL)->rec_position_id,
            'die Stelle bleibt die, auf die er sich festgelegt hat');
    }

    public function test_eine_echte_matched_via_wird_nicht_ueberschrieben(): void
    {
        // 1015 hat ebenfalls einen position_switch-Log-Eintrag, aber sein Pivot
        // traegt bereits eine ECHTE Match-Information aus dem Inbound-Matching.
        // whereNull('matched_via') beim Markieren ist Pflicht — sonst wuerde der
        // Backfill diese Information stillschweigend zerstoeren.
        $this->runBackfill();

        $pivot = Capsule::table('rec_applicant_posting')
            ->where('rec_applicant_id', self::APPLICANT_ECHTER_MATCH)->first();

        $this->assertSame('inbound', $pivot->matched_via,
            'eine echte Match-Information darf der Backfill nicht ueberschreiben');
    }

    public function test_der_zaehler_meldet_nur_geschriebene_zeilen(): void
    {
        // Review-Befund: zwei Kandidaten stehen im Transition-Log (1014
        // ungeschuetzt, 1015 durch matched_via='inbound' geschuetzt) — der
        // Zaehler darf NUR den einen tatsaechlich geschriebenen Fall melden,
        // nicht die Kandidatenzahl aus dem Log. Vor dem Fix meldete das
        // Kommando hier 2 (siehe Fix-Bericht fuer die Gegenprobe).
        $report = $this->runBackfill();

        $this->assertSame(1, $report['markiert'],
            'ein durch matched_via geschuetzter Altfall darf nicht mitgezaehlt werden');
    }

    public function test_team_id_grenzt_die_bearbeitung_ein(): void
    {
        // Nur TEAM (8) wird bearbeitet — 1020 im FREMDEN Team (9) hat ebenso
        // ein leeres Feld und eine eigene Anzeige, bleibt aber unberuehrt.
        $this->runBackfill(teamId: (string) self::TEAM);

        $this->assertSame(81, (int) RecApplicant::find(self::APPLICANT_LEER)->rec_position_id,
            'das eigene Team wird bearbeitet');
        $this->assertNull(RecApplicant::find(self::APPLICANT_FREMD)->rec_position_id,
            'ein anderes Team bleibt von --team-id unberuehrt');

        // GEGENPROBE im selben Testlauf: ohne Einschraenkung wird auch das
        // fremde Team erfasst — sonst bewiese der Test oben nur, dass 1020
        // zufaellig nie erfasst wird, nicht dass die Option eine Grenze zieht.
        $this->runBackfill();

        $this->assertSame(self::POSITION_FREMD, (int) RecApplicant::find(self::APPLICANT_FREMD)->rec_position_id,
            'ohne --team-id wird auch das fremde Team bearbeitet');
    }

    public function test_ohne_anzeige_bleibt_das_feld_leer(): void
    {
        // 1030 hat KEINE Pivot-Verknuepfung — die tragende Regel des Features:
        // "bleibt leer, kein Raten". Der ohneAnzeige-Zweig ist trivial im Code,
        // war bisher aber unbelegt.
        $report = $this->runBackfill();

        $this->assertNull(RecApplicant::find(self::APPLICANT_OHNE_ANZEIGE)->rec_position_id);
        $this->assertGreaterThanOrEqual(1, $report['ohneAnzeige']);
    }

    public function test_ein_zweiter_lauf_aendert_nichts(): void
    {
        // Idempotenz war bisher nur behauptet (Docblock), nicht geprueft.
        $this->runBackfill();

        $nachErstemLauf = (int) RecApplicant::find(self::APPLICANT_LEER)->rec_position_id;
        $matchedViaNachErstemLauf = Capsule::table('rec_applicant_posting')
            ->where('rec_applicant_id', self::APPLICANT_ALTFALL)->value('matched_via');

        $zweiterLauf = $this->runBackfill();

        $this->assertSame($nachErstemLauf, (int) RecApplicant::find(self::APPLICANT_LEER)->rec_position_id,
            'ein zweiter Lauf darf ein bereits gefuelltes Feld nicht anfassen');
        $this->assertSame($matchedViaNachErstemLauf, Capsule::table('rec_applicant_posting')
            ->where('rec_applicant_id', self::APPLICANT_ALTFALL)->value('matched_via'),
            'ein zweiter Lauf darf eine bereits gesetzte Markierung nicht anfassen');

        $this->assertSame(0, $zweiterLauf['gesetzt'], 'zweiter Lauf: nichts mehr zu setzen');
        $this->assertSame(0, $zweiterLauf['markiert'], 'zweiter Lauf: nichts mehr zu markieren');
    }

    // -----------------------------------------------------------------
    // Werkzeug
    // -----------------------------------------------------------------

    private function runBackfill(bool $dryRun = false, ?string $teamId = null): array
    {
        return (new BackfillApplicantPositionProbe())->probeBackfill($dryRun, $teamId);
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

    /**
     * Setzt genau die Spalten und Zeilen zurueck, die die Tests dieser Klasse
     * anfassen — der Bestand wird geteilt (setUpBeforeClass).
     *
     *  - rec_applicants.rec_position_id (1010/1020/1030 leer, 1014/1015 auf
     *    ihrer festgelegten Stelle)
     *  - rec_applicant_posting.matched_via (1014 leer, 1015 'inbound')
     */
    private static function setzeBestandAufAusgangszustand(): void
    {
        Capsule::table('rec_applicants')
            ->whereIn('id', [self::APPLICANT_LEER, self::APPLICANT_FREMD, self::APPLICANT_OHNE_ANZEIGE])
            ->update(['rec_position_id' => null]);
        Capsule::table('rec_applicants')->whereIn('id', [self::APPLICANT_ALTFALL, self::APPLICANT_ECHTER_MATCH])
            ->update(['rec_position_id' => self::POSITION_KOELN]);

        Capsule::table('rec_applicant_posting')->where('rec_applicant_id', self::APPLICANT_ALTFALL)
            ->update(['matched_via' => null]);
        Capsule::table('rec_applicant_posting')->where('rec_applicant_id', self::APPLICANT_ECHTER_MATCH)
            ->update(['matched_via' => 'inbound']);
    }

    private static function seed(): void
    {
        $now = self::HEUTE;

        Capsule::table('rec_positions')->insert([
            ['id' => self::POSITION_ESSEN, 'uuid' => 'bap-pos-81', 'team_id' => self::TEAM,
             'title' => 'Essen', 'location' => 'Essen', 'is_active' => 1,
             'created_at' => $now, 'updated_at' => $now],
            ['id' => self::POSITION_KOELN, 'uuid' => 'bap-pos-82', 'team_id' => self::TEAM,
             'title' => 'Koeln', 'location' => 'Koeln', 'is_active' => 1,
             'created_at' => $now, 'updated_at' => $now],
            ['id' => self::POSITION_FREMD, 'uuid' => 'bap-pos-91', 'team_id' => self::FREMDES_TEAM,
             'title' => 'Fremdes Team', 'location' => 'Woanders', 'is_active' => 1,
             'created_at' => $now, 'updated_at' => $now],
        ]);

        Capsule::table('rec_phases')->insert([
            ['id' => self::PHASE_EINGANG, 'uuid' => 'bap-ph-101', 'team_id' => self::TEAM,
             'rec_position_id' => self::POSITION_ESSEN, 'name' => 'Eingang', 'order' => 1,
             'is_active' => 1, 'created_at' => $now, 'updated_at' => $now],
        ]);

        Capsule::table('rec_postings')->insert([
            ['id' => self::POSTING_ESSEN, 'uuid' => 'bap-pstg-810', 'rec_position_id' => self::POSITION_ESSEN,
             'team_id' => self::TEAM, 'title' => 'Essen Anzeige', 'status' => 'published',
             'is_active' => 1, 'created_at' => $now, 'updated_at' => $now],
            // Verwaiste Verknuepfungen der beiden Altfaelle — die Stelle darauf
            // ist irrelevant (die Marker-Logik liest nur das Transition-Log, nie
            // die Anzeige selbst), aber die FK braucht eine existierende Zeile.
            ['id' => self::POSTING_ALTFALL, 'uuid' => 'bap-pstg-814', 'rec_position_id' => self::POSITION_KOELN,
             'team_id' => self::TEAM, 'title' => 'Verwaiste Anzeige (Altfall)', 'status' => 'published',
             'is_active' => 1, 'created_at' => $now, 'updated_at' => $now],
            ['id' => self::POSTING_ECHTER_MATCH, 'uuid' => 'bap-pstg-815', 'rec_position_id' => self::POSITION_KOELN,
             'team_id' => self::TEAM, 'title' => 'Verwaiste Anzeige (echter Match)', 'status' => 'published',
             'is_active' => 1, 'created_at' => $now, 'updated_at' => $now],
            ['id' => self::POSTING_FREMD, 'uuid' => 'bap-pstg-910', 'rec_position_id' => self::POSITION_FREMD,
             'team_id' => self::FREMDES_TEAM, 'title' => 'Fremde Anzeige', 'status' => 'published',
             'is_active' => 1, 'created_at' => $now, 'updated_at' => $now],
        ]);

        Capsule::table('rec_applicants')->insert([
            ['id' => self::APPLICANT_LEER, 'uuid' => 'bap-app-1010', 'team_id' => self::TEAM,
             'applied_at' => '2026-07-01', 'rec_phase_id' => self::PHASE_EINGANG, 'rec_position_id' => null,
             'is_active' => 1, 'is_test' => 0, 'created_at' => $now, 'updated_at' => $now],
            ['id' => self::APPLICANT_ALTFALL, 'uuid' => 'bap-app-1014', 'team_id' => self::TEAM,
             'applied_at' => '2026-07-02', 'rec_phase_id' => self::PHASE_EINGANG, 'rec_position_id' => self::POSITION_KOELN,
             'is_active' => 1, 'is_test' => 0, 'created_at' => $now, 'updated_at' => $now],
            ['id' => self::APPLICANT_ECHTER_MATCH, 'uuid' => 'bap-app-1015', 'team_id' => self::TEAM,
             'applied_at' => '2026-07-03', 'rec_phase_id' => self::PHASE_EINGANG, 'rec_position_id' => self::POSITION_KOELN,
             'is_active' => 1, 'is_test' => 0, 'created_at' => $now, 'updated_at' => $now],
            // Fremdes Team: kein rec_phase_id noetig (die Spalte ist nullable,
            // das Kommando liest sie nicht).
            ['id' => self::APPLICANT_FREMD, 'uuid' => 'bap-app-1020', 'team_id' => self::FREMDES_TEAM,
             'applied_at' => '2026-07-04', 'rec_phase_id' => null, 'rec_position_id' => null,
             'is_active' => 1, 'is_test' => 0, 'created_at' => $now, 'updated_at' => $now],
            // Keine Anzeige verknuepft (kein Pivot-Eintrag weiter unten).
            ['id' => self::APPLICANT_OHNE_ANZEIGE, 'uuid' => 'bap-app-1030', 'team_id' => self::TEAM,
             'applied_at' => '2026-07-05', 'rec_phase_id' => self::PHASE_EINGANG, 'rec_position_id' => null,
             'is_active' => 1, 'is_test' => 0, 'created_at' => $now, 'updated_at' => $now],
        ]);

        Capsule::table('rec_applicant_posting')->insert([
            ['rec_applicant_id' => self::APPLICANT_LEER, 'rec_posting_id' => self::POSTING_ESSEN,
             'applied_at' => '2026-07-01', 'created_at' => $now, 'updated_at' => $now],
            ['rec_applicant_id' => self::APPLICANT_ALTFALL, 'rec_posting_id' => self::POSTING_ALTFALL,
             'applied_at' => '2026-07-02', 'created_at' => $now, 'updated_at' => $now],
            ['rec_applicant_id' => self::APPLICANT_ECHTER_MATCH, 'rec_posting_id' => self::POSTING_ECHTER_MATCH,
             'applied_at' => '2026-07-03', 'created_at' => $now, 'updated_at' => $now],
            ['rec_applicant_id' => self::APPLICANT_FREMD, 'rec_posting_id' => self::POSTING_FREMD,
             'applied_at' => '2026-07-04', 'created_at' => $now, 'updated_at' => $now],
        ]);

        // Transition-Log: beide Altfaelle wechselten vor dem Umbau die Stelle.
        Capsule::table('rec_phase_transitions')->insert([
            ['team_id' => self::TEAM, 'rec_applicant_id' => self::APPLICANT_ALTFALL,
             'rec_position_id' => self::POSITION_KOELN, 'from_phase_id' => null, 'to_phase_id' => null,
             'from_phase_name' => null, 'to_phase_name' => null,
             'trigger' => PhaseTransitionTrigger::POSITION_SWITCH, 'source' => 'live',
             'occurred_at' => '2026-06-01 09:00:00', 'created_at' => $now, 'updated_at' => $now],
            ['team_id' => self::TEAM, 'rec_applicant_id' => self::APPLICANT_ECHTER_MATCH,
             'rec_position_id' => self::POSITION_KOELN, 'from_phase_id' => null, 'to_phase_id' => null,
             'from_phase_name' => null, 'to_phase_name' => null,
             'trigger' => PhaseTransitionTrigger::POSITION_SWITCH, 'source' => 'live',
             'occurred_at' => '2026-06-02 09:00:00', 'created_at' => $now, 'updated_at' => $now],
        ]);

        self::setzeBestandAufAusgangszustand();
    }
}

/**
 * Reicht BackfillApplicantPosition::backfill() heraus — die reine
 * Abgleichs-Logik ohne Konsolen-I/O. Muster aus ReconcileApplicantPositionsProbe
 * (ReconcileApplicantPositionsGateTest).
 */
final class BackfillApplicantPositionProbe extends BackfillApplicantPosition
{
    /** @return array{gesetzt:int, ohneAnzeige:int, markiert:int} */
    public function probeBackfill(bool $dryRun, ?string $teamId): array
    {
        return $this->backfill($dryRun, $teamId);
    }
}
