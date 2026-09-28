<?php

namespace Platform\Recruiting\Tests\Integration;

use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Observers\RecEmployeeExportObserver;
use Platform\Recruiting\Services\PersonLinker;
use Platform\Recruiting\Services\Zas\PersonPairLinker;
use Platform\Recruiting\Support\PersonGroupPlanner;

/**
 * Aufgabe 6 (Spec 2026-09-28, Paragraph 4): ein frisch gepaartes Paar
 * bekommt ueber PersonPairLinker::stamp() nicht nur seinen person_key,
 * sondern auch seine gemeinsame rec_persons-Zeile. Ohne diesen zweiten
 * Schritt faellt jedes NACH dem Backfill neu gepaarte Paar in den alten
 * Laufzeit-Zweig zurueck, den wir gerade loswerden.
 *
 * Der ZAS-Marker-Test registriert den ECHTEN RecEmployeeExportObserver
 * (wie tests/Integration/PersonLinkerTest.php) — sonst beweist der Test
 * nur, dass es in diesem Testlauf gar keinen Beobachter gibt, nicht dass
 * der neue Schreibweg ihn weiterhin umgeht.
 *
 * Fixrunde 1 (Ruling T6-A, C1): nach dem Backfill hat jeder markerlose
 * Datensatz laengst seine EIGENE Personen-Zeile. Das Audit-Kommando findet
 * ein sicheres Paar genau dann, wenn beide Seiten noch KEINEN person_key
 * tragen (PersonPairAuditPlanner ueberspringt nur Gruppen mit gemeinsamem,
 * nicht-leerem Marker) — jede so gefundene Stempelung ist also der
 * Zusammenlege-Fall, nicht der Verbinden-Fall. Ohne die Unterscheidung in
 * verbindePerson() wirft PersonLinker::verbinde() bei jedem sicheren Paar,
 * und `recruiting:person-pair-audit --apply` stirbt beim ersten Treffer.
 */
final class PersonPairLinkerPersonTest extends TestCase
{
    private const TEAM = 9;

    private Capsule $capsule;

    protected function setUp(): void
    {
        parent::setUp();
        $container = Container::getInstance();
        Container::setInstance($container);

        // Log-Attrappe binden, BEVOR die Facade-Instanzen geleert werden
        // (reference_log_facade_test_stub.md) — sonst fliegt eine
        // ReflectionException, sobald der echte Observer in safelyRun()
        // \Log::warning(...) aufruft.
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
            // Fuer PersonLinker::fuehreZusammen() (Fixrunde 1, C1) --
            // stillgelegte Verlierer-Zeilen behalten ihre Nummer, verlieren
            // aber die Anmeldung.
            $t->string('password_hash')->nullable();
            $t->timestamp('registered_at')->nullable();
            $t->integer('merged_into_person_id')->nullable();
            $t->timestamps();
            $t->unique(['team_id', 'phone'], 'rec_persons_team_phone_unique');
        });

        $this->capsule->schema()->create('rec_employees', function ($t) {
            $t->increments('id');
            $t->integer('team_id')->nullable();
            $t->integer('rec_applicant_id')->nullable();
            $t->string('person_key', 36)->nullable();
            $t->integer('rec_person_id')->nullable();
            $t->string('phone')->nullable();
            $t->boolean('is_active')->nullable();
            $t->timestamp('zas_changed_at')->nullable();
            $t->timestamps();
        });

        // RecEmployeeExportObserver::trackPayrollChanges() liest die
        // Team-Einstellungen — ohne die Tabelle liefe der Lauf in den
        // stillen Fehlerzweig (safelyRun) und der ZAS-Marker-Test pruefte
        // den Marker, ohne den echten Beobachter-Code gelaufen zu sein.
        $this->capsule->schema()->create('rec_applicant_settings', function ($t) {
            $t->increments('id');
            $t->integer('team_id')->unique();
            $t->text('settings')->nullable();
            $t->timestamps();
        });

        // Der echte Beobachter, nicht seine Abwesenheit, ist die
        // Zusicherung dieser Testklasse.
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

    public function test_stempeln_verbindet_auch_die_person(): void
    {
        DB::table('rec_employees')->insert([
            ['id' => 1, 'team_id' => self::TEAM, 'phone' => '+4915111111111', 'is_active' => 1, 'updated_at' => '2026-01-01 00:00:00'],
            ['id' => 2, 'team_id' => self::TEAM, 'phone' => '+4915111111111', 'is_active' => 1, 'updated_at' => '2026-06-01 00:00:00'],
        ]);

        PersonPairLinker::stamp([1, 2], null);

        $ids = DB::table('rec_employees')->orderBy('id')->pluck('rec_person_id')->map(fn ($v) => (int) $v)->all();
        $this->assertNotSame(0, $ids[0], 'ein frisch gepaartes Paar ohne Personen-Zeile fiele in den alten Zweig zurueck');
        $this->assertSame($ids[0], $ids[1]);
        $this->assertSame(1, (int) DB::table('rec_persons')->count());
    }

    public function test_stempeln_benutzt_eine_schon_vorhandene_personen_zeile(): void
    {
        // Anstellung 1 muss existieren, BEVOR verbinde() sie verbindet --
        // sonst trifft dessen whereIn()-Update null Zeilen und der spaetere
        // stamp()-Aufruf kann die Verbindung nirgendwo wiederfinden (kein
        // Beleg mehr in rec_employees). Der Brief-Testkoerper liess diese
        // Zeile aus; ohne sie waere der Fall gar nicht der "gemischte
        // Liste"-Fall aus PersonLinker::verbinde(), sondern ein reiner
        // Telefonnummern-Zufallstreffer -- den weist Ruling T3-A bewusst ab
        // (geteiltes Handy, andere Person), verbinde() legte also eine
        // ZWEITE Zeile ohne Nummer an.
        DB::table('rec_employees')->insert([
            ['id' => 1, 'team_id' => self::TEAM, 'phone' => '+4915111111111', 'is_active' => 1, 'updated_at' => '2026-01-01 00:00:00'],
        ]);
        $personId = PersonLinker::verbinde([1], self::TEAM, '+4915111111111');
        DB::table('rec_employees')->insert([
            ['id' => 2, 'team_id' => self::TEAM, 'phone' => '+4915111111111', 'is_active' => 1, 'updated_at' => '2026-06-01 00:00:00'],
        ]);

        PersonPairLinker::stamp([1, 2], null);

        $this->assertSame(1, (int) DB::table('rec_persons')->count(), 'es darf keine zweite Zeile entstehen');
        $this->assertSame($personId, (int) DB::table('rec_employees')->where('id', 2)->value('rec_person_id'));
    }

    public function test_stempeln_setzt_weiterhin_keinen_zas_marker(): void
    {
        DB::table('rec_employees')->insert([
            ['id' => 1, 'team_id' => self::TEAM, 'phone' => '+4915111111111', 'is_active' => 1, 'updated_at' => '2026-01-01 00:00:00', 'zas_changed_at' => null],
            ['id' => 2, 'team_id' => self::TEAM, 'phone' => '+4915111111111', 'is_active' => 1, 'updated_at' => '2026-06-01 00:00:00', 'zas_changed_at' => null],
        ]);

        PersonPairLinker::stamp([1, 2], null);

        $this->assertSame(0, (int) DB::table('rec_employees')->whereNotNull('zas_changed_at')->count());

        // Zweitbeleg (dasselbe Muster wie PersonLinkerTest, Nachtrag zu I4):
        // rec_person_id steht NICHT in RecEmployeeExportObserver::
        // RELEVANT_EMPLOYEE_FIELDS — ein Wechsel auf Eloquent wuerde den
        // ZAS-Marker also selbst mit dem echten Beobachter oben nicht
        // setzen, und die Zusicherung darueber bliebe gruen, obwohl der
        // observer-freie Schreibweg verlassen wurde. updated_at dagegen
        // fasst ein Eloquent-Schreibweg automatisch an, ein
        // DB::table()->update() nur auf ausdrueckliche Anweisung — das
        // macht diesen Beleg unabhaengig von der Feldliste.
        $this->assertSame(
            '2026-01-01 00:00:00',
            (string) DB::table('rec_employees')->where('id', 1)->value('updated_at'),
            'ein Eloquent-Schreibweg haette updated_at automatisch angefasst, ein DB::table()-Update nicht',
        );
        $this->assertSame(
            '2026-06-01 00:00:00',
            (string) DB::table('rec_employees')->where('id', 2)->value('updated_at'),
            'ein Eloquent-Schreibweg haette updated_at automatisch angefasst, ein DB::table()-Update nicht',
        );
    }

    public function test_die_juengste_nummer_landet_an_der_person(): void
    {
        DB::table('rec_employees')->insert([
            ['id' => 1, 'team_id' => self::TEAM, 'phone' => '+4915111111111', 'is_active' => 1, 'updated_at' => '2026-01-01 00:00:00'],
            ['id' => 2, 'team_id' => self::TEAM, 'phone' => '+4915122222222', 'is_active' => 1, 'updated_at' => '2026-06-01 00:00:00'],
        ]);

        PersonPairLinker::stamp([1, 2], null);

        $this->assertSame(
            '+4915122222222',
            DB::table('rec_persons')->value('phone'),
            'dieselbe Regel wie im Backfill — sie darf nicht zweimal verschieden existieren',
        );
    }

    /** Ruling T6-A, C1: zwei verschiedene Personen werden zusammengelegt statt zu scheitern. */
    public function test_stempeln_legt_verschiedene_personen_zusammen(): void
    {
        DB::table('rec_employees')->insert([
            ['id' => 1, 'team_id' => self::TEAM, 'phone' => '+4915111111111', 'is_active' => 1, 'updated_at' => '2026-01-01 00:00:00'],
            ['id' => 2, 'team_id' => self::TEAM, 'phone' => '+4915122222222', 'is_active' => 1, 'updated_at' => '2026-06-01 00:00:00'],
            // Dritte Anstellung an derselben (Verlierer-)Person wie 2 --
            // Beleg, dass fuehreZusammen() ALLE ihre Anstellungen umhaengt,
            // nicht nur die beiden aus dem gestempelten Paar.
            ['id' => 3, 'team_id' => self::TEAM, 'phone' => '+4915122222222', 'is_active' => 1, 'updated_at' => '2026-06-01 00:00:00'],
        ]);

        $sieger = PersonLinker::verbinde([1], self::TEAM, '+4915111111111');
        $verlierer = PersonLinker::verbinde([2, 3], self::TEAM, '+4915122222222');
        $this->assertNotSame($sieger, $verlierer);

        PersonPairLinker::stamp([1, 2], null);

        $ids = DB::table('rec_employees')->orderBy('id')->pluck('rec_person_id')->map(fn ($v) => (int) $v)->all();
        $this->assertSame(
            [$sieger, $sieger, $sieger],
            $ids,
            'alle drei Anstellungen muessen jetzt am Sieger haengen, nicht nur das gestempelte Paar',
        );

        $verliererZeile = DB::table('rec_persons')->where('id', $verlierer)->first();
        $this->assertNotNull($verliererZeile, 'die Verlierer-Zeile bleibt stehen (Canvas 1793), wird nicht geloescht');
        $this->assertSame($sieger, (int) $verliererZeile->merged_into_person_id);
        $this->assertSame(2, (int) DB::table('rec_persons')->count(), 'beide Zeilen bleiben stehen, nur eine ist noch lebend');
    }

    /** Ruling T6-A: der bisherige Fall (keine oder schon dieselbe Person) bleibt unveraendert. */
    public function test_stempeln_ohne_bestehende_person_bleibt_unveraendert(): void
    {
        DB::table('rec_employees')->insert([
            ['id' => 1, 'team_id' => self::TEAM, 'phone' => '+4915111111111', 'is_active' => 1, 'updated_at' => '2026-01-01 00:00:00'],
            ['id' => 2, 'team_id' => self::TEAM, 'phone' => '+4915111111111', 'is_active' => 1, 'updated_at' => '2026-06-01 00:00:00'],
        ]);

        PersonPairLinker::stamp([1, 2], null);
        // Ein zweiter Stempel-Lauf auf demselben, schon verbundenen Paar
        // (dieselbe Person auf beiden Seiten) darf ebenfalls nicht in den
        // Zusammenlege-Zweig laufen.
        PersonPairLinker::stamp([1, 2], null);

        $this->assertSame(1, (int) DB::table('rec_persons')->count());
    }

    /**
     * Nachstellung des echten Ablaufs (Ruling T6-A): der Backfill gibt
     * jedem markerlosen Datensatz seine EIGENE Personen-Zeile, lange bevor
     * das Audit-Kommando das Paar findet -- genau die Reihenfolge, in der
     * recruiting:person-pair-audit --apply in der Produktion laeuft. Ohne
     * den Zusammenlege-Zweig wirft dieser Testfall.
     */
    public function test_backfill_dann_audit_scheitert_nicht(): void
    {
        DB::table('rec_employees')->insert([
            ['id' => 1, 'team_id' => self::TEAM, 'phone' => '+4915111111111', 'is_active' => 1, 'updated_at' => '2026-01-01 00:00:00'],
            ['id' => 2, 'team_id' => self::TEAM, 'phone' => '+4915122222222', 'is_active' => 1, 'updated_at' => '2026-06-01 00:00:00'],
        ]);

        // Backfill: PersonGroupPlanner gruppiert nur bei gemeinsamem,
        // nicht-leerem person_key -- zwei markerlose Datensaetze landen
        // also in zwei getrennten Gruppen und bekommen zwei Personen.
        $mitarbeiter = DB::table('rec_employees')->orderBy('id')
            ->get(['id', 'person_key', 'phone', 'updated_at'])
            ->map(fn ($m) => [
                'id'         => (int) $m->id,
                'person_key' => $m->person_key,
                'phone'      => $m->phone,
                'updated_at' => $m->updated_at,
            ])
            ->all();
        $plan = PersonGroupPlanner::plan($mitarbeiter);
        $this->assertCount(2, $plan['gruppen'], 'zwei markerlose Datensaetze -- zwei eigene Gruppen, exakt wie im Backfill');
        foreach ($plan['gruppen'] as $gruppe) {
            PersonLinker::verbinde($gruppe['ids'], self::TEAM, $gruppe['phone']);
        }
        $this->assertSame(2, (int) DB::table('rec_persons')->count());

        // Das Audit-Kommando findet das Paar erst jetzt -- stamp() darf
        // NICHT werfen.
        PersonPairLinker::stamp([1, 2], null);

        $ids = DB::table('rec_employees')->orderBy('id')->pluck('rec_person_id')->map(fn ($v) => (int) $v)->all();
        $this->assertSame($ids[0], $ids[1], 'beide haengen jetzt an derselben (zusammengelegten) Person');
        $this->assertSame(
            1,
            (int) DB::table('rec_persons')->whereNull('merged_into_person_id')->count(),
            'genau eine lebende Person bleibt uebrig',
        );
    }

    /** M4, Fixrunde 1: der Team-Tie-Break ist per Test festgenagelt, nicht nur behauptet. */
    public function test_team_der_kleinsten_kennung_gewinnt(): void
    {
        DB::table('rec_employees')->insert([
            ['id' => 1, 'team_id' => self::TEAM, 'phone' => '+4915111111111', 'is_active' => 1, 'updated_at' => '2026-01-01 00:00:00'],
            ['id' => 2, 'team_id' => self::TEAM + 1, 'phone' => '+4915111111111', 'is_active' => 1, 'updated_at' => '2026-06-01 00:00:00'],
        ]);

        PersonPairLinker::stamp([1, 2], null);

        $personId = (int) DB::table('rec_employees')->where('id', 1)->value('rec_person_id');
        $this->assertSame(
            self::TEAM,
            (int) DB::table('rec_persons')->where('id', $personId)->value('team_id'),
            'Team der kleinsten Anstellungs-Kennung gewinnt, nicht die groesste',
        );
    }
}
