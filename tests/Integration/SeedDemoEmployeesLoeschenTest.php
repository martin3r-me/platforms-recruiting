<?php

namespace Platform\Recruiting\Tests\Integration;

use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Console\Commands\SeedDemoEmployees;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * I2 (Fixrunde 1, Aufgabe 7, Pruefer-Auftrag): --loeschen ist die einzige
 * Stelle im gesamten Personen-Klammer-Zweig, an der ueberhaupt etwas
 * geloescht wird -- und sie steht in einem Kommando, das neben echten
 * Menschen laufen kann. Eine Verhaltensweise, die nur per Lesen fuer sicher
 * erklaert wird, ist nicht dasselbe wie eine gepruefte. Bewusst NICHT die
 * volle anlegen()/handle()-Suite: nur die zwei Faelle, bei denen ein Fehler
 * wehtaete --
 *
 *  1. eine Personen-Zeile mit einem Testmenschen UND einem echten
 *     Mitarbeiter -- der echte Mensch und seine Personen-Zeile muessen nach
 *     --loeschen unangetastet stehen bleiben;
 *  2. eine Personen-Zeile mit ausschliesslich Testmenschen -- die muss weg
 *     sein, sonst haeuft sich bei jedem Neuaufbau Muell an.
 *
 * Aufbau nach dem Muster BackfillPersonsTest/SwitchPortalVersionTest:
 * handgebaute Capsule + SQLite, echtes Command::run() (nicht handle()
 * direkt, sonst liefe die Options-Erkennung --team/--loeschen nie mit).
 * Zusaetzlich zu jenem Muster: SeedDemoEmployees liest in darfHier() ueber
 * den globalen config()-Helper config('app.url') fuer die Produktions-
 * Sperre -- ohne eine gebundene 'config'-Instanz waere der Lauf schon dort
 * gescheitert, bevor er die eigentliche Loesch-Logik erreicht.
 */
final class SeedDemoEmployeesLoeschenTest extends TestCase
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

        // darfHier() liest config('app.url') fuer die Produktions-Sperre.
        // Capsule bindet 'config' beim Aufbau bereits SELBST (als Fluent,
        // fuer database.default/database.connections) -- ein eigenes
        // instance('config', ...) wuerde diese Bindung ERSETZEN und damit
        // die gerade registrierte Verbindung wieder wegkonfigurieren
        // ("Database connection [] not configured"). Deshalb wird hier nur
        // ein zusaetzlicher Schluessel auf der bestehenden Instanz gesetzt.
        // Eine lokale Adresse laesst das Kommando durch, ohne die Sperre
        // selbst anzufassen oder zu umgehen -- istProduktion() bleibt
        // unveraendert und entscheidet hier genauso wie ueberall sonst.
        $container['config']['app'] = ['url' => 'http://localhost'];

        Facade::setFacadeApplication($container);
        Facade::clearResolvedInstances();

        $this->capsule->schema()->create('rec_persons', function ($t) {
            $t->increments('id');
            $t->string('uuid', 64)->unique();
            $t->integer('team_id')->nullable();
            $t->string('phone', 32)->nullable();
            $t->timestamps();
        });

        $this->capsule->schema()->create('rec_employees', function ($t) {
            $t->increments('id');
            $t->string('uuid', 64)->nullable();
            $t->integer('team_id')->nullable();
            $t->string('first_name')->nullable();
            $t->string('last_name')->nullable();
            $t->string('personnel_number')->nullable();
            $t->string('portal_token')->nullable();
            $t->string('phone')->nullable();
            $t->boolean('is_active')->nullable();
            $t->integer('rec_person_id')->nullable();
            $t->timestamps();
        });

        $this->capsule->schema()->create('rec_employee_proofs', function ($t) {
            $t->increments('id');
            $t->integer('rec_employee_id')->nullable();
            $t->string('proof_type_code')->nullable();
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

    private function loeschen(): void
    {
        $command = new SeedDemoEmployees();
        $command->setLaravel(new SeedDemoEmployeesFakeLaravel());

        $input = new ArrayInput(['--team' => (string) self::TEAM, '--loeschen' => true], $command->getDefinition());
        $output = new BufferedOutput();

        $command->run($input, $output);
    }

    public function test_geteilte_personen_zeile_bleibt_wenn_ein_echter_mitarbeiter_noch_dranhaengt(): void
    {
        $personId = DB::table('rec_persons')->insertGetId([
            'uuid'       => 'p-geteilt',
            'team_id'    => self::TEAM,
            'created_at' => '2026-01-01 00:00:00',
            'updated_at' => '2026-01-01 00:00:00',
        ]);

        $echterId = DB::table('rec_employees')->insertGetId([
            'team_id'          => self::TEAM,
            'first_name'       => 'Echt',
            'last_name'        => 'Mensch',
            'personnel_number' => '00042',
            'rec_person_id'    => $personId,
            'is_active'        => 1,
            'created_at'       => '2026-01-01 00:00:00',
            'updated_at'       => '2026-01-01 00:00:00',
        ]);

        $testId = DB::table('rec_employees')->insertGetId([
            'team_id'          => self::TEAM,
            'first_name'       => 'Gregor',
            'last_name'        => 'Doppelt',
            'personnel_number' => 'DEMO-01',
            'rec_person_id'    => $personId,
            'is_active'        => 1,
            'created_at'       => '2026-01-01 00:00:00',
            'updated_at'       => '2026-01-01 00:00:00',
        ]);

        $this->loeschen();

        $this->assertNotNull(
            DB::table('rec_employees')->where('id', $echterId)->first(),
            'der echte Mitarbeiter darf durch --loeschen nicht verschwinden',
        );
        $this->assertSame(
            $personId,
            (int) DB::table('rec_employees')->where('id', $echterId)->value('rec_person_id'),
            'seine Personen-Zuordnung darf sich nicht aendern',
        );
        $this->assertNotNull(
            DB::table('rec_persons')->where('id', $personId)->first(),
            'eine geteilte Personen-Zeile darf nicht verschwinden, solange noch ein echter Mensch dranhaengt',
        );
        $this->assertNull(
            DB::table('rec_employees')->where('id', $testId)->first(),
            'der Testmensch selbst muss weg sein',
        );
    }

    public function test_personen_zeile_mit_ausschliesslich_testmenschen_verschwindet(): void
    {
        $personId = DB::table('rec_persons')->insertGetId([
            'uuid'       => 'p-nur-test',
            'team_id'    => self::TEAM,
            'created_at' => '2026-01-01 00:00:00',
            'updated_at' => '2026-01-01 00:00:00',
        ]);

        DB::table('rec_employees')->insertGetId([
            'team_id'          => self::TEAM,
            'first_name'       => 'Gregor',
            'last_name'        => 'Doppelt',
            'personnel_number' => 'DEMO-02',
            'rec_person_id'    => $personId,
            'is_active'        => 1,
            'created_at'       => '2026-01-01 00:00:00',
            'updated_at'       => '2026-01-01 00:00:00',
        ]);

        $this->loeschen();

        $this->assertNull(
            DB::table('rec_persons')->where('id', $personId)->first(),
            'eine Personen-Zeile, an der nur Testmenschen haengen, muss beim Loeschen mit verschwinden',
        );
    }
}

/**
 * Minimaler Ersatz fuer die volle Laravel-Application (Muster
 * BackfillPersonsFakeLaravel/SwitchPortalVersionFakeLaravel):
 * Illuminate\Console\Command::run() braucht runningUnitTests(), sonst
 * nichts.
 */
final class SeedDemoEmployeesFakeLaravel extends Container
{
    public function runningUnitTests(): bool
    {
        return true;
    }
}
