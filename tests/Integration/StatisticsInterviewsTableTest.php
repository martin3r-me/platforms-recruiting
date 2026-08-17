<?php

namespace Platform\Recruiting\Tests\Integration;

use Illuminate\Container\Container;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Livewire\Statistics\Index;
use Platform\Recruiting\Services\Statistics\CohortViewModel;

/**
 * ANSCHLUSS-NACHWEIS fuer Tabelle 2 (Schulungstermine mit Herkunft): laufen
 * Termin-Query und Kohorten-Zeilen zu EINER Tabellenzeile zusammen, ohne dass
 * dabei zwei Zaehlungen derselben Menschen entstehen?
 *
 * Warum das nicht als Unit-Test geht: die Zeile eines Termins mischt zwei
 * Quellen, und genau diese Trennung ist der Punkt —
 *  - die BELEGUNG (IST/SOLL) kommt aus der Termin-Query (Plaetze sind eine
 *    Eigenschaft des Termins),
 *  - die TRICHTER-Zahlen kommen aus den Assigner-Zeilen (dieselben Zeilen wie
 *    Tabelle 1, keine zweite Query).
 * Ob die Zusammenfuehrung stimmt, zeigt sich nur, wenn Query und Assigner
 * gemeinsam laufen.
 *
 * Aufbau wie StatisticsCohortWiringTest (Container + Capsule von Hand, ECHTE
 * Migrationen per glob, auth() als Attrappe, feste Uhr). Datensaetze per Query
 * Builder mit expliziter UUID, weil der Event-Dispatcher abgeschaltet ist.
 */
class StatisticsInterviewsTableTest extends TestCase
{
    private const TEAM = 4;

    /** Ausschreibungen der Filiale Essen (Stelle 1). */
    private const POSTING_SERVICE = 20;
    private const POSTING_BANKETT = 22;

    /** Ausschreibung der Filiale Wuppertal (Stelle 2). */
    private const POSTING_KUECHE = 21;

    /** Termin in Essen — Veranstaltungsort ist bewusst NICHT „Essen". */
    private const INTERVIEW_AUGUST = 300;

    /** Zweiter Termin in Essen, im Juli (Zeitraum-Filter). */
    private const INTERVIEW_JULI = 301;

    /** Termin der Filiale Wuppertal. */
    private const INTERVIEW_WUPPERTAL = 302;

    /** Inaktiver Termin (HR hat ihn stillgelegt) — nie in der Tabelle. */
    private const INTERVIEW_INAKTIV = 303;

    private const HEUTE = '2026-08-17 10:00:00';

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
                // nicht benutzt: die Komponente ruft nur auth()->user()
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

    private function component(?string $ort = null, ?string $from = null, ?string $to = null): Index
    {
        $component = new Index();
        $component->ortFilter = $ort;
        $component->interviewFrom = $from;
        $component->interviewTo = $to;

        return $component;
    }

    /** @return list<int> */
    private function interviewIds(Index $component): array
    {
        return array_map('intval', $component->interviews()->pluck('id')->all());
    }

    public function test_termine_folgen_dem_ort_der_stelle_nicht_dem_veranstaltungsort(): void
    {
        // Tabelle 1 zeigt EINE Filiale — Tabelle 2 muss dieselbe zeigen, sonst
        // steht genau das Nebeneinander auf der Seite, das der Kunde reklamiert
        // hat. Eingegrenzt wird ueber die STELLE des Termins.
        $this->assertSame(
            [self::INTERVIEW_AUGUST, self::INTERVIEW_JULI],
            $this->interviewIds($this->component('Essen')),
            'nur Termine an Stellen der Filiale Essen, neueste zuerst',
        );

        // rec_interviews.location ist freier Text und der VERANSTALTUNGSORT: der
        // August-Termin findet am „Bahnhof Duisburg" statt und gehoert trotzdem
        // zur Filiale Essen. Ein Filter auf diese Spalte haette ihn verschluckt.
        $august = $this->component('Essen')->interviews()->firstWhere('id', self::INTERVIEW_AUGUST);
        $this->assertSame('Bahnhof Duisburg, Gleis 3', $august->location);

        // Ohne Ort-Filter keine Einschraenkung (Task 10 macht den Ort zur Pflicht)
        $this->assertSame(
            [self::INTERVIEW_WUPPERTAL, self::INTERVIEW_AUGUST, self::INTERVIEW_JULI],
            $this->interviewIds($this->component()),
            'ohne Ort alle Filialen, weiter chronologisch absteigend',
        );
    }

    public function test_inaktiver_termin_taucht_nicht_auf(): void
    {
        // Termine haben kein is_test-Flag; inaktiv ist der einzige Weg, mit dem HR
        // einen Test-Termin aus der Statistik nimmt.
        $this->assertNotContains(self::INTERVIEW_INAKTIV, $this->interviewIds($this->component()));
    }

    public function test_geleertes_datumsfeld_schneidet_nichts_weg(): void
    {
        // Die Leerstring-Falle: ein geleertes x-ui-input-date liefert '', und
        // '2026-07-05' >= '' ist WAHR — ohne Normalisierung auf null waere die
        // Tabelle nach dem Zuruecksetzen des Filters leer bzw. willkuerlich
        // beschnitten. Der updated-Hook ist die einzige Stelle, die das faengt.
        $alle = $this->interviewIds($this->component('Essen'));

        $component = $this->component('Essen');
        $component->updatedInterviewFrom('');
        $component->updatedInterviewTo('');

        $this->assertNull($component->interviewFrom);
        $this->assertNull($component->interviewTo);
        $this->assertSame($alle, $this->interviewIds($component), 'geleerte Felder schneiden nichts weg');

        // Zum Gegenbeweis, dass der Filter ueberhaupt greift: gesetzte Werte
        // schneiden, und der Bis-Tag ist INKLUSIV (23:59:59 am Termin-Tag)
        $component->updatedInterviewFrom('2026-08-01');
        $this->assertSame([self::INTERVIEW_AUGUST], $this->interviewIds($component));

        $bis = $this->component('Essen');
        $bis->updatedInterviewTo('2026-07-05');
        $this->assertSame([self::INTERVIEW_JULI], $this->interviewIds($bis),
            'der Juli-Termin um 09:00 liegt im Bis-Tag');
    }

    public function test_belegung_kommt_vom_termin_der_trichter_aus_den_assigner_zeilen(): void
    {
        $component = $this->component('Essen');
        $table = $component->buildInterviewTable($component->interviews(), $component->cohort()['rows']);

        $august = $this->rowOf($table, self::INTERVIEW_AUGUST);

        // Belegung: Eigenschaft des TERMINS (zentrale Zaehlregel seatTaking),
        // unabhaengig von der Kohorte. Drei Buchungen, davon eine mit
        // freigegebenem Platz (Standby) -> zwei belegte Plaetze von acht.
        $this->assertSame(2, $august['seat_taking'], 'IST aus der Termin-Query');
        $this->assertSame(8, $august['max'], 'SOLL aus max_participants');

        // Trichter: aus den Assigner-Zeilen, also derselben Quelle wie Tabelle 1
        $this->assertSame(3, $component->countIn($august['rows'], 'ids'), 'drei Teilnehmer in der Kohorte');
        $this->assertSame(1, $component->countIn($august['rows'], 'standby'));

        // Anzeige-Spalten des Termins
        $this->assertSame('Schulung', $august['type']);
        $this->assertSame('Bahnhof Duisburg, Gleis 3', $august['location']);
        $this->assertSame('Kellner (m/w/d)', $august['posting_title'], 'Ausschreibung des Termins');
        $this->assertTrue($august['has_posting']);

        // Termin OHNE Ausschreibung: Rueckfall auf den Titel des Termins
        $juli = $this->rowOf($table, self::INTERVIEW_JULI);
        $this->assertSame('Nachschulung Juli', $juli['posting_title']);
        $this->assertFalse($juli['has_posting']);
    }

    public function test_herkunft_summiert_sich_zur_zeile_des_termins(): void
    {
        // Dieselbe Zusicherung wie im Unit-Test, aber am ECHTEN Bestand: die
        // Unterzeilen sind die Assigner-Zeilen des Termins, nach Ausschreibung
        // gruppiert. Zwei Ausschreibungen, drei Teilnehmer, keine Doppelzaehlung.
        $component = $this->component('Essen');
        $table = $component->buildInterviewTable($component->interviews(), $component->cohort()['rows']);
        $august = $this->rowOf($table, self::INTERVIEW_AUGUST);

        $this->assertSame(
            [self::POSTING_BANKETT, self::POSTING_SERVICE],
            array_map(fn ($o) => $o['posting_id'], $august['origins']),
            'eine Unterzeile pro Ausschreibung der Teilnehmer, alphabetisch nach Titel',
        );

        foreach (['ids', 'gebucht', 'standby', 'unterschrieben'] as $column) {
            $herkunft = 0;
            foreach ($august['origins'] as $origin) {
                $herkunft += $component->countIn($origin['rows'], $column);
            }
            $this->assertSame(
                $component->countIn($august['rows'], $column),
                $herkunft,
                "Spalte {$column}: Summe der Herkunft = Zeile des Termins",
            );
        }

        // ... und das Drill-down-Token der Unterzeile trifft genau ihre Personen.
        // Geprueft wird der ganze Weg, den ein Klick nimmt: Token bauen (Komponente)
        // → dekodieren → gegen die FRISCHEN Zeilen aufloesen (ViewModel). Nur
        // drill() selbst bleibt aussen vor, weil es die Computed Property $cohort
        // liest, die es ohne Livewire-Lifecycle nicht gibt.
        $bankett = $august['origins'][0];
        $this->assertSame(self::POSTING_BANKETT, $bankett['posting_id']);
        $this->assertSame(1, $component->countIn($bankett['rows'], 'ids'));

        $vm = new CohortViewModel();
        $token = $component->drillToken('interview_posting', 'Aushilfe Bankett', [
            'interview' => self::INTERVIEW_AUGUST, 'posting' => self::POSTING_BANKETT,
        ]);
        $this->assertSame(
            [204],
            $vm->resolveIdsFromClient($component->cohort()['rows'], $vm->decodeScope($token), 'ids'),
        );

        // Der Termin selbst loest die Vereinigung auf — dieselbe Menge, die die
        // Zeile anzeigt
        $terminToken = $component->drillToken('interview', 'Schulung August', [
            'interview' => self::INTERVIEW_AUGUST,
        ]);
        $this->assertSame(
            [201, 203, 204],
            $vm->resolveIdsFromClient($component->cohort()['rows'], $vm->decodeScope($terminToken), 'ids'),
        );
    }

    public function test_teilnehmer_ausserhalb_der_termin_auswahl_werden_benannt(): void
    {
        // Der inaktive Termin hat einen Teilnehmer. Er steckt in Tabelle 1 (die
        // kennt keinen Termin-Zeitraum und kein is_active), taucht in Tabelle 2
        // aber nicht auf. Diese Differenz wird BENANNT statt verschluckt —
        // eine Zahl, die ohne Erklaerung kleiner ist als daneben, ist genau die
        // Sorte Zahl, die der Kunde nicht nachvollziehen konnte.
        $component = $this->component('Essen');
        $table = $component->buildInterviewTable($component->interviews(), $component->cohort()['rows']);

        $this->assertSame(1, $table['outside']['interviews']);
        $this->assertSame(1, $table['outside']['applications']);

        // Ohne ausgeschlossene Termine bleibt die Fussnote weg
        $ohneFilter = $this->component('Wuppertal');
        $wuppertal = $ohneFilter->buildInterviewTable($ohneFilter->interviews(), $ohneFilter->cohort()['rows']);
        $this->assertSame(0, $wuppertal['outside']['interviews']);
        $this->assertSame(0, $wuppertal['outside']['applications']);
    }

    public function test_termin_ohne_kohorten_teilnehmer_bleibt_eine_zeile(): void
    {
        // Ein Termin ohne Kohorten-Teilnehmer verschwindet NICHT: seine Belegung
        // ist eine Aussage („fuenf Plaetze, einer belegt, keiner davon in dieser
        // Auswahl"). Hier ist es ein Testbewerber — er belegt einen Platz am
        // Termin und steckt in keiner Kohorte. Die beiden Zahlen duerfen deshalb
        // auseinandergehen, und niemand darf sie gegeneinander rechnen.
        $component = $this->component('Wuppertal');
        $table = $component->buildInterviewTable($component->interviews(), $component->cohort()['rows']);
        $row = $this->rowOf($table, self::INTERVIEW_WUPPERTAL);

        $this->assertSame([], $row['rows']);
        $this->assertSame([], $row['origins']);
        $this->assertSame(1, $row['seat_taking'], 'die Buchung des Termins zaehlt trotzdem');
        $this->assertSame(5, $row['max']);
        $this->assertSame(0, $component->countIn($row['rows'], 'ids'), 'Testbewerber sind in keiner Kohorte');
    }

    public function test_termin_query_kostet_drei_queries(): void
    {
        // Query-Budget ist Abnahmekriterium §2: eine Query fuer die Termine
        // (Belegung als Subselect, Ort als Sub-Query) plus zwei Eager Loads
        // (Terminart, Ausschreibung). Kein N+1 ueber die Termine.
        $connection = Capsule::connection();
        $connection->flushQueryLog();
        $connection->enableQueryLog();

        $this->component('Essen')->interviews();

        $queries = $connection->getQueryLog();
        $connection->disableQueryLog();
        $connection->flushQueryLog();

        $this->assertCount(3, $queries, 'Termine + Terminart + Ausschreibung: ' . json_encode(
            array_map(fn ($q) => $q['query'], $queries),
        ));
    }

    /** Genau eine Tabellenzeile zu einem Termin holen. */
    private function rowOf(array $table, int $interviewId): array
    {
        $hits = array_values(array_filter($table['rows'], fn ($row) => $row['interview_id'] === $interviewId));
        $this->assertCount(1, $hits, "genau eine Zeile erwartet fuer Termin {$interviewId}");

        return $hits[0];
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
            ['id' => 1, 'uuid' => 'ivpos-1', 'team_id' => self::TEAM, 'title' => 'Kellner',
             'location' => 'Essen', 'is_active' => 1, 'created_at' => $now, 'updated_at' => $now],
            ['id' => 2, 'uuid' => 'ivpos-2', 'team_id' => self::TEAM, 'title' => 'Küche',
             'location' => 'Wuppertal', 'is_active' => 1, 'created_at' => $now, 'updated_at' => $now],
        ]);

        Capsule::table('rec_phases')->insert([
            ['id' => 1, 'uuid' => 'ivph-1', 'team_id' => self::TEAM, 'rec_position_id' => 1,
             'name' => 'Eingang', 'order' => 1, 'is_active' => 1, 'created_at' => $now, 'updated_at' => $now],
            ['id' => 2, 'uuid' => 'ivph-2', 'team_id' => self::TEAM, 'rec_position_id' => 2,
             'name' => 'Eingang', 'order' => 1, 'is_active' => 1, 'created_at' => $now, 'updated_at' => $now],
        ]);

        Capsule::table('rec_postings')->insert([
            ['id' => self::POSTING_SERVICE, 'uuid' => 'ivpost-20', 'team_id' => self::TEAM, 'rec_position_id' => 1,
             'title' => 'Kellner (m/w/d)', 'activity' => 'Service', 'status' => 'published', 'is_active' => 1,
             'bedarf' => 10, 'bewerbungs_faktor' => 8.0, 'created_at' => $now, 'updated_at' => $now],
            ['id' => self::POSTING_KUECHE, 'uuid' => 'ivpost-21', 'team_id' => self::TEAM, 'rec_position_id' => 2,
             'title' => 'Küchenhilfe', 'activity' => 'Küche', 'status' => 'published', 'is_active' => 1,
             'bedarf' => null, 'bewerbungs_faktor' => null, 'created_at' => $now, 'updated_at' => $now],
            // Titel bewusst alphabetisch VOR „Kellner", damit die Sortierung der
            // Herkunfts-Unterzeilen (nach Titel) gepruefbar ist
            ['id' => self::POSTING_BANKETT, 'uuid' => 'ivpost-22', 'team_id' => self::TEAM, 'rec_position_id' => 1,
             'title' => 'Aushilfe Bankett', 'activity' => 'Bankett', 'status' => 'published', 'is_active' => 1,
             'bedarf' => null, 'bewerbungs_faktor' => null, 'created_at' => $now, 'updated_at' => $now],
        ]);

        Capsule::table('rec_interview_types')->insert([
            ['id' => 1, 'uuid' => 'ivtype-1', 'team_id' => self::TEAM, 'name' => 'Schulung',
             'is_active' => 1, 'created_at' => $now, 'updated_at' => $now],
        ]);

        Capsule::table('rec_interviews')->insert([
            // Filiale Essen (Stelle 1), Veranstaltungsort bewusst anders
            ['id' => self::INTERVIEW_AUGUST, 'uuid' => 'iv-300', 'team_id' => self::TEAM,
             'interview_type_id' => 1, 'rec_position_id' => 1, 'rec_posting_id' => self::POSTING_SERVICE,
             'title' => 'Schulung August', 'location' => 'Bahnhof Duisburg, Gleis 3',
             'starts_at' => '2026-08-10 10:00:00', 'max_participants' => 8,
             'is_active' => 1, 'created_at' => $now, 'updated_at' => $now],
            // Essen, aber OHNE Ausschreibung am Termin -> Rueckfall auf den Titel
            ['id' => self::INTERVIEW_JULI, 'uuid' => 'iv-301', 'team_id' => self::TEAM,
             'interview_type_id' => 1, 'rec_position_id' => 1, 'rec_posting_id' => null,
             'title' => 'Nachschulung Juli', 'location' => 'Essen, Zentrale',
             'starts_at' => '2026-07-05 09:00:00', 'max_participants' => 4,
             'is_active' => 1, 'created_at' => $now, 'updated_at' => $now],
            ['id' => self::INTERVIEW_WUPPERTAL, 'uuid' => 'iv-302', 'team_id' => self::TEAM,
             'interview_type_id' => 1, 'rec_position_id' => 2, 'rec_posting_id' => self::POSTING_KUECHE,
             'title' => 'Schulung Wuppertal', 'location' => 'Wuppertal, Werkstatt',
             'starts_at' => '2026-08-12 10:00:00', 'max_participants' => 5,
             'is_active' => 1, 'created_at' => $now, 'updated_at' => $now],
            // stillgelegt: hat einen Teilnehmer, gehoert aber nicht in die Tabelle
            ['id' => self::INTERVIEW_INAKTIV, 'uuid' => 'iv-303', 'team_id' => self::TEAM,
             'interview_type_id' => 1, 'rec_position_id' => 1, 'rec_posting_id' => self::POSTING_SERVICE,
             'title' => 'Testtermin', 'location' => 'Essen, Zentrale',
             'starts_at' => '2026-08-14 10:00:00', 'max_participants' => 6,
             'is_active' => 0, 'created_at' => $now, 'updated_at' => $now],
        ]);

        Capsule::table('rec_applicants')->insert([
            ['id' => 201, 'uuid' => 'ivapp-201', 'team_id' => self::TEAM, 'applied_at' => '2026-07-20',
             'rec_phase_id' => 1, 'is_test' => 0, 'created_at' => $now, 'updated_at' => $now],
            ['id' => 202, 'uuid' => 'ivapp-202', 'team_id' => self::TEAM, 'applied_at' => '2026-07-21',
             'rec_phase_id' => 1, 'is_test' => 0, 'created_at' => $now, 'updated_at' => $now],
            ['id' => 203, 'uuid' => 'ivapp-203', 'team_id' => self::TEAM, 'applied_at' => '2026-07-22',
             'rec_phase_id' => 1, 'is_test' => 0, 'created_at' => $now, 'updated_at' => $now],
            ['id' => 204, 'uuid' => 'ivapp-204', 'team_id' => self::TEAM, 'applied_at' => '2026-07-23',
             'rec_phase_id' => 1, 'is_test' => 0, 'created_at' => $now, 'updated_at' => $now],
            ['id' => 205, 'uuid' => 'ivapp-205', 'team_id' => self::TEAM, 'applied_at' => '2026-07-24',
             'rec_phase_id' => 1, 'is_test' => 0, 'created_at' => $now, 'updated_at' => $now],
            // TESTBEWERBER auf dem Wuppertal-Termin: er belegt dort einen Platz
            // (Termin-Query), gehoert aber in keine Kohorte (Stufe 1 der
            // Praezedenz-Kette). Genau der Fall, in dem Belegung und Trichter
            // AUSEINANDERGEHEN — und der zeigt, dass sie zwei Quellen haben.
            ['id' => 206, 'uuid' => 'ivapp-206', 'team_id' => self::TEAM, 'applied_at' => '2026-07-25',
             'rec_phase_id' => 2, 'is_test' => 1, 'created_at' => $now, 'updated_at' => $now],
        ]);

        Capsule::table('rec_applicant_posting')->insert([
            ['rec_applicant_id' => 201, 'rec_posting_id' => self::POSTING_SERVICE, 'created_at' => $now, 'updated_at' => $now],
            ['rec_applicant_id' => 202, 'rec_posting_id' => self::POSTING_SERVICE, 'created_at' => $now, 'updated_at' => $now],
            ['rec_applicant_id' => 203, 'rec_posting_id' => self::POSTING_SERVICE, 'created_at' => $now, 'updated_at' => $now],
            ['rec_applicant_id' => 204, 'rec_posting_id' => self::POSTING_BANKETT, 'created_at' => $now, 'updated_at' => $now],
            ['rec_applicant_id' => 205, 'rec_posting_id' => self::POSTING_SERVICE, 'created_at' => $now, 'updated_at' => $now],
            ['rec_applicant_id' => 206, 'rec_posting_id' => self::POSTING_KUECHE, 'created_at' => $now, 'updated_at' => $now],
        ]);

        Capsule::table('rec_interview_bookings')->insert([
            // August-Termin: zwei Ausschreibungen, drei Teilnehmer, davon einer
            // auf Standby (booked + seat_released_at) -> belegt sind zwei Plaetze
            ['id' => 401, 'uuid' => 'ivb-401', 'team_id' => self::TEAM, 'rec_interview_id' => self::INTERVIEW_AUGUST,
             'rec_applicant_id' => 201, 'status' => 'confirmed', 'seat_released_at' => null, 'is_active' => 1,
             'created_at' => $now, 'updated_at' => $now],
            ['id' => 402, 'uuid' => 'ivb-402', 'team_id' => self::TEAM, 'rec_interview_id' => self::INTERVIEW_AUGUST,
             'rec_applicant_id' => 203, 'status' => 'booked', 'seat_released_at' => '2026-08-01 09:00:00',
             'is_active' => 1, 'created_at' => $now, 'updated_at' => $now],
            ['id' => 403, 'uuid' => 'ivb-403', 'team_id' => self::TEAM, 'rec_interview_id' => self::INTERVIEW_AUGUST,
             'rec_applicant_id' => 204, 'status' => 'attended', 'seat_released_at' => null, 'is_active' => 1,
             'created_at' => $now, 'updated_at' => $now],
            // Juli-Termin
            ['id' => 404, 'uuid' => 'ivb-404', 'team_id' => self::TEAM, 'rec_interview_id' => self::INTERVIEW_JULI,
             'rec_applicant_id' => 202, 'status' => 'attended', 'seat_released_at' => null, 'is_active' => 1,
             'created_at' => $now, 'updated_at' => $now],
            // Teilnehmer des INAKTIVEN Termins -> Grundlage der Fussnote
            ['id' => 405, 'uuid' => 'ivb-405', 'team_id' => self::TEAM, 'rec_interview_id' => self::INTERVIEW_INAKTIV,
             'rec_applicant_id' => 205, 'status' => 'confirmed', 'seat_released_at' => null, 'is_active' => 1,
             'created_at' => $now, 'updated_at' => $now],
            // Wuppertal: Buchung eines TESTBEWERBERS — belegt einen Platz, steckt
            // in keiner Kohorte
            ['id' => 406, 'uuid' => 'ivb-406', 'team_id' => self::TEAM, 'rec_interview_id' => self::INTERVIEW_WUPPERTAL,
             'rec_applicant_id' => 206, 'status' => 'confirmed', 'seat_released_at' => null, 'is_active' => 1,
             'created_at' => $now, 'updated_at' => $now],
        ]);
    }
}
