<?php

namespace Platform\Recruiting\Tests\Integration;

use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Models\RecPerson;

/**
 * Der Mensch als eigene Zeile: seine Anstellungen zeigen auf ihn, nicht
 * umgekehrt. Und wer beim Zusammenlegen verloren hat, sagt das selbst.
 */
final class RecPersonTest extends TestCase
{
    private const TEAM = 3;

    private Capsule $capsule;

    protected function setUp(): void
    {
        parent::setUp();
        $container = Container::getInstance();
        Container::setInstance($container);

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
            // NOT NULL + unique wie in der echten Migration (Zeile 37) — sonst
            // prueft der Test eine schwaechere Garantie als die Produktion.
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

            // Eine Nummer haengt nur an einem Konto (Canvas 68, Eintrag 1740).
            // NULL zaehlt in SQLite (wie in der echten Migration/MySQL) nicht
            // als Kollision — mehrere Personen ohne Nummer sind erlaubt.
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
            $t->boolean('is_active')->nullable();
            $t->string('phone')->nullable();
            $t->timestamps();
        });
    }

    protected function tearDown(): void
    {
        Model::unsetConnectionResolver();
        Model::clearBootedModels();
        Facade::clearResolvedInstances();
        parent::tearDown();
    }

    public function test_eine_person_traegt_beide_anstellungen(): void
    {
        $personId = DB::table('rec_persons')->insertGetId([
            'uuid' => 'p-1', 'team_id' => self::TEAM, 'phone' => '+4915112345678',
            'created_at' => '2026-09-28 10:00:00', 'updated_at' => '2026-09-28 10:00:00',
        ]);

        DB::table('rec_employees')->insert([
            ['id' => 1, 'team_id' => self::TEAM, 'first_name' => 'Gregor', 'last_name' => 'Doppelt',
             'company' => 'RG', 'rec_person_id' => $personId, 'is_active' => 1],
            ['id' => 2, 'team_id' => self::TEAM, 'first_name' => 'Gregor', 'last_name' => 'Doppelt',
             'company' => 'MA', 'rec_person_id' => $personId, 'is_active' => 1],
        ]);

        $person = RecPerson::query()->find($personId);

        $this->assertSame(
            ['MA', 'RG'],
            $person->employees->pluck('company')->sort()->values()->all(),
            'die Person kennt ihre beiden Anstellungen nicht',
        );
    }

    public function test_stillgelegte_person_sagt_es_selbst(): void
    {
        $sieger = DB::table('rec_persons')->insertGetId([
            'uuid' => 'p-sieger', 'team_id' => self::TEAM,
            'created_at' => '2026-09-28 10:00:00', 'updated_at' => '2026-09-28 10:00:00',
        ]);
        $verlierer = DB::table('rec_persons')->insertGetId([
            'uuid' => 'p-verlierer', 'team_id' => self::TEAM, 'merged_into_person_id' => $sieger,
            'created_at' => '2026-09-28 10:00:00', 'updated_at' => '2026-09-28 10:00:00',
        ]);

        $this->assertFalse(RecPerson::query()->find($sieger)->istStillgelegt());
        $this->assertTrue(RecPerson::query()->find($verlierer)->istStillgelegt());
    }

    /**
     * phone ist der spaetere Benutzername (Stufe 2) — deshalb muss die
     * Datenbank selbst verhindern, dass zwei Personen im selben Team
     * dieselbe Nummer tragen, nicht nur die Anwendung.
     */
    public function test_dieselbe_nummer_im_team_wird_abgewiesen(): void
    {
        DB::table('rec_persons')->insert([
            'uuid' => 'p-nummer-1', 'team_id' => self::TEAM, 'phone' => '+4915112345678',
            'created_at' => '2026-09-28 10:00:00', 'updated_at' => '2026-09-28 10:00:00',
        ]);

        // Laravel wirft bei diesem Fehler die spezifischere Unterklasse von
        // QueryException, keine generische — festgenagelt statt geraten.
        $this->expectException(\Illuminate\Database\UniqueConstraintViolationException::class);
        $this->expectExceptionMessageMatches('/UNIQUE constraint failed: rec_persons\.team_id, rec_persons\.phone/');

        DB::table('rec_persons')->insert([
            'uuid' => 'p-nummer-2', 'team_id' => self::TEAM, 'phone' => '+4915112345678',
            'created_at' => '2026-09-28 10:00:00', 'updated_at' => '2026-09-28 10:00:00',
        ]);
    }

    /**
     * Die Migration behauptet: NULL ist erlaubt und mehrfach moeglich, wer
     * keine Nummer hat bekommt trotzdem eine Zeile, nur kein Konto. Das ist
     * heute unbewiesen — hier wird es belegt.
     */
    public function test_mehrere_personen_ohne_nummer_sind_erlaubt(): void
    {
        DB::table('rec_persons')->insert([
            'uuid' => 'p-ohne-nummer-1', 'team_id' => self::TEAM, 'phone' => null,
            'created_at' => '2026-09-28 10:00:00', 'updated_at' => '2026-09-28 10:00:00',
        ]);
        DB::table('rec_persons')->insert([
            'uuid' => 'p-ohne-nummer-2', 'team_id' => self::TEAM, 'phone' => null,
            'created_at' => '2026-09-28 10:00:00', 'updated_at' => '2026-09-28 10:00:00',
        ]);

        $this->assertSame(
            2,
            DB::table('rec_persons')->where('team_id', self::TEAM)->whereNull('phone')->count(),
            'zwei Personen ohne Nummer im selben Team muessen beide anlegbar sein',
        );
    }
}
