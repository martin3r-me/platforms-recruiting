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

/**
 * PersonLinker ist der einzige Schreiber von rec_employees.rec_person_id.
 * Diese Tests beweisen vor allem die Stellen, an denen ein stiller Fehler
 * NICHT auffallen wuerde, wenn man ihn nie hat scheitern sehen: keinen
 * ZAS-Marker setzen, die Nummer auf alle Anstellungen ziehen, und beim
 * Zusammenlegen weder an einen Geist noch in einen Ring haengen.
 *
 * Der ZAS-Marker-Test registriert den ECHTEN RecEmployeeExportObserver
 * gegen den handgebauten Dispatcher (Fixrunde 1, Befund I4) — sonst
 * beweist der Test nur, dass es in diesem Testlauf gar keinen Beobachter
 * gibt, nicht dass PersonLinker ihn observer-frei umgeht.
 */
final class PersonLinkerTest extends TestCase
{
    private const TEAM = 3;

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
            $t->string('password_hash')->nullable();
            $t->string('email')->nullable();
            $t->timestamp('invited_at')->nullable();
            $t->timestamp('registered_at')->nullable();
            $t->timestamp('locked_at')->nullable();
            $t->integer('merged_into_person_id')->nullable();
            $t->timestamps();

            // Derselbe Eindeutigkeits-Index wie in der echten Migration —
            // Ruling T3-A haengt genau an dieser Kollision.
            $t->unique(['team_id', 'phone'], 'rec_persons_team_phone_unique');
        });

        $this->capsule->schema()->create('rec_employees', function ($t) {
            $t->increments('id');
            $t->integer('team_id')->nullable();
            $t->string('first_name')->nullable();
            $t->string('last_name')->nullable();
            $t->string('person_key', 64)->nullable();
            $t->integer('rec_person_id')->nullable();
            $t->string('company')->nullable();
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

        // Der echte Beobachter, nicht seine Abwesenheit, ist die Zusicherung
        // dieser Testklasse (Fixrunde 1, I4).
        RecEmployeeExportObserver::register();

        // Die Testfaelle setzen zwei bestehende Anstellungen voraus.
        DB::table('rec_employees')->insert([
            ['id' => 1, 'team_id' => self::TEAM, 'first_name' => 'Gregor', 'last_name' => 'Erste',
             'company' => 'RG', 'is_active' => 1, 'zas_changed_at' => '2026-09-28 09:00:00',
             'created_at' => '2026-09-28 09:00:00', 'updated_at' => '2026-09-28 09:00:00'],
            ['id' => 2, 'team_id' => self::TEAM, 'first_name' => 'Gregor', 'last_name' => 'Zweite',
             'company' => 'MA', 'is_active' => 1, 'zas_changed_at' => '2026-09-28 09:00:00',
             'created_at' => '2026-09-28 09:00:00', 'updated_at' => '2026-09-28 09:00:00'],
        ]);
    }

    protected function tearDown(): void
    {
        Model::unsetConnectionResolver();
        Model::clearBootedModels();
        Container::getInstance()->forgetInstance('log');
        Facade::clearResolvedInstances();
        parent::tearDown();
    }

    public function test_verbinden_legt_eine_zeile_an_und_haengt_beide_an(): void
    {
        $personId = PersonLinker::verbinde([1, 2], self::TEAM, '+4915112345678');

        $this->assertSame(1, (int) DB::table('rec_persons')->count());
        $this->assertSame(
            [$personId, $personId],
            DB::table('rec_employees')->orderBy('id')->pluck('rec_person_id')->map(fn ($v) => (int) $v)->all(),
        );
    }

    public function test_verbinden_benutzt_eine_vorhandene_zeile_statt_einer_zweiten(): void
    {
        $erst = PersonLinker::verbinde([1], self::TEAM, '+4915112345678');
        $zweit = PersonLinker::verbinde([1, 2], self::TEAM, '+4915112345678');

        $this->assertSame($erst, $zweit);
        $this->assertSame(1, (int) DB::table('rec_persons')->count());
    }

    public function test_verbinden_weigert_sich_bei_zwei_verschiedenen_personen(): void
    {
        $a = PersonLinker::verbinde([1], self::TEAM, null);
        $b = PersonLinker::verbinde([2], self::TEAM, null);
        $this->assertNotSame($a, $b);

        try {
            PersonLinker::verbinde([1, 2], self::TEAM, null);
            $this->fail('erwartete InvalidArgumentException blieb aus');
        } catch (\InvalidArgumentException $e) {
            // erwartet
        }

        // I5 (Fixrunde 1): nicht nur die Ausnahme zaehlt, sondern dass VOR
        // ihr nichts geschrieben wurde. Ein Pruefblock nach dem Update
        // wuerde die Ausnahme werfen, nachdem der Schaden schon passiert ist.
        $this->assertSame(
            $a,
            (int) DB::table('rec_employees')->where('id', 1)->value('rec_person_id'),
            'Anstellung 1 darf nach der Weigerung nicht umgehaengt worden sein',
        );
        $this->assertSame(
            $b,
            (int) DB::table('rec_employees')->where('id', 2)->value('rec_person_id'),
            'Anstellung 2 darf nach der Weigerung nicht umgehaengt worden sein',
        );
    }

    public function test_verbinden_weigert_sich_bei_leerer_liste(): void
    {
        // I1 (Fixrunde 1): ohne diese Wache entstuende eine Personen-Zeile MIT
        // der Nummer, an keiner Anstellung — die Nummer waere fuer den
        // echten Menschen verbrannt (Ruling T3-A weist sie ihm dann ab).
        try {
            PersonLinker::verbinde([], self::TEAM, '+4915112345678');
            $this->fail('erwartete InvalidArgumentException blieb aus');
        } catch (\InvalidArgumentException $e) {
            // erwartet
        }

        $this->assertSame(
            0,
            (int) DB::table('rec_persons')->count(),
            'eine leere Anstellungsliste darf keine Personen-Zeile anlegen',
        );
    }

    public function test_verbinden_setzt_keinen_zas_marker(): void
    {
        DB::table('rec_employees')->where('id', 1)->update(['zas_changed_at' => null]);

        PersonLinker::verbinde([1], self::TEAM, '+4915112345678');

        $this->assertNull(
            DB::table('rec_employees')->where('id', 1)->value('zas_changed_at'),
            'die Zuordnung darf niemanden in die updates.csv spuelen',
        );

        // Feld-unabhaengiger Zweitbeleg (Fixrunde 1, Nachtrag zu I4):
        // rec_person_id steht NICHT in RecEmployeeExportObserver::
        // RELEVANT_EMPLOYEE_FIELDS — ein Wechsel auf Eloquent wuerde den
        // ZAS-Marker also selbst mit dem oben registrierten ECHTEN
        // Beobachter nicht setzen, und die Zusicherung oben bliebe grün,
        // obwohl der observer-freie Schreibweg verlassen wurde. updated_at
        // dagegen fasst JEDES Eloquent-save() automatisch an, ein
        // DB::table()->update() nur, wenn man es explizit mitgibt — das
        // macht diesen Beleg unabhaengig von der Feldliste.
        $this->assertSame(
            '2026-09-28 09:00:00',
            (string) DB::table('rec_employees')->where('id', 1)->value('updated_at'),
            'ein Eloquent-Schreibweg haette updated_at automatisch angefasst, ein DB::table()-Update nicht',
        );
    }

    public function test_zusammenfuehren_legt_still_statt_zu_loeschen(): void
    {
        $sieger = PersonLinker::verbinde([1], self::TEAM, '+4915111111111');
        $verlierer = PersonLinker::verbinde([2], self::TEAM, '+4915122222222');
        DB::table('rec_persons')->where('id', $verlierer)
            ->update(['password_hash' => 'geheim', 'registered_at' => '2026-09-01 10:00:00']);

        PersonLinker::fuehreZusammen($sieger, $verlierer);

        $zeile = DB::table('rec_persons')->where('id', $verlierer)->first();
        $this->assertSame($sieger, (int) $zeile->merged_into_person_id);
        $this->assertNull($zeile->password_hash, 'die Anmeldung der Verlierer-Zeile muss weg sein');
        $this->assertNull($zeile->registered_at);
        $this->assertSame('+4915122222222', $zeile->phone, 'die Nummer bleibt gesperrt (Canvas 1793)');

        $this->assertSame(
            [$sieger, $sieger],
            DB::table('rec_employees')->orderBy('id')->pluck('rec_person_id')->map(fn ($v) => (int) $v)->all(),
        );
    }

    /** C1 (Fixrunde 1), Wache 0: unveraendert, jetzt aber mit eigenem Test. */
    public function test_zusammenfuehren_weigert_sich_bei_gleicher_person(): void
    {
        $person = PersonLinker::verbinde([1], self::TEAM, null);

        $this->expectException(\InvalidArgumentException::class);

        PersonLinker::fuehreZusammen($person, $person);
    }

    /**
     * C1, Wache 1 — belegt: ohne sie haengt die Anstellung an einem Geist
     * (rec_person_id zeigt auf eine Zeile, die es nicht gibt).
     */
    public function test_zusammenfuehren_weigert_sich_wenn_sieger_fehlt(): void
    {
        $verlierer = PersonLinker::verbinde([1], self::TEAM, null);

        try {
            PersonLinker::fuehreZusammen(999999, $verlierer);
            $this->fail('erwartete InvalidArgumentException blieb aus');
        } catch (\InvalidArgumentException $e) {
            // erwartet
        }

        $this->assertSame(
            $verlierer,
            (int) DB::table('rec_employees')->where('id', 1)->value('rec_person_id'),
            'ohne existierenden Sieger darf die Anstellung nicht umgehaengt werden',
        );
    }

    /** C1, Wache 3. */
    public function test_zusammenfuehren_weigert_sich_wenn_verlierer_fehlt(): void
    {
        $sieger = PersonLinker::verbinde([1], self::TEAM, null);

        $this->expectException(\InvalidArgumentException::class);

        PersonLinker::fuehreZusammen($sieger, 999999);
    }

    /** C1, Wache 4. */
    public function test_zusammenfuehren_weigert_sich_bei_unterschiedlichen_teams(): void
    {
        $sieger = PersonLinker::verbinde([1], self::TEAM, null);
        $verliererAnderesTeam = DB::table('rec_persons')->insertGetId([
            'uuid' => 'p-anderes-team', 'team_id' => self::TEAM + 1,
            'created_at' => '2026-09-28 10:00:00', 'updated_at' => '2026-09-28 10:00:00',
        ]);

        $this->expectException(\InvalidArgumentException::class);

        PersonLinker::fuehreZusammen($sieger, $verliererAnderesTeam);
    }

    /**
     * C1, Wache 2 — belegt: das Ring-Szenario aus dem Review. Ohne diese
     * Wache zeigen nach dem zweiten Aufruf beide Zeilen aufeinander und der
     * Mensch kann sich nie mehr anmelden.
     */
    public function test_zusammenfuehren_weigert_sich_wenn_sieger_selbst_stillgelegt_ist(): void
    {
        $a = PersonLinker::verbinde([1], self::TEAM, null);
        $b = PersonLinker::verbinde([2], self::TEAM, null);

        PersonLinker::fuehreZusammen($b, $a); // a ist jetzt stillgelegt, zeigt auf b

        $this->expectException(\InvalidArgumentException::class);

        PersonLinker::fuehreZusammen($a, $b); // wuerde ohne Wache einen Ring erzeugen
    }

    public function test_loesen_gibt_der_anstellung_eine_neue_person(): void
    {
        $gemeinsam = PersonLinker::verbinde([1, 2], self::TEAM, '+4915112345678');

        $neu = PersonLinker::loese(2);

        $this->assertNotSame($gemeinsam, $neu);
        $this->assertSame($gemeinsam, (int) DB::table('rec_employees')->where('id', 1)->value('rec_person_id'));
        $this->assertSame($neu, (int) DB::table('rec_employees')->where('id', 2)->value('rec_person_id'));
    }

    public function test_nummer_wandert_auf_alle_anstellungen(): void
    {
        $personId = PersonLinker::verbinde([1, 2], self::TEAM, '+4915111111111');

        PersonLinker::setzeNummer($personId, '+4915199999999');

        $this->assertSame('+4915199999999', DB::table('rec_persons')->where('id', $personId)->value('phone'));
        $this->assertSame(
            ['+4915199999999', '+4915199999999'],
            DB::table('rec_employees')->orderBy('id')->pluck('phone')->all(),
            'sonst kommt der Einmalcode auf einer anderen Nummer an als die Anmeldung',
        );
    }

    /**
     * Ruling T3-A: der Eindeutigkeits-Index laesst pro Team nur eine Person
     * mit einer bestimmten Nummer zu. Im Bestand teilen sich aber zwei
     * verschiedene Menschen manchmal ein Handy — verbinde() darf dann nicht
     * sterben, sondern muss die Zeile ohne Nummer anlegen.
     */
    public function test_geteilte_nummer_erzeugt_zweite_zeile_ohne_nummer(): void
    {
        $erste = PersonLinker::verbinde([1], self::TEAM, '+4915112345678');

        $zweite = PersonLinker::verbinde([2], self::TEAM, '+4915112345678');

        $this->assertNotSame($erste, $zweite, 'zwei verschiedene Menschen bekommen zwei Personen-Zeilen');
        $this->assertSame(
            '+4915112345678',
            DB::table('rec_persons')->where('id', $erste)->value('phone'),
            'die zuerst angelegte Zeile behaelt die Nummer',
        );
        $this->assertNull(
            DB::table('rec_persons')->where('id', $zweite)->value('phone'),
            'ein geteiltes Handy darf den Lauf nicht toeten, aber auch keine zweite Zeile mit derselben Nummer erzeugen',
        );
    }

    /**
     * Ruling T3-B (Fixrunde 1, I2): PersonGroupPlanner vergleicht Nummern
     * formatunabhaengig (PhoneE164::suffix()), reicht aber den rohen Wert
     * weiter. Ohne eigene Normalisierung waeren "+4915112345678" und
     * "015112345678" fuer PersonLinker zwei verschiedene Nummern gewesen —
     * die Kollision aus Ruling T3-A haette nicht gegriffen.
     */
    public function test_verbinden_normalisiert_nummern_vor_dem_vergleich(): void
    {
        $erste = PersonLinker::verbinde([1], self::TEAM, '+4915112345678');
        $zweite = PersonLinker::verbinde([2], self::TEAM, '015112345678'); // dieselbe Nummer, andere Schreibweise

        $this->assertNotSame($erste, $zweite, 'zwei verschiedene Menschen bekommen zwei Personen-Zeilen');
        $this->assertSame(
            '+4915112345678',
            DB::table('rec_persons')->where('id', $erste)->value('phone'),
        );
        $this->assertNull(
            DB::table('rec_persons')->where('id', $zweite)->value('phone'),
            'ohne Normalisierung waere die Kollision unentdeckt geblieben und beide Zeilen haetten dieselbe echte Nummer getragen',
        );
    }

    public function test_verbinden_speichert_die_normalisierte_form(): void
    {
        $personId = PersonLinker::verbinde([1], self::TEAM, '015112345678');

        $this->assertSame(
            '+4915112345678',
            DB::table('rec_persons')->where('id', $personId)->value('phone'),
            'gespeichert wird die E.164-Form, nicht die Rohschreibweise — sonst driftet der Index gegen den Vergleich',
        );
    }

    /**
     * PhoneE164::normalize() liefert null bei unlesbaren Nummern. Das darf
     * denselben Weg gehen wie eine Kollision: Zeile ohne Nummer, kein Fehler.
     */
    public function test_verbinden_mit_unlesbarer_nummer_legt_zeile_ohne_nummer_an(): void
    {
        $personId = PersonLinker::verbinde([1], self::TEAM, 'nicht-lesbar');

        $this->assertNull(
            DB::table('rec_persons')->where('id', $personId)->value('phone'),
            'eine unlesbare Nummer darf den Lauf nicht toeten, aber auch keinen Muellwert speichern',
        );
    }

    /**
     * I1 (Schlusspruefung): loese() weist ausdruecklich an, danach
     * setzeNummer() zu rufen. Wer der Anweisung folgt und dieselbe Nummer
     * uebergibt, bekam bisher eine rohe UniqueConstraintViolationException
     * aus dem Index. Jetzt benennt setzeNummer() den Fall — bewusst anders
     * als verbinde(), das die Nummer stillschweigend weglaesst.
     */
    public function test_setze_nummer_benennt_die_schon_vergebene_nummer(): void
    {
        $gemeinsam = PersonLinker::verbinde([1, 2], self::TEAM, '+4915112345678');
        $neuePerson = PersonLinker::loese(2);

        try {
            PersonLinker::setzeNummer($neuePerson, '+4915112345678');
            $this->fail('setzeNummer() haette den Fall benennen muessen');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('gehoert im Team', $e->getMessage());
            $this->assertStringContainsString((string) $gemeinsam, $e->getMessage(), 'die Meldung muss sagen, WEM die Nummer gehoert');
        }

        $this->assertNull(
            DB::table('rec_persons')->where('id', $neuePerson)->value('phone'),
            'ein abgewiesener Aufruf darf nichts halb geschrieben haben',
        );
    }

    /**
     * Die Pruefung muss auf der normalisierten Form arbeiten (Ruling T3-B) —
     * sonst rutscht "015..." am Vergleich vorbei und stirbt danach am Index.
     */
    public function test_setze_nummer_erkennt_die_vergebene_nummer_auch_in_anderer_schreibweise(): void
    {
        PersonLinker::verbinde([1], self::TEAM, '+4915112345678');
        $zweite = PersonLinker::verbinde([2], self::TEAM, null);

        $this->expectException(\InvalidArgumentException::class);

        PersonLinker::setzeNummer($zweite, '015112345678');
    }

    /**
     * Gegenprobe zur Wache: die EIGENE Nummer noch einmal zu setzen ist kein
     * Konflikt — sonst waere jede Wiederholung ein Fehler.
     */
    public function test_setze_nummer_darf_die_eigene_nummer_wiederholen(): void
    {
        $personId = PersonLinker::verbinde([1, 2], self::TEAM, '+4915112345678');

        PersonLinker::setzeNummer($personId, '+4915112345678');

        $this->assertSame('+4915112345678', DB::table('rec_persons')->where('id', $personId)->value('phone'));
    }

    /**
     * I2 (Schlusspruefung): nach dem Loesen behauptete der person_key weiter
     * "derselbe Mensch", waehrend rec_person_id "zwei Menschen" sagte. Der
     * Fall war danach mit keinem Werkzeug mehr auffindbar (der Backfill
     * sieht nur Gruppen mit ungebundenen Mitgliedern, das Audit ueberspringt
     * Gruppen mit gemeinsamem Marker). Zwei Zustaende, die dasselbe
     * behaupten sollen, duerfen nicht auseinanderlaufen.
     */
    public function test_loesen_raeumt_den_person_key_mit_ab(): void
    {
        DB::table('rec_employees')->whereIn('id', [1, 2])->update(['person_key' => 'gemeinsamer-marker']);
        $gemeinsam = PersonLinker::verbinde([1, 2], self::TEAM, '+4915112345678');

        $neuePerson = PersonLinker::loese(2);

        $this->assertNull(
            DB::table('rec_employees')->where('id', 2)->value('person_key'),
            'der Marker der geloesten Anstellung muss mit abgeraeumt werden',
        );
        $this->assertSame(
            'gemeinsamer-marker',
            DB::table('rec_employees')->where('id', 1)->value('person_key'),
            'die zurueckbleibende Anstellung behaelt ihren Marker',
        );
        $this->assertSame($gemeinsam, (int) DB::table('rec_employees')->where('id', 1)->value('rec_person_id'));
        $this->assertSame($neuePerson, (int) DB::table('rec_employees')->where('id', 2)->value('rec_person_id'));
    }

    /**
     * Das zusaetzliche Abraeumen des Markers laeuft im selben Update — es
     * darf den Weg NICHT auf Eloquent umbiegen, sonst spuelt jedes Loesen
     * den Menschen in die naechste ZAS-Update-Datei. Zweitbeleg ueber
     * updated_at, weil ein Eloquent-save() es automatisch anfasst, ein
     * DB::table()-Update nur auf Anweisung.
     */
    public function test_loesen_setzt_keinen_zas_marker(): void
    {
        DB::table('rec_employees')->whereIn('id', [1, 2])->update([
            'person_key' => 'gemeinsamer-marker',
            'zas_changed_at' => null,
        ]);
        PersonLinker::verbinde([1, 2], self::TEAM, '+4915112345678');

        PersonLinker::loese(2);

        $this->assertSame(
            0,
            (int) DB::table('rec_employees')->whereNotNull('zas_changed_at')->count(),
            'ein HR-Rueckweg darf niemanden in die updates.csv spuelen',
        );
        $this->assertSame(
            '2026-09-28 09:00:00',
            (string) DB::table('rec_employees')->where('id', 2)->value('updated_at'),
            'ein Eloquent-Schreibweg haette updated_at automatisch angefasst, der Query Builder nicht',
        );
    }
}
