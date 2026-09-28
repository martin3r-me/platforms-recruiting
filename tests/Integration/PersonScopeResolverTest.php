<?php

namespace Platform\Recruiting\Tests\Integration;

use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Models\RecEmployee;
use Platform\Recruiting\Services\PersonScopeResolver;

/**
 * PersonScopeResolver ist der Kern der Stufe: welche Anstellungen gehoeren
 * zu diesem Menschen? Drei Lesestellen (PortalShell, ProofReader,
 * ProofWriter) verlassen sich auf diese eine Antwort.
 *
 * Die beiden ersten Testfaelle unten teilen sich absichtlich dasselbe
 * Datenbild (gleicher person_key, keine Telefonnummern) und unterscheiden
 * sich nur in der gesetzten rec_person_id. Genau dieses Nebeneinander
 * belegt, dass die Spalte die Luecke schliesst, an der der alte
 * Telefonnummer-Abgleich im Demo-Bestand scheitert.
 */
final class PersonScopeResolverTest extends TestCase
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
    }

    protected function tearDown(): void
    {
        Model::unsetConnectionResolver();
        Model::clearBootedModels();
        Facade::clearResolvedInstances();
        parent::tearDown();
    }

    public function test_mit_personen_zeile_zaehlen_alle_anstellungen_dieser_person(): void
    {
        $personId = DB::table('rec_persons')->insertGetId([
            'uuid' => 'p-1', 'team_id' => self::TEAM,
            'created_at' => '2026-09-28 10:00:00', 'updated_at' => '2026-09-28 10:00:00',
        ]);
        DB::table('rec_employees')->insert([
            // Bewusst OHNE Telefonnummern: genau der Demo-Fall, an dem der alte Weg scheitert.
            ['id' => 1, 'team_id' => self::TEAM, 'person_key' => 'k-1', 'rec_person_id' => $personId, 'phone' => null, 'is_active' => 1],
            ['id' => 2, 'team_id' => self::TEAM, 'person_key' => 'k-1', 'rec_person_id' => $personId, 'phone' => null, 'is_active' => 1],
        ]);

        $r = (new PersonScopeResolver())->forEmployee(RecEmployee::query()->find(1));

        $this->assertSame([1, 2], $r['ids'], 'die Spalte entscheidet, nicht die Telefonnummer');
        $this->assertSame([], $r['abweichend'], 'eine entschiedene Zuordnung kennt keinen Zweifelsfall mehr');
    }

    public function test_ohne_personen_zeile_gilt_weiter_der_alte_weg(): void
    {
        DB::table('rec_employees')->insert([
            ['id' => 1, 'team_id' => self::TEAM, 'person_key' => 'k-1', 'rec_person_id' => null, 'phone' => '+4915112345678', 'is_active' => 1],
            ['id' => 2, 'team_id' => self::TEAM, 'person_key' => 'k-1', 'rec_person_id' => null, 'phone' => '+4915112345678', 'is_active' => 1],
        ]);

        $r = (new PersonScopeResolver())->forEmployee(RecEmployee::query()->find(1));

        $this->assertSame([1, 2], $r['ids'], 'noch nicht gebackfillte Zeilen muessen weiter funktionieren');
    }

    public function test_ohne_personen_zeile_und_ohne_nummer_bleibt_der_zweifelsfall(): void
    {
        DB::table('rec_employees')->insert([
            ['id' => 1, 'team_id' => self::TEAM, 'person_key' => 'k-1', 'rec_person_id' => null, 'phone' => null, 'is_active' => 1],
            ['id' => 2, 'team_id' => self::TEAM, 'person_key' => 'k-1', 'rec_person_id' => null, 'phone' => null, 'is_active' => 1],
        ]);

        $r = (new PersonScopeResolver())->forEmployee(RecEmployee::query()->find(1));

        $this->assertSame([1], $r['ids'], 'zwei leere Nummern bestaetigen sich nicht (PersonProofScope)');
        $this->assertSame([2], $r['abweichend']);
    }
}
