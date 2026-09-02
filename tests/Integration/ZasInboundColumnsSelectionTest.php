<?php

namespace Platform\Recruiting\Tests\Integration;

use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Console\Commands\ZasInboundColumns;
use Platform\Recruiting\Models\RecZasInboundFile;
use Platform\Recruiting\Services\Zas\ZasColumnProfiler;
use Platform\Recruiting\Services\Zas\ZasInboundColumnReport;
use Platform\Recruiting\Services\Zas\ZasInboundCsvParser;

/**
 * Welche Lieferungen der Spalten-Bericht heranzieht.
 *
 * Der Rest des Commands ist pure Logik und im Unit-Test abgedeckt; hier geht es
 * allein um die Auswahl gegen die echte Tabelle — die Stelle, an der ein
 * falscher Spaltenname oder eine falsche Sortierung dazu fuehrt, dass jemand
 * eine Aussage ueber die falsche Lieferung trifft.
 */
class ZasInboundColumnsSelectionTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        $container = Container::getInstance();
        $container->instance('config', new ConfigRepository([
            'activity-log' => ['events' => []],
        ]));

        $dispatcher = new \Illuminate\Events\Dispatcher($container);
        $container->instance('events', $dispatcher);
        $container->instance('log', new class {
            public function __call(string $name, array $args): void
            {
            }
        });
        Facade::setFacadeApplication($container);
        Facade::clearResolvedInstance('log');

        $capsule = new Capsule();
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $capsule->setEventDispatcher($dispatcher);
        $capsule->setAsGlobal();
        $capsule->bootEloquent();
        $container->instance('db', $capsule->getDatabaseManager());

        Model::clearBootedModels();
        Model::unguard();

        Capsule::schema()->create('rec_zas_inbound_files', function ($t) {
            $t->increments('id');
            $t->string('uuid')->nullable();
            $t->string('original_filename')->nullable();
            $t->string('disk')->nullable();
            $t->string('stored_path')->nullable();
            $t->integer('row_count')->nullable();
            $t->boolean('is_test')->default(false);
            $t->string('status')->default('received');
            $t->dateTime('processed_at')->nullable();
            $t->text('notes')->nullable();
            $t->timestamps();
        });
    }

    public static function tearDownAfterClass(): void
    {
        Capsule::schema()->dropAllTables();
        Facade::clearResolvedInstances();
    }

    protected function setUp(): void
    {
        Capsule::table('rec_zas_inbound_files')->delete();

        // Zwei Echt-Lieferungen und dazwischen eine Testlieferung — die
        // Reihenfolge der IDs ist der Punkt der Tests unten.
        RecZasInboundFile::create(['disk' => 'local', 'stored_path' => 'a.csv', 'row_count' => 100, 'is_test' => false]);
        RecZasInboundFile::create(['disk' => 'local', 'stored_path' => 'b.csv', 'row_count' => 5,   'is_test' => true]);
        RecZasInboundFile::create(['disk' => 'local', 'stored_path' => 'c.csv', 'row_count' => 20,  'is_test' => false]);
    }

    private function command(): ZasInboundColumns
    {
        return new ZasInboundColumns(
            new ZasInboundColumnReport(new ZasColumnProfiler(), new ZasInboundCsvParser())
        );
    }

    public function test_without_arguments_it_takes_the_newest_real_delivery(): void
    {
        $files = $this->command()->filesFor(null, false);

        $this->assertCount(1, $files);
        $this->assertSame('c.csv', $files->first()->stored_path);
    }

    public function test_all_takes_every_real_delivery_oldest_first_and_skips_tests(): void
    {
        $files = $this->command()->filesFor(null, true);

        // Testlieferungen wuerden den Fuellgrad verzerren (drei Zeilen
        // Handarbeit gegen 800 echte) — deshalb bleiben sie draussen.
        $this->assertSame(['a.csv', 'c.csv'], $files->pluck('stored_path')->all());
    }

    public function test_an_explicit_id_wins_even_for_a_test_delivery(): void
    {
        $test = RecZasInboundFile::where('stored_path', 'b.csv')->firstOrFail();

        $files = $this->command()->filesFor((int) $test->id, false);

        $this->assertCount(1, $files);
        $this->assertSame('b.csv', $files->first()->stored_path);
    }

    public function test_an_unknown_id_yields_nothing(): void
    {
        // handle() macht daraus "Keine passende Lieferung gefunden" statt
        // stillschweigend die neueste zu nehmen.
        $this->assertCount(0, $this->command()->filesFor(999999, false));
    }
}
