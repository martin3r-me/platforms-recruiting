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
use Platform\Recruiting\Livewire\InterviewSchedule\Index as SchedulePage;

/**
 * Die Termin-Uebersicht trennt anstehende von vergangenen Terminen, damit die
 * Liste nicht mit Altbestand zulaeuft (vergangene stehen eingeklappt darunter).
 *
 * Getestet wird die TRENNLINIE, denn dort liegt die einzige Entscheidung, die
 * man falsch treffen kann: ein Termin, der gerade LAEUFT (Start vorbei, Ende in
 * der Zukunft), gehoert nach oben zu den anstehenden — er waere sonst genau in
 * dem Moment eingeklappt, in dem man ihn braucht. Faellt das Ende weg, zaehlt
 * der Start.
 *
 * Ebenso festgehalten: die Trennung VERLIERT keinen Termin (Summe beider Gruppen
 * = die Gesamtmenge, aus der auch die Seitenleiste zaehlt) und sie hebelt die
 * Filter nicht aus.
 *
 * Aufbau wie die anderen Integrationstests des Moduls (Container + Capsule von
 * Hand, ECHTE Migrationen per glob, auth() als Attrappe, feste Uhr).
 */
class InterviewSchedulePastGroupingTest extends TestCase
{
    private const TEAM = 8;
    private const FREMDES_TEAM = 99;

    /** Morgen — anstehend. */
    private const TERMIN_MORGEN = 840;

    /** Gestern, mit Ende — vergangen. */
    private const TERMIN_GESTERN = 841;

    /** Laeuft GERADE: Start vor einer Stunde, Ende in einer Stunde. */
    private const TERMIN_LAEUFT = 842;

    /** Heute frueh, OHNE Ende — dann zaehlt der Start, also vergangen. */
    private const TERMIN_HEUTE_FRUEH_OHNE_ENDE = 843;

    /** Gestern und abgesagt — fuer die Gegenprobe mit dem Status-Filter. */
    private const TERMIN_GESTERN_ABGESAGT = 844;

    /** Fremdes Team, morgen — darf in keiner der beiden Gruppen stehen. */
    private const TERMIN_FREMD = 899;

    private const HEUTE = '2026-08-17 12:00:00';

    public static function setUpBeforeClass(): void
    {
        $container = Container::getInstance();

        $capsule = new Capsule();
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        // Dispatcher gesetzt, obwohl dieser Test die Zeilen direkt einfuegt: eine
        // Modellklasse, die im geteilten PHPUnit-Prozess zuerst OHNE Dispatcher
        // bootet, laesst ihre creating-Hooks fuer alle spaeteren Testklassen still
        // ausfallen (siehe Kommentar in phpunit.xml).
        $capsule->setEventDispatcher(new Dispatcher($container));
        $capsule->setAsGlobal();
        $capsule->bootEloquent();
        Model::unguard();

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

    public function test_ein_laufender_termin_bleibt_bei_den_anstehenden(): void
    {
        $seite = new SchedulePage();

        $anstehend = $seite->upcomingInterviews()->pluck('id')->all();

        // Start vorbei, Ende in der Zukunft: der Termin laeuft, er ist nicht vorbei
        $this->assertContains(self::TERMIN_LAEUFT, $anstehend, 'ein laufender Termin darf nicht eingeklappt werden');
        $this->assertContains(self::TERMIN_MORGEN, $anstehend);

        // GEGENPROBE, sonst zeigte der Test nur, dass alles anstehend ist
        $this->assertNotContains(self::TERMIN_GESTERN, $anstehend);
        $this->assertNotContains(self::TERMIN_HEUTE_FRUEH_OHNE_ENDE, $anstehend);
    }

    public function test_ohne_ende_zaehlt_der_start(): void
    {
        $seite = new SchedulePage();

        $vergangen = $seite->pastInterviews()->pluck('id')->all();

        $this->assertContains(self::TERMIN_HEUTE_FRUEH_OHNE_ENDE, $vergangen, 'ohne Ende entscheidet der Start');
        $this->assertContains(self::TERMIN_GESTERN, $vergangen);

        // GEGENPROBE
        $this->assertNotContains(self::TERMIN_LAEUFT, $vergangen);
        $this->assertNotContains(self::TERMIN_MORGEN, $vergangen);
    }

    public function test_die_trennung_verliert_keinen_termin(): void
    {
        // Die Seitenleiste zaehlt weiter aus der Gesamtmenge. Ginge bei der
        // Trennung ein Termin verloren, waeren Tabelle und Zaehler uneins — und
        // zwar still.
        $seite = new SchedulePage();

        $alle = $seite->interviews()->pluck('id')->sort()->values()->all();
        $getrennt = $seite->upcomingInterviews()->pluck('id')
            ->merge($seite->pastInterviews()->pluck('id'))
            ->sort()->values()->all();

        $this->assertSame($alle, $getrennt);
        $this->assertCount(5, $alle, 'die fuenf Termine des eigenen Teams');
        $this->assertNotContains(self::TERMIN_FREMD, $alle, 'der Termin des fremden Teams bleibt aussen vor');
    }

    public function test_beide_gruppen_bleiben_absteigend_sortiert(): void
    {
        // Bewusst NICHT umgedreht: die Reihenfolge der Uebersicht bleibt wie sie
        // war, die Trennung ist der einzige Unterschied.
        $seite = new SchedulePage();

        $this->assertSame(
            [self::TERMIN_MORGEN, self::TERMIN_LAEUFT],
            $seite->upcomingInterviews()->pluck('id')->all(),
        );
        $this->assertSame(
            [self::TERMIN_HEUTE_FRUEH_OHNE_ENDE, self::TERMIN_GESTERN, self::TERMIN_GESTERN_ABGESAGT],
            $seite->pastInterviews()->pluck('id')->all(),
        );
    }

    public function test_die_filter_wirken_in_beiden_gruppen(): void
    {
        $seite = new SchedulePage();
        $seite->filterStatus = 'cancelled';

        $this->assertSame([], $seite->upcomingInterviews()->pluck('id')->all());
        $this->assertSame([self::TERMIN_GESTERN_ABGESAGT], $seite->pastInterviews()->pluck('id')->all());

        // GEGENPROBE mit der Suche, die auf einen anstehenden Termin passt
        $gesucht = new SchedulePage();
        $gesucht->search = 'Schulung Morgen';
        $this->assertSame([self::TERMIN_MORGEN], $gesucht->upcomingInterviews()->pluck('id')->all());
        $this->assertSame([], $gesucht->pastInterviews()->pluck('id')->all());
    }

    // -----------------------------------------------------------------
    // Schema und Datenbestand
    // -----------------------------------------------------------------

    private static function runRealMigrations(): void
    {
        // Fremde Tabellen, auf die die eigenen Migrationen per Fremdschluessel
        // zeigen. Leer genuegt: die Uebersicht laedt die Gespraechspartner
        // (users) mit, und ein Eager Load auf eine FEHLENDE Tabelle ist ein
        // Fehler — auf eine leere nur ein leeres Ergebnis.
        $schema = Capsule::connection()->getSchemaBuilder();
        $schema->create('teams', fn ($table) => $table->id());
        $schema->create('users', fn ($table) => $table->id());
        $schema->create('hcm_job_titles', fn ($table) => $table->id());
        $schema->create('comms_channels', fn ($table) => $table->id());

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

        Capsule::table('rec_interviews')->insert([
            ['id' => self::TERMIN_MORGEN, 'uuid' => 'giv-840', 'team_id' => self::TEAM,
             'title' => 'Schulung Morgen', 'starts_at' => '2026-08-18 10:00:00',
             'ends_at' => '2026-08-18 12:00:00', 'status' => 'planned',
             'is_active' => 1, 'created_at' => $now, 'updated_at' => $now],

            ['id' => self::TERMIN_LAEUFT, 'uuid' => 'giv-842', 'team_id' => self::TEAM,
             'title' => 'Schulung laeuft gerade', 'starts_at' => '2026-08-17 11:00:00',
             'ends_at' => '2026-08-17 13:00:00', 'status' => 'confirmed',
             'is_active' => 1, 'created_at' => $now, 'updated_at' => $now],

            ['id' => self::TERMIN_HEUTE_FRUEH_OHNE_ENDE, 'uuid' => 'giv-843', 'team_id' => self::TEAM,
             'title' => 'Schulung heute frueh', 'starts_at' => '2026-08-17 08:00:00',
             'ends_at' => null, 'status' => 'completed',
             'is_active' => 1, 'created_at' => $now, 'updated_at' => $now],

            ['id' => self::TERMIN_GESTERN, 'uuid' => 'giv-841', 'team_id' => self::TEAM,
             'title' => 'Schulung gestern', 'starts_at' => '2026-08-16 10:00:00',
             'ends_at' => '2026-08-16 12:00:00', 'status' => 'completed',
             'is_active' => 1, 'created_at' => $now, 'updated_at' => $now],

            ['id' => self::TERMIN_GESTERN_ABGESAGT, 'uuid' => 'giv-844', 'team_id' => self::TEAM,
             'title' => 'Schulung abgesagt', 'starts_at' => '2026-08-16 09:00:00',
             'ends_at' => '2026-08-16 10:00:00', 'status' => 'cancelled',
             'is_active' => 1, 'created_at' => $now, 'updated_at' => $now],

            ['id' => self::TERMIN_FREMD, 'uuid' => 'giv-899', 'team_id' => self::FREMDES_TEAM,
             'title' => 'Fremdes Team morgen', 'starts_at' => '2026-08-18 10:00:00',
             'ends_at' => '2026-08-18 12:00:00', 'status' => 'planned',
             'is_active' => 1, 'created_at' => $now, 'updated_at' => $now],
        ]);
    }
}
