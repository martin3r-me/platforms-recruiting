<?php

namespace Platform\Recruiting\Tests\Integration;

use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Models\RecPhase;
use Platform\Recruiting\Models\RecPosition;

/**
 * Die Phase, die eine neu angelegte Stelle automatisch mitbekommt, darf nicht
 * "durch Felder abschliessbar" sein.
 *
 * Hintergrund (Bewerber #3342 live): RecPosition::created() legt fuer jede
 * Stelle, die ohne eigene Phasen entsteht, eine Phase "Bewerbung" an — ohne
 * Felder. Die Spalten-Vorgabe von completion_type ist 'fields' (Migration
 * 2026_04_28_000001), und calculateProgress() liefert bei NULL Pflichtfeldern
 * 100. Eine solche Phase gilt damit in der Sekunde als abgeschlossen, in der
 * ein Bewerber sie betritt: Abschluss-Hooks feuern (dort haengt der
 * Geburtsdatum-Riegel → HR-Schreibtisch) und der Legacy-Zweig "letzte Phase
 * ⇒ Terminlink senden" schickt eine Terminbuchung an jemanden, von dem wir
 * keinen einzigen Datenpunkt haben.
 *
 * Mit 'manual' kann diese Phase nichts von sich aus abschliessen — sie wartet,
 * bis jemand Felder konfiguriert oder HR sie weiterschaltet. Das ist auch das,
 * was die Stellen-Anlage ueber die UI heute schon tut (Phase 57 der Stelle
 * "Teamleiter / Serviceleiter", am 01.09.2026 angelegt, steht auf 'manual') —
 * der programmatische Weg zieht damit nur nach.
 *
 * Der Test braucht einen Event-Dispatcher, weil created() sonst nicht feuert,
 * und ein clearBootedModels(): Eloquents Boot-Cache ist statisch, und eine
 * frueher laufende Testklasse kann RecPosition schon OHNE Dispatcher gebootet
 * haben (siehe den Hinweis zur Testreihenfolge in phpunit.xml).
 */
class NeueStelleManuellePhaseTest extends TestCase
{
    private const TEAM = 10;

    public static function setUpBeforeClass(): void
    {
        $container = Container::getInstance();
        $container->instance('config', new ConfigRepository(['activity-log' => ['events' => []]]));

        $dispatcher = new \Illuminate\Events\Dispatcher($container);
        $container->instance('events', $dispatcher);
        $container->instance('log', new class {
            public function __call(string $name, array $args): void
            {
            }
        });

        $capsule = new Capsule();
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $capsule->setEventDispatcher($dispatcher);
        $capsule->setAsGlobal();
        $capsule->bootEloquent();

        $container->instance('db', $capsule->getDatabaseManager());
        $container->instance('db.schema', $capsule->getConnection()->getSchemaBuilder());
        Facade::setFacadeApplication($container);
        Facade::clearResolvedInstance('log');

        // Boot-Cache leeren, DAMIT RecPosition mit unserem Dispatcher bootet und
        // created() feuert — ohne das haengt es an der Ausfuehrungsreihenfolge.
        Model::clearBootedModels();
        Model::unguard();

        $schema = $capsule->getConnection()->getSchemaBuilder();
        $schema->create('teams', fn ($table) => $table->id());
        $schema->create('users', fn ($table) => $table->id());
        $schema->create('hcm_job_titles', fn ($table) => $table->id());
        $schema->create('comms_channels', fn ($table) => $table->id());

        self::runRealMigrations();
    }

    public static function tearDownAfterClass(): void
    {
        Facade::clearResolvedInstances();
        Model::clearBootedModels();
    }

    public function test_die_mitgelieferte_phase_kann_nicht_von_selbst_abschliessen(): void
    {
        $position = RecPosition::create([
            'team_id' => self::TEAM,
            'title' => 'Frisch angelegte Stelle',
            'is_active' => true,
        ]);

        $phasen = RecPhase::where('rec_position_id', $position->id)->get();

        $this->assertCount(1, $phasen, 'genau eine Phase wird mitgeliefert');

        $phase = $phasen->first();

        $this->assertSame('Bewerbung', $phase->name);
        $this->assertSame(
            'manual',
            $phase->completion_type,
            'ohne Felder darf die Phase nicht ueber Felder abschliessbar sein — sonst '
            . 'gilt sie sofort als fertig und schickt einen Terminlink'
        );
    }

    /**
     * Gegenprobe zum Sinn der Regel: die mitgelieferte Phase hat wirklich keine
     * Felddefinitionen. Genau deshalb waere 'fields' hier eine Falle — der Test
     * oben wuerde sonst nur eine Vorliebe festschreiben.
     */
    public function test_die_mitgelieferte_phase_hat_keine_felder(): void
    {
        $position = RecPosition::create([
            'team_id' => self::TEAM,
            'title' => 'Zweite frisch angelegte Stelle',
            'is_active' => true,
        ]);

        $phase = RecPhase::where('rec_position_id', $position->id)->first();

        $anzahl = \Platform\Core\Models\CoreExtraFieldDefinition::query()
            ->where('context_type', RecPhase::class)
            ->where('context_id', $phase->id)
            ->count();

        $this->assertSame(0, $anzahl, 'die mitgelieferte Phase fragt nichts ab');
    }

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
}
