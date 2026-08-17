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
 * REKONZILIATION AUF SEITEN-EBENE: erfassen Tabellen und Blöcke zusammen JEDE
 * Bewerbung des Teams — genau einmal?
 *
 * Warum diese Zusicherung fehlte und warum sie die wichtigste dieses Umbaus ist:
 * der bestehende Rekonziliations-Hinweis („Σ Zeilen ≠ Gesamtmenge") vergleicht die
 * Auswahl mit SICH SELBST — `total_ids` wird in cohort() nach dem Filtern neu
 * gebildet. Was vor dem Filter herausfiel, kann er per Konstruktion nicht sehen.
 * Gemessen zeigte die Seite bei einer Filiale mit Status „online" eine von sieben
 * Bewerbungen, und nichts auf der Seite sagte, wo die anderen sechs sind.
 *
 * Dieser Test schliesst die Luecke von aussen: er kennt jede Kombination aus
 * Standort und Status und behauptet eine PARTITION —
 *   Σ (alle Filial-Ansichten) + Block „Geschlossene Ausschreibungen"
 *   + Block „Ohne Filial-Zuordnung" = alle Bewerbungen des Teams,
 * paarweise disjunkt. Faellt beim naechsten Filter etwas lautlos heraus, wird
 * dieser Test rot, nicht der Kunde stutzig.
 *
 * Aufbau wie StatisticsCohortWiringTest (Container + Capsule von Hand, ECHTE
 * Migrationen per glob, auth() als Attrappe, feste Uhr).
 */
class StatisticsPageReconciliationTest extends TestCase
{
    private const TEAM = 6;

    /** Fall 1: gewaehlte Filiale, online -> Tabellen. */
    private const APP_ESSEN_ONLINE = 901;

    /** Fall 2: gewaehlte Filiale, geschlossen -> Block „Geschlossene Ausschreibungen". */
    private const APP_ESSEN_ZU = 902;

    /** Fall 3: Stelle OHNE gepflegten Standort, online -> Block „Ohne Filial-Zuordnung". */
    private const APP_OHNE_ORT_ONLINE = 903;

    /** Fall 4: Stelle ohne Standort, geschlossen -> Block „Geschlossene…" (NICHT beide). */
    private const APP_OHNE_ORT_ZU = 904;

    /** Fall 5: keine Ausschreibung am Bewerber -> Block „Ohne Filial-Zuordnung". */
    private const APP_OHNE_AUSSCHREIBUNG = 905;

    /** Fall 6: FREMDE Filiale, online -> sichtbar, sobald man die Filiale wechselt. */
    private const APP_FREMD_ONLINE = 906;

    /** Fall 7: fremde Filiale, geschlossen -> Block „Geschlossene…". */
    private const APP_FREMD_ZU = 907;

    /** Testbewerber: steht in KEINER der Mengen (Stufe 1 der Praezedenz-Kette). */
    private const APP_TEST = 908;

    private const POSTING_ESSEN_ONLINE = 610;
    private const POSTING_ESSEN_ZU = 611;
    private const POSTING_OHNE_ORT_ONLINE = 612;
    private const POSTING_OHNE_ORT_ZU = 613;
    private const POSTING_FREMD_ONLINE = 614;
    private const POSTING_FREMD_ZU = 615;

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

    private function component(?string $ort = null, string $status = 'online'): Index
    {
        $component = new Index();
        $component->ortFilter = $ort;
        $component->postingStatusFilter = $status;

        return $component;
    }

    /**
     * Bewerber-IDs einer Zeilenmenge — genau die Aufloesung, die auch das
     * Drill-down nimmt (kein array_unique, damit eine Doppelzaehlung sichtbar
     * bleibt).
     *
     * @param  list<array>  $rows
     * @return list<int>
     */
    private function idsOf(array $rows): array
    {
        return (new CohortViewModel())->resolveIds($rows, ['scope' => 'all'], 'ids');
    }

    /** @return list<int> */
    private function teamApplicantIds(): array
    {
        return array_map('intval', Capsule::table('rec_applicants')
            ->where('team_id', self::TEAM)
            ->where('is_test', 0)
            ->orderBy('id')
            ->pluck('id')
            ->all());
    }

    public function test_tabellen_und_bloecke_erfassen_jede_bewerbung_genau_einmal(): void
    {
        $orte = $this->component()->ortOptions();
        $this->assertSame(['Essen', 'Wuppertal'], array_keys($orte), 'nur gepflegte Standorte sind waehlbar');

        // (a) JEDE Filial-Ansicht, Status „online" — das, was die Tabellen zeigen
        $inViews = [];
        foreach (array_keys($orte) as $ort) {
            $inViews = array_merge($inViews, $this->idsOf($this->component($ort)->cohort()['rows']));
        }

        // (b) die beiden Bloecke. Sie sind filial-UNABHAENGIG, deshalb genuegt eine
        //     beliebige Ansicht — und genau das wird hier gleich mitgeprueft.
        $cohort = $this->component('Essen')->cohort();
        $inClosed = $this->idsOf($cohort['closed_rows']);
        $inUnreachable = $this->idsOf($cohort['unreachable_rows']);

        $wuppertal = $this->component('Wuppertal')->cohort();
        $this->assertSame($inClosed, $this->idsOf($wuppertal['closed_rows']),
            'der Block der geschlossenen Ausschreibungen haengt nicht an der gewaehlten Filiale');
        $this->assertSame($inUnreachable, $this->idsOf($wuppertal['unreachable_rows']),
            'der Block ohne Filial-Zuordnung haengt nicht an der gewaehlten Filiale');

        // DIE PARTITION
        $alle = array_merge($inViews, $inClosed, $inUnreachable);
        sort($alle);

        $this->assertSame(
            array_values(array_unique($alle)),
            $alle,
            'keine Doppelzaehlung: keine Bewerbung steht in zwei Mengen',
        );
        $this->assertSame(
            $this->teamApplicantIds(),
            $alle,
            'keine Luecke: jede Bewerbung des Teams steckt in einer der Mengen',
        );

        // Der Testbewerber ist in KEINER Menge — der einzige stille Filter (Stufe 1)
        $this->assertNotContains(self::APP_TEST, $alle);
    }

    public function test_jeder_fall_landet_in_genau_einem_block(): void
    {
        // Die Tabelle aus dem Bericht, als Test: welcher Fall wo landet. Ohne diese
        // Zuordnung waere die Partition oben erfuellbar, ohne dass die Bloecke das
        // Richtige zeigen (z. B. wenn alles im selben Block laege).
        $cohort = $this->component('Essen')->cohort();

        $this->assertSame([self::APP_ESSEN_ONLINE], $this->idsOf($cohort['rows']),
            'Fall 1: gewaehlte Filiale + online steht in den Tabellen — und sonst nichts');

        $closed = $this->idsOf($cohort['closed_rows']);
        sort($closed);
        $this->assertSame(
            [self::APP_ESSEN_ZU, self::APP_OHNE_ORT_ZU, self::APP_FREMD_ZU],
            $closed,
            'Faelle 2, 4, 7: alles Geschlossene, unabhaengig von der Filiale',
        );

        $unreachable = $this->idsOf($cohort['unreachable_rows']);
        sort($unreachable);
        $this->assertSame(
            [self::APP_OHNE_ORT_ONLINE, self::APP_OHNE_AUSSCHREIBUNG],
            $unreachable,
            'Faelle 3 und 5: ohne gepflegten Standort bzw. ohne Ausschreibung — und NUR die online',
        );

        // Fall 4 belegt die Nicht-Doppelung: eine geschlossene Ausschreibung ohne
        // Standort steht im Block der Geschlossenen und NICHT zusaetzlich im Block
        // ohne Filial-Zuordnung (Bedingung posting_closed === false).
        $this->assertNotContains(self::APP_OHNE_ORT_ZU, $unreachable);

        // Fall 6 ist ueber die Filial-Auswahl erreichbar — das ist der Unterschied
        // zu den Faellen 3 und 5 und der Grund, warum er in KEINEN Block gehoert.
        $this->assertSame(
            [self::APP_FREMD_ONLINE],
            $this->idsOf($this->component('Wuppertal')->cohort()['rows']),
        );
        $this->assertNotContains(self::APP_FREMD_ONLINE, $closed);
        $this->assertNotContains(self::APP_FREMD_ONLINE, $unreachable);
    }

    public function test_der_gemessene_befund_ist_jetzt_vollstaendig_benannt(): void
    {
        // Der Auslöser: „1 von 7" in der Ansicht, drei im Block der Geschlossenen,
        // drei nirgends. Die drei „nirgends" sind jetzt zwei im neuen Block plus
        // eine Bewerbung, die man mit einem Filialwechsel sieht.
        $cohort = $this->component('Essen')->cohort();
        $component = $this->component('Essen');

        $this->assertSame(7, count($this->teamApplicantIds()), 'sieben echte Bewerbungen im Bestand');
        $this->assertSame(1, $component->countIn($cohort['rows'], 'ids'), 'die Ansicht zeigt eine');
        $this->assertSame(3, $component->countIn($cohort['closed_rows'], 'ids'), 'Block 2 nennt drei');
        $this->assertSame(2, $component->countIn($cohort['unreachable_rows'], 'ids'), 'Block 3 nennt zwei');
        $this->assertSame(
            1,
            $component->countIn($this->component('Wuppertal')->cohort()['rows'], 'ids'),
            'die siebte steht in der anderen Filiale',
        );

        // Und der Rekonziliations-Hinweis (Block 4) schweigt dabei zu Recht: er
        // rechnet innerhalb der Auswahl, und dort stimmt die Summe.
        $this->assertSame(
            $component->countIn($cohort['rows'], 'ids'),
            count($cohort['total_ids']),
        );
    }

    public function test_die_zahlen_der_bloecke_sind_anklickbar(): void
    {
        // Eine Zahl, die man nicht aufloesen kann, ist auf dieser Seite keine Zahl.
        // Beide Bloecke tragen 'set' im Token, weil ihre Zeilen NICHT in der
        // Auswahl stehen — ohne den Schluessel bliebe das Modal leer.
        $component = $this->component('Essen');
        $cohort = $component->cohort();
        $vm = new CohortViewModel();

        $closedToken = $component->drillToken('posting', 'Aushilfe Bankett (Essen, geschlossen)', [
            'posting' => self::POSTING_ESSEN_ZU,
            'set' => 'closed',
        ]);
        $this->assertSame(
            [self::APP_ESSEN_ZU],
            $vm->resolveIdsFromClient($cohort['closed_rows'], $vm->decodeScope($closedToken), 'ids'),
        );

        $unreachableToken = $component->drillToken('posting', 'Springer ohne Standort', [
            'posting' => self::POSTING_OHNE_ORT_ONLINE,
            'set' => 'unreachable',
        ]);
        $this->assertSame(
            [self::APP_OHNE_ORT_ONLINE],
            $vm->resolveIdsFromClient($cohort['unreachable_rows'], $vm->decodeScope($unreachableToken), 'ids'),
        );

        // Die Bewerbung OHNE Ausschreibung ist eine eigene Zeile mit posting = null
        // — sie muss anklickbar bleiben ('posting' vorhanden UND null).
        $ohneToken = $component->drillToken('posting', 'ohne Ausschreibung', [
            'posting' => null,
            'set' => 'unreachable',
        ]);
        $this->assertSame(
            [self::APP_OHNE_AUSSCHREIBUNG],
            $vm->resolveIdsFromClient($cohort['unreachable_rows'], $vm->decodeScope($ohneToken), 'ids'),
        );

        // Gegenprobe: dasselbe Token gegen die AUSWAHL trifft nichts — genau
        // deshalb reist 'set' mit.
        $this->assertSame(
            [],
            $vm->resolveIdsFromClient($cohort['rows'], $vm->decodeScope($unreachableToken), 'ids'),
        );
    }

    public function test_taetigkeits_filter_wirkt_auch_auf_die_bloecke(): void
    {
        // Asymmetrie der beiden Filter, und sie ist begruendet: wer eine TAETIGKEIT
        // waehlt, will keine fremde sehen — wer eine FILIALE waehlt, kann die
        // Zeilen ohne Filiale ueber keine Auswahl erreichen. Der Ort-Filter wird in
        // den Bloecken deshalb ignoriert, der Taetigkeits-Filter nicht.
        $component = $this->component('Essen');
        $component->activityFilter = 'Service';
        $cohort = $component->cohort();

        // „Bankett" ist die Taetigkeit der geschlossenen Essener Ausschreibung
        $this->assertNotContains(self::APP_ESSEN_ZU, $this->idsOf($cohort['closed_rows']),
            'fremde Taetigkeit faellt auch im Block heraus');
        $this->assertContains(self::APP_FREMD_ZU, $this->idsOf($cohort['closed_rows']),
            'dieselbe Taetigkeit einer anderen Filiale bleibt drin (der ORT wird ignoriert)');

        // ... und der Ort-Filter bleibt in den Bloecken ohne Wirkung
        $this->assertSame(
            $this->idsOf($cohort['closed_rows']),
            $this->idsOf((function () {
                $c = $this->component('Wuppertal');
                $c->activityFilter = 'Service';

                return $c->cohort()['closed_rows'];
            })()),
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

    /**
     * Der Bestand ist die VOLLSTAENDIGE Fall-Liste: Standort gepflegt/nicht
     * gepflegt x online/geschlossen, dazu eine Bewerbung ohne Ausschreibung, eine
     * fremde Filiale und ein Testbewerber. Genau darum kann der Test oben eine
     * Partition behaupten.
     */
    private static function seed(): void
    {
        $now = self::HEUTE;

        Capsule::table('rec_positions')->insert([
            ['id' => 61, 'uuid' => 'kpos-61', 'team_id' => self::TEAM, 'title' => 'Kellner',
             'location' => 'Essen', 'is_active' => 1, 'created_at' => $now, 'updated_at' => $now],
            ['id' => 62, 'uuid' => 'kpos-62', 'team_id' => self::TEAM, 'title' => 'Küche',
             'location' => 'Wuppertal', 'is_active' => 1, 'created_at' => $now, 'updated_at' => $now],
            // OHNE gepflegten Standort — die Stelle, wegen der es den Block
            // „Ohne Filial-Zuordnung" gibt (live rund 929 Bewerbungen)
            ['id' => 63, 'uuid' => 'kpos-63', 'team_id' => self::TEAM, 'title' => 'Springer',
             'location' => null, 'is_active' => 1, 'created_at' => $now, 'updated_at' => $now],
        ]);

        Capsule::table('rec_phases')->insert([
            ['id' => 91, 'uuid' => 'kph-91', 'team_id' => self::TEAM, 'rec_position_id' => 61,
             'name' => 'Eingang', 'order' => 1, 'is_active' => 1, 'created_at' => $now, 'updated_at' => $now],
            ['id' => 92, 'uuid' => 'kph-92', 'team_id' => self::TEAM, 'rec_position_id' => 62,
             'name' => 'Eingang', 'order' => 1, 'is_active' => 1, 'created_at' => $now, 'updated_at' => $now],
            ['id' => 93, 'uuid' => 'kph-93', 'team_id' => self::TEAM, 'rec_position_id' => 63,
             'name' => 'Eingang', 'order' => 1, 'is_active' => 1, 'created_at' => $now, 'updated_at' => $now],
        ]);

        Capsule::table('rec_postings')->insert([
            ['id' => self::POSTING_ESSEN_ONLINE, 'uuid' => 'kpost-610', 'team_id' => self::TEAM,
             'rec_position_id' => 61, 'title' => 'Kellner (m/w/d)', 'activity' => 'Service',
             'status' => 'published', 'is_active' => 1, 'published_at' => null, 'closes_at' => null,
             'bedarf' => null, 'bewerbungs_faktor' => null, 'created_at' => $now, 'updated_at' => $now],
            ['id' => self::POSTING_ESSEN_ZU, 'uuid' => 'kpost-611', 'team_id' => self::TEAM,
             'rec_position_id' => 61, 'title' => 'Aushilfe Bankett', 'activity' => 'Bankett',
             'status' => 'draft', 'is_active' => 1, 'published_at' => null, 'closes_at' => null,
             'bedarf' => null, 'bewerbungs_faktor' => null, 'created_at' => $now, 'updated_at' => $now],
            ['id' => self::POSTING_OHNE_ORT_ONLINE, 'uuid' => 'kpost-612', 'team_id' => self::TEAM,
             'rec_position_id' => 63, 'title' => 'Springer ohne Standort', 'activity' => 'Service',
             'status' => 'published', 'is_active' => 1, 'published_at' => null, 'closes_at' => null,
             'bedarf' => null, 'bewerbungs_faktor' => null, 'created_at' => $now, 'updated_at' => $now],
            ['id' => self::POSTING_OHNE_ORT_ZU, 'uuid' => 'kpost-613', 'team_id' => self::TEAM,
             'rec_position_id' => 63, 'title' => 'Springer alt', 'activity' => 'Service',
             // published, aber NICHT aktiv: der zweite Weg, nicht online zu sein
             'status' => 'published', 'is_active' => 0, 'published_at' => null, 'closes_at' => null,
             'bedarf' => null, 'bewerbungs_faktor' => null, 'created_at' => $now, 'updated_at' => $now],
            ['id' => self::POSTING_FREMD_ONLINE, 'uuid' => 'kpost-614', 'team_id' => self::TEAM,
             'rec_position_id' => 62, 'title' => 'Küchenhilfe', 'activity' => 'Küche',
             'status' => 'published', 'is_active' => 1, 'published_at' => null, 'closes_at' => null,
             'bedarf' => null, 'bewerbungs_faktor' => null, 'created_at' => $now, 'updated_at' => $now],
            // status='closed' ist der dritte Weg, nicht online zu sein (die Spalte
            // ist ein enum draft|published|closed, siehe Migration)
            ['id' => self::POSTING_FREMD_ZU, 'uuid' => 'kpost-615', 'team_id' => self::TEAM,
             'rec_position_id' => 62, 'title' => 'Küchenhilfe alt', 'activity' => 'Service',
             'status' => 'closed', 'is_active' => 1, 'published_at' => null, 'closes_at' => null,
             'bedarf' => null, 'bewerbungs_faktor' => null, 'created_at' => $now, 'updated_at' => $now],
        ]);

        Capsule::table('rec_applicants')->insert([
            ['id' => self::APP_ESSEN_ONLINE, 'uuid' => 'kapp-901', 'team_id' => self::TEAM,
             'applied_at' => '2026-07-01', 'rec_phase_id' => 91, 'is_test' => 0,
             'created_at' => $now, 'updated_at' => $now],
            ['id' => self::APP_ESSEN_ZU, 'uuid' => 'kapp-902', 'team_id' => self::TEAM,
             'applied_at' => '2026-07-02', 'rec_phase_id' => 91, 'is_test' => 0,
             'created_at' => $now, 'updated_at' => $now],
            ['id' => self::APP_OHNE_ORT_ONLINE, 'uuid' => 'kapp-903', 'team_id' => self::TEAM,
             'applied_at' => '2026-07-03', 'rec_phase_id' => 93, 'is_test' => 0,
             'created_at' => $now, 'updated_at' => $now],
            ['id' => self::APP_OHNE_ORT_ZU, 'uuid' => 'kapp-904', 'team_id' => self::TEAM,
             'applied_at' => '2026-07-04', 'rec_phase_id' => 93, 'is_test' => 0,
             'created_at' => $now, 'updated_at' => $now],
            // OHNE Pivot-Eintrag: Fall 3 der Zuordnungsregel
            ['id' => self::APP_OHNE_AUSSCHREIBUNG, 'uuid' => 'kapp-905', 'team_id' => self::TEAM,
             'applied_at' => '2026-07-05', 'rec_phase_id' => 91, 'is_test' => 0,
             'created_at' => $now, 'updated_at' => $now],
            ['id' => self::APP_FREMD_ONLINE, 'uuid' => 'kapp-906', 'team_id' => self::TEAM,
             'applied_at' => '2026-07-06', 'rec_phase_id' => 92, 'is_test' => 0,
             'created_at' => $now, 'updated_at' => $now],
            ['id' => self::APP_FREMD_ZU, 'uuid' => 'kapp-907', 'team_id' => self::TEAM,
             'applied_at' => '2026-07-07', 'rec_phase_id' => 92, 'is_test' => 0,
             'created_at' => $now, 'updated_at' => $now],
            ['id' => self::APP_TEST, 'uuid' => 'kapp-908', 'team_id' => self::TEAM,
             'applied_at' => '2026-07-08', 'rec_phase_id' => 91, 'is_test' => 1,
             'created_at' => $now, 'updated_at' => $now],
        ]);

        Capsule::table('rec_applicant_posting')->insert([
            ['rec_applicant_id' => self::APP_ESSEN_ONLINE, 'rec_posting_id' => self::POSTING_ESSEN_ONLINE,
             'created_at' => $now, 'updated_at' => $now],
            ['rec_applicant_id' => self::APP_ESSEN_ZU, 'rec_posting_id' => self::POSTING_ESSEN_ZU,
             'created_at' => $now, 'updated_at' => $now],
            ['rec_applicant_id' => self::APP_OHNE_ORT_ONLINE, 'rec_posting_id' => self::POSTING_OHNE_ORT_ONLINE,
             'created_at' => $now, 'updated_at' => $now],
            ['rec_applicant_id' => self::APP_OHNE_ORT_ZU, 'rec_posting_id' => self::POSTING_OHNE_ORT_ZU,
             'created_at' => $now, 'updated_at' => $now],
            ['rec_applicant_id' => self::APP_FREMD_ONLINE, 'rec_posting_id' => self::POSTING_FREMD_ONLINE,
             'created_at' => $now, 'updated_at' => $now],
            ['rec_applicant_id' => self::APP_FREMD_ZU, 'rec_posting_id' => self::POSTING_FREMD_ZU,
             'created_at' => $now, 'updated_at' => $now],
            ['rec_applicant_id' => self::APP_TEST, 'rec_posting_id' => self::POSTING_ESSEN_ONLINE,
             'created_at' => $now, 'updated_at' => $now],
        ]);
    }
}
