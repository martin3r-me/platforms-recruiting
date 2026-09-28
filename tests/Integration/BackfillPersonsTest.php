<?php

namespace Platform\Recruiting\Tests\Integration;

use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Console\Commands\BackfillPersons;
use Platform\Recruiting\Observers\RecEmployeeExportObserver;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * recruiting:personen-anlegen -- der Bestandslauf, der rund 1300 Mitarbeiter
 * auf einmal beruehrt.
 *
 * Der wichtigste Test hier ist test_backfill_setzt_keinen_zas_marker: er
 * erlaubt diesem Lauf ueberhaupt, ueber den ganzen Bestand zu gehen, ohne
 * die halbe Belegschaft in Michels naechste updates.csv zu spuelen. Damit er
 * nicht stumm gruen bleibt (wie schon zweimal in diesem Projekt), registriert
 * setUp() den ECHTEN RecEmployeeExportObserver gegen den handgebauten
 * Dispatcher -- Muster PersonLinkerTest.
 */
final class BackfillPersonsTest extends TestCase
{
    private const TEAM = 3;

    private Capsule $capsule;

    protected function setUp(): void
    {
        parent::setUp();
        $container = Container::getInstance();
        Container::setInstance($container);

        // Log-Attrappe VOR dem Leeren der Facade-Instanzen binden
        // (reference_log_facade_test_stub.md) -- sonst fliegt eine
        // ReflectionException, sobald der echte Observer \Log::warning(...)
        // in safelyRun() aufruft.
        if (!class_exists('Log', false)) {
            class_alias(\Illuminate\Support\Facades\Log::class, 'Log');
        }
        $container->instance('log', new class {
            public function __call($m, $a) {}
        });

        $this->capsule = new Capsule($container);
        $this->capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $this->capsule->setEventDispatcher(new Dispatcher($container));
        $this->capsule->setAsGlobal();
        $this->capsule->bootEloquent();
        Model::clearBootedModels();

        $container->instance('db', $this->capsule->getDatabaseManager());
        $container->instance('db.schema', $this->capsule->getConnection()->getSchemaBuilder());
        Facade::setFacadeApplication($container);
        Facade::clearResolvedInstances();

        $this->capsule->schema()->create('rec_persons', function ($t) {
            $t->increments('id');
            $t->string('uuid', 64)->unique();
            $t->integer('team_id')->nullable();
            $t->string('phone', 32)->nullable();
            $t->string('password_hash')->nullable();
            $t->string('email')->nullable();
            $t->timestamp('invited_at')->nullable();
            $t->timestamp('registered_at')->nullable();
            $t->timestamp('locked_at')->nullable();
            $t->integer('merged_into_person_id')->nullable();
            $t->timestamps();

            // Derselbe Eindeutigkeits-Index wie in der echten Migration --
            // die Kollisionsregel (Ruling T1-A) haengt genau an ihm.
            $t->unique(['team_id', 'phone'], 'rec_persons_team_phone_unique');
        });

        $this->capsule->schema()->create('rec_employees', function ($t) {
            $t->increments('id');
            $t->integer('team_id')->nullable();
            $t->string('person_key', 64)->nullable();
            $t->integer('rec_person_id')->nullable();
            $t->string('company')->nullable();
            $t->string('phone')->nullable();
            $t->boolean('is_active')->nullable();
            $t->timestamp('zas_changed_at')->nullable();
            $t->timestamps();
        });

        // RecEmployeeExportObserver::trackPayrollChanges() liest die
        // Team-Einstellungen -- ohne die Tabelle liefe der Lauf in den
        // stillen Fehlerzweig (safelyRun) und der ZAS-Marker-Test pruefte
        // den Marker, ohne dass der echte Beobachter-Code gelaufen ist.
        $this->capsule->schema()->create('rec_applicant_settings', function ($t) {
            $t->increments('id');
            $t->integer('team_id')->unique();
            $t->text('settings')->nullable();
            $t->timestamps();
        });

        // Der echte Beobachter, nicht seine Abwesenheit, ist die Zusicherung
        // dieser Testklasse.
        RecEmployeeExportObserver::register();
    }

    protected function tearDown(): void
    {
        Model::unsetConnectionResolver();
        Model::clearBootedModels();
        Container::getInstance()->forgetInstance('log');
        Facade::clearResolvedInstances();
        parent::tearDown();
    }

    private function lauf(bool $dryRun = false): BackfillPersons
    {
        $command = new BackfillPersons();
        $command->setLaravel(new BackfillPersonsFakeLaravel());

        $optionen = [];
        if ($dryRun) {
            $optionen['--dry-run'] = true;
        }

        $input = new ArrayInput($optionen, $command->getDefinition());
        $output = new BufferedOutput();

        $command->run($input, $output);

        return $command;
    }

    public function test_jeder_bekommt_eine_person_auch_inaktive(): void
    {
        DB::table('rec_employees')->insert([
            ['id' => 1, 'team_id' => self::TEAM, 'person_key' => null, 'phone' => '+4915111111111', 'is_active' => 1, 'updated_at' => '2026-01-01 00:00:00'],
            ['id' => 2, 'team_id' => self::TEAM, 'person_key' => null, 'phone' => '+4915122222222', 'is_active' => 0, 'updated_at' => '2026-01-01 00:00:00'],
        ]);

        $this->lauf();

        $this->assertSame(2, (int) DB::table('rec_persons')->count(), 'ein Rueckkehrer braucht seine alte Person, nicht eine zweite');
        $this->assertSame(0, (int) DB::table('rec_employees')->whereNull('rec_person_id')->count());
    }

    public function test_gepaarte_teilen_sich_eine(): void
    {
        DB::table('rec_employees')->insert([
            ['id' => 1, 'team_id' => self::TEAM, 'person_key' => 'p-1', 'phone' => '+4915111111111', 'is_active' => 1, 'updated_at' => '2026-01-01 00:00:00'],
            ['id' => 2, 'team_id' => self::TEAM, 'person_key' => 'p-1', 'phone' => '+4915111111111', 'is_active' => 1, 'updated_at' => '2026-01-01 00:00:00'],
        ]);

        $this->lauf();

        $this->assertSame(1, (int) DB::table('rec_persons')->count());
        $ids = DB::table('rec_employees')->orderBy('id')->pluck('rec_person_id')->map(fn ($v) => (int) $v)->all();
        $this->assertSame($ids[0], $ids[1]);
    }

    public function test_zweiter_lauf_legt_nichts_neu_an(): void
    {
        DB::table('rec_employees')->insert([
            ['id' => 1, 'team_id' => self::TEAM, 'person_key' => null, 'phone' => '+4915111111111', 'is_active' => 1, 'updated_at' => '2026-01-01 00:00:00'],
        ]);

        $this->lauf();
        $ersteId = (int) DB::table('rec_employees')->where('id', 1)->value('rec_person_id');
        $this->lauf();

        $this->assertSame(1, (int) DB::table('rec_persons')->count(), 'das Kommando muss wiederholbar sein');
        $this->assertSame($ersteId, (int) DB::table('rec_employees')->where('id', 1)->value('rec_person_id'));
    }

    public function test_dry_run_schreibt_nichts(): void
    {
        DB::table('rec_employees')->insert([
            ['id' => 1, 'team_id' => self::TEAM, 'person_key' => null, 'phone' => '+4915111111111', 'is_active' => 1, 'updated_at' => '2026-01-01 00:00:00'],
        ]);

        $this->lauf(dryRun: true);

        $this->assertSame(0, (int) DB::table('rec_persons')->count());
        $this->assertNull(DB::table('rec_employees')->where('id', 1)->value('rec_person_id'));
    }

    public function test_uneinige_nummern_werden_gemeldet(): void
    {
        DB::table('rec_employees')->insert([
            ['id' => 1, 'team_id' => self::TEAM, 'person_key' => 'p-1', 'phone' => '+4915111111111', 'is_active' => 1, 'updated_at' => '2026-01-01 00:00:00'],
            ['id' => 2, 'team_id' => self::TEAM, 'person_key' => 'p-1', 'phone' => '+4915122222222', 'is_active' => 1, 'updated_at' => '2026-06-01 00:00:00'],
            // Zweite Gruppe, EINIGE Nummer (M4, Fixrunde 1): ohne sie waeren
            // "jede Gruppe zaehlt" und "nur uneinige Gruppen zaehlen" nicht
            // zu unterscheiden -- der urspruengliche Test hatte im ganzen
            // Lauf nur eine einzige Gruppe.
            ['id' => 3, 'team_id' => self::TEAM, 'person_key' => 'p-2', 'phone' => '+4915133333333', 'is_active' => 1, 'updated_at' => '2026-01-01 00:00:00'],
            ['id' => 4, 'team_id' => self::TEAM, 'person_key' => 'p-2', 'phone' => '+4915133333333', 'is_active' => 1, 'updated_at' => '2026-01-02 00:00:00'],
        ]);

        $ausgabe = $this->lauf()->ausgabe();

        $this->assertStringContainsString('Nummern uneinig: 1', $ausgabe, 'die HR-Liste muss NUR die uneinige Gruppe nennen, nicht jede Gruppe');

        $personIdGruppeEins = DB::table('rec_employees')->where('id', 1)->value('rec_person_id');
        $this->assertSame(
            '+4915122222222',
            DB::table('rec_persons')->where('id', $personIdGruppeEins)->value('phone'),
            'der zuletzt geaenderte Wert gewinnt (Canvas 68)',
        );
    }

    public function test_zwei_verschiedene_menschen_an_einer_nummer_toeten_den_lauf_nicht(): void
    {
        // Genau der Fall, den der Mehrfachnummern-Zaehler zaehlt. Ohne die
        // Kollisionsregel stirbt der Lauf hier an (team_id, phone).
        DB::table('rec_employees')->insert([
            ['id' => 1, 'team_id' => self::TEAM, 'person_key' => null, 'phone' => '+4915112345678', 'is_active' => 1, 'updated_at' => '2026-01-01 00:00:00'],
            ['id' => 2, 'team_id' => self::TEAM, 'person_key' => null, 'phone' => '+4915112345678', 'is_active' => 1, 'updated_at' => '2026-02-01 00:00:00'],
        ]);

        $ausgabe = $this->lauf()->ausgabe();

        $this->assertSame(2, (int) DB::table('rec_persons')->count(), 'beide bekommen ihre Zeile, die Klammer bleibt vollstaendig');
        $this->assertSame(
            1,
            (int) DB::table('rec_persons')->whereNotNull('phone')->count(),
            'nur einer darf die Nummer tragen -- der Index ist die Garantie',
        );
        $this->assertStringContainsString('Nummer nicht gesetzt (Dublette oder unlesbar): 1', $ausgabe);
    }

    /**
     * Ruling T4-A (Fixrunde 1): "wiederholbar" heisst auch, dass ein
     * SPAETERER Lauf mit einem neu dazugekommenen Geschwister (gleicher
     * person_key) dieses Geschwister an die ALTE Person haengt. Reines
     * whereNull('rec_person_id') wuerde das schon gebundene Geschwister aus
     * der Abfrage werfen -- verbinde()s eigene Wiederverwendungspruefung
     * saehe die gebundene Kennung dann gar nicht, und der Rueckkehrer
     * bekaeme lautlos eine zweite Person.
     */
    public function test_rueckkehrer_mit_bereits_gebundenem_geschwister_bekommt_dieselbe_person(): void
    {
        DB::table('rec_employees')->insert([
            ['id' => 1, 'team_id' => self::TEAM, 'person_key' => 'p-9', 'phone' => '+4915111111111', 'is_active' => 1, 'updated_at' => '2026-01-01 00:00:00'],
        ]);

        $this->lauf();
        $ersteId = (int) DB::table('rec_employees')->where('id', 1)->value('rec_person_id');

        // Das Geschwister kommt ERST NACH dem ersten Lauf dazu -- genau der
        // Fall, den Ruling T4-A abdeckt.
        DB::table('rec_employees')->insert([
            ['id' => 2, 'team_id' => self::TEAM, 'person_key' => 'p-9', 'phone' => '+4915111111111', 'is_active' => 1, 'updated_at' => '2026-02-01 00:00:00'],
        ]);

        $this->lauf();

        $this->assertSame(
            1,
            (int) DB::table('rec_persons')->count(),
            'das Geschwister braucht die ALTE Person, nicht eine zweite',
        );
        $this->assertSame(
            $ersteId,
            (int) DB::table('rec_employees')->where('id', 2)->value('rec_person_id'),
        );
    }

    public function test_backfill_setzt_keinen_zas_marker(): void
    {
        DB::table('rec_employees')->insert([
            ['id' => 1, 'team_id' => self::TEAM, 'person_key' => null, 'phone' => '+4915111111111', 'is_active' => 1, 'updated_at' => '2026-01-01 00:00:00', 'zas_changed_at' => null],
        ]);

        $this->lauf();

        $this->assertSame(
            0,
            (int) DB::table('rec_employees')->whereNotNull('zas_changed_at')->count(),
            'ein Backfill ueber den ganzen Bestand darf niemanden in die updates.csv spuelen',
        );

        // Feld-unabhaengiger Zweitbeleg (wie PersonLinkerTest::
        // test_verbinden_setzt_keinen_zas_marker): rec_person_id steht gar
        // nicht in RecEmployeeExportObserver::RELEVANT_EMPLOYEE_FIELDS -- die
        // Zusicherung oben ueber zas_changed_at bliebe also AUCH mit dem
        // echten Beobachter gruen, wenn irgendwo im Aufrufpfad auf Eloquent
        // umgestellt wuerde. updated_at dagegen fasst JEDES Eloquent-save()
        // automatisch an, ein DB::table()-Update nur auf ausdrueckliche
        // Anweisung -- das macht diesen Beleg unabhaengig von der Feldliste
        // und ist der Beleg, der bei der Mutationsprobe tatsaechlich rot wird.
        $this->assertSame(
            '2026-01-01 00:00:00',
            (string) DB::table('rec_employees')->where('id', 1)->value('updated_at'),
            'ein Eloquent-Schreibweg haette updated_at automatisch angefasst, PersonLinker::verbinde() (Query Builder) nicht',
        );
    }

    public function test_team_filter_laesst_andere_teams_unberuehrt(): void
    {
        DB::table('rec_employees')->insert([
            ['id' => 1, 'team_id' => self::TEAM, 'person_key' => null, 'phone' => '+4915111111111', 'is_active' => 1, 'updated_at' => '2026-01-01 00:00:00'],
            ['id' => 2, 'team_id' => self::TEAM + 1, 'person_key' => null, 'phone' => '+4915122222222', 'is_active' => 1, 'updated_at' => '2026-01-01 00:00:00'],
        ]);

        $command = new BackfillPersons();
        $command->setLaravel(new BackfillPersonsFakeLaravel());
        $input = new ArrayInput(['--team' => (string) self::TEAM], $command->getDefinition());
        $output = new BufferedOutput();
        $command->run($input, $output);

        $this->assertNotNull(DB::table('rec_employees')->where('id', 1)->value('rec_person_id'));
        $this->assertNull(DB::table('rec_employees')->where('id', 2)->value('rec_person_id'), 'das andere Team darf der Team-Filter nicht anfassen');
    }

    /**
     * C1 (Schlusspruefung): der Lauf bestaetigt je Gruppe statt am Ende.
     * Bricht er mitten drin ab, muessen die bis dahin verbundenen
     * Datensaetze STEHEN BLEIBEN -- eine grosse Transaktion haette sie hier
     * alle mit zurueckgerollt. Und der naechste Lauf macht sauber weiter,
     * ohne das schon Erledigte noch einmal anzufassen.
     *
     * Der Abbruch ist echt und nicht gestellt: die Gruppe p-2 traegt zwei
     * VERSCHIEDENE rec_person_id, das ist der Zusammenlege-Fall, und
     * PersonLinker::verbinde() weigert sich dort ausdruecklich.
     */
    public function test_abbruch_behaelt_die_bis_dahin_verbundenen(): void
    {
        $personA = DB::table('rec_persons')->insertGetId([
            'uuid' => 'p-a', 'team_id' => self::TEAM,
            'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00',
        ]);
        $personB = DB::table('rec_persons')->insertGetId([
            'uuid' => 'p-b', 'team_id' => self::TEAM,
            'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00',
        ]);

        DB::table('rec_employees')->insert([
            // Gruppe 1: sauber, wird VOR dem Abbruch bestaetigt.
            ['id' => 1, 'team_id' => self::TEAM, 'person_key' => 'p-1', 'rec_person_id' => null, 'phone' => '+4915111111111', 'is_active' => 1, 'updated_at' => '2026-01-01 00:00:00'],
            ['id' => 2, 'team_id' => self::TEAM, 'person_key' => 'p-1', 'rec_person_id' => null, 'phone' => '+4915111111111', 'is_active' => 1, 'updated_at' => '2026-01-01 00:00:00'],
            // Gruppe 2: zwei gebundene Geschwister an VERSCHIEDENEN Personen
            // plus ein ungebundenes -- daran stirbt der Lauf.
            ['id' => 3, 'team_id' => self::TEAM, 'person_key' => 'p-2', 'rec_person_id' => $personA, 'phone' => '+4915122222222', 'is_active' => 1, 'updated_at' => '2026-01-01 00:00:00'],
            ['id' => 4, 'team_id' => self::TEAM, 'person_key' => 'p-2', 'rec_person_id' => $personB, 'phone' => '+4915122222222', 'is_active' => 1, 'updated_at' => '2026-01-01 00:00:00'],
            ['id' => 5, 'team_id' => self::TEAM, 'person_key' => 'p-2', 'rec_person_id' => null, 'phone' => '+4915122222222', 'is_active' => 1, 'updated_at' => '2026-01-01 00:00:00'],
        ]);

        $geworfen = null;
        try {
            $this->lauf();
        } catch (\InvalidArgumentException $e) {
            $geworfen = $e;
        }

        $this->assertNotNull($geworfen, 'der Zusammenlege-Fall muss den Lauf abbrechen, nicht stillschweigend etwas raten');

        $personGruppeEins = DB::table('rec_employees')->where('id', 1)->value('rec_person_id');
        $this->assertNotNull($personGruppeEins, 'die vor dem Abbruch verbundene Gruppe muss stehen bleiben');
        $this->assertSame(
            (int) $personGruppeEins,
            (int) DB::table('rec_employees')->where('id', 2)->value('rec_person_id'),
            'eine Gruppe wird ganz oder gar nicht verbunden -- die Gruppe selbst bleibt atomar',
        );
        $this->assertNull(DB::table('rec_employees')->where('id', 5)->value('rec_person_id'), 'die abgebrochene Gruppe darf nichts hinterlassen');
        $this->assertSame(3, (int) DB::table('rec_persons')->count(), 'A, B und die eine neue Zeile der ersten Gruppe');

        // HR loest den Konflikt (beide Geschwister an dieselbe Person) --
        // danach muss der zweite Lauf einfach weitermachen.
        DB::table('rec_employees')->where('id', 4)->update(['rec_person_id' => $personA]);

        $this->lauf();

        $this->assertSame((int) $personA, (int) DB::table('rec_employees')->where('id', 5)->value('rec_person_id'));
        $this->assertSame(
            (int) $personGruppeEins,
            (int) DB::table('rec_employees')->where('id', 1)->value('rec_person_id'),
            'der zweite Lauf darf die schon erledigte Gruppe nicht noch einmal anfassen',
        );
        $this->assertSame(3, (int) DB::table('rec_persons')->count(), 'der zweite Lauf legt nichts Neues an');
    }

    /**
     * C1, zweite Haelfte: der Trockenlauf rollt jetzt je Gruppe zurueck
     * statt einmal am Ende. Auch ueber MEHRERE Gruppen hinweg darf danach
     * nichts stehen.
     */
    public function test_dry_run_hinterlaesst_auch_ueber_mehrere_gruppen_nichts(): void
    {
        DB::table('rec_employees')->insert([
            ['id' => 1, 'team_id' => self::TEAM, 'person_key' => 'p-1', 'phone' => '+4915111111111', 'is_active' => 1, 'updated_at' => '2026-01-01 00:00:00'],
            ['id' => 2, 'team_id' => self::TEAM, 'person_key' => 'p-1', 'phone' => '+4915111111111', 'is_active' => 1, 'updated_at' => '2026-01-01 00:00:00'],
            ['id' => 3, 'team_id' => self::TEAM, 'person_key' => null, 'phone' => '+4915133333333', 'is_active' => 1, 'updated_at' => '2026-01-01 00:00:00'],
            ['id' => 4, 'team_id' => self::TEAM, 'person_key' => null, 'phone' => '+4915144444444', 'is_active' => 1, 'updated_at' => '2026-01-01 00:00:00'],
        ]);

        $ausgabe = $this->lauf(dryRun: true)->ausgabe();

        $this->assertSame(0, (int) DB::table('rec_persons')->count(), 'ein Trockenlauf darf nichts hinterlassen');
        $this->assertSame(4, (int) DB::table('rec_employees')->whereNull('rec_person_id')->count());
        $this->assertStringContainsString('Personen angelegt: 3', $ausgabe, 'der Trockenlauf muss trotzdem melden, was er anlegen WUERDE');
    }

    /**
     * I3 (Schlusspruefung): "Nummern uneinig: 47" ist fuer HR wertlos, und
     * ein zweiter Lauf bringt die Faelle nicht zurueck (die Gruppen sind
     * dann gebunden). Also muessen die Kennungen im selben Lauf erscheinen
     * -- beide Sorten getrennt, ohne Namen.
     */
    public function test_kennungen_der_grenzfaelle_stehen_unter_der_uebersicht(): void
    {
        DB::table('rec_employees')->insert([
            // Uneinige Nummern in EINER Gruppe.
            ['id' => 1, 'team_id' => self::TEAM, 'person_key' => 'p-1', 'phone' => '+4915111111111', 'is_active' => 1, 'updated_at' => '2026-01-01 00:00:00'],
            ['id' => 2, 'team_id' => self::TEAM, 'person_key' => 'p-1', 'phone' => '+4915122222222', 'is_active' => 1, 'updated_at' => '2026-06-01 00:00:00'],
            // Zwei Menschen an derselben Nummer: der zweite bekommt keine.
            ['id' => 3, 'team_id' => self::TEAM, 'person_key' => null, 'phone' => '+4915133333333', 'is_active' => 1, 'updated_at' => '2026-01-01 00:00:00'],
            ['id' => 4, 'team_id' => self::TEAM, 'person_key' => null, 'phone' => '+4915133333333', 'is_active' => 1, 'updated_at' => '2026-01-01 00:00:00'],
        ]);

        $ausgabe = $this->lauf()->ausgabe();

        $this->assertStringContainsString('Nummern uneinig: 1', $ausgabe);
        $this->assertStringContainsString('Kennungen, Nummern uneinig', $ausgabe);
        $this->assertStringContainsString("\n  1 + 2", $ausgabe, 'HR braucht die Kennungen der uneinigen Gruppe, nicht nur ihre Anzahl');

        $this->assertStringContainsString('Nummer nicht gesetzt (Dublette oder unlesbar): 1', $ausgabe);
        $this->assertStringContainsString("\n  4", $ausgabe, 'auch die zweite Sorte braucht ihre Kennung');

        // Namen kommen nie mit (Muster recruiting:mitarbeiter-grenzfaelle):
        // die Ausgabe darf ausser Zahlen und Ueberschriften nichts tragen.
        $this->assertStringNotContainsString('+4915133333333', $ausgabe, 'eine Kennungsliste traegt keine Personendaten aus der Produktion');
    }
}

/**
 * Minimaler Ersatz fuer die volle Laravel-Application (Muster
 * SwitchPortalVersionFakeLaravel): Illuminate\Console\Command::run() braucht
 * runningUnitTests(), sonst nichts.
 */
final class BackfillPersonsFakeLaravel extends Container
{
    public function runningUnitTests(): bool
    {
        return true;
    }
}
