<?php

namespace Platform\Recruiting\Tests\Integration;

use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Services\Zas\ZasInboundDuplicateFinder;
use Platform\Recruiting\Services\Zas\ZasInboundEmployeeImporter;
use Platform\Recruiting\Services\Zas\ZasInboundRowMapper;
use Platform\Recruiting\Services\Zas\ZasLookupReverseResolver;

/**
 * Fehlgeschlagene Zeilen muessen im Log landen.
 *
 * Befund 2026-08-25: der Importer enthielt kein einziges Log::. Jede
 * abgewiesene Zeile existierte nur im JSON der notes-Spalte und in der
 * HTTP-Antwort an ZAS — also genau an zwei Orten, die niemand ansieht bzw. die
 * bei einem Abbruch mitverschwinden. Ein Mensch fehlte im System und es fiel
 * erst Stunden spaeter beim Nachzaehlen auf.
 *
 * Das Log ist die einzige Spur, die auch ueberlebt, wenn der Abschluss-Schreib-
 * vorgang der Lieferung scheitert.
 */
class ZasInboundFailureLoggingTest extends TestCase
{
    /** @var object{records: list<array{level:string, message:string, context:array}>} */
    private static object $log;

    public static function setUpBeforeClass(): void
    {
        $container = Container::getInstance();
        $container->instance('config', new ConfigRepository([
            'recruiting' => ['zas' => ['inbound_team_id' => 3]],
        ]));

        self::$log = new class {
            /** @var list<array{level:string, message:string, context:array}> */
            public array $records = [];

            public function warning(string $message, array $context = []): void
            {
                $this->records[] = ['level' => 'warning', 'message' => $message, 'context' => $context];
            }

            public function __call(string $name, array $args): void
            {
            }
        };

        $container->instance('log', self::$log);
        Facade::setFacadeApplication($container);
        Facade::clearResolvedInstance('log');

        // Eine saubere Zeile laeuft bis findExisting und braucht dafuer eine
        // echte Tabelle — ohne DB wuerde JEDE Zeile mit Personalnummer als
        // SQL-Fehler enden und der "kein Rauschen"-Test waere wertlos.
        $dispatcher = new \Illuminate\Events\Dispatcher($container);
        $container->instance('events', $dispatcher);

        $capsule = new Capsule();
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $capsule->setEventDispatcher($dispatcher);
        $capsule->setAsGlobal();
        $capsule->bootEloquent();
        $container->instance('db', $capsule->getDatabaseManager());

        Model::clearBootedModels();
        Model::unguard();

        Capsule::schema()->create('rec_employees', function ($t) {
            $t->increments('id');
            $t->string('uuid')->nullable();
            $t->string('portal_token')->nullable();
            $t->integer('team_id');
            $t->string('first_name')->nullable();
            $t->string('last_name')->nullable();
            $t->string('email')->nullable();
            $t->string('phone')->nullable();
            $t->string('iban')->nullable();
            $t->string('personnel_number')->nullable();
            $t->boolean('is_active')->default(true);
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
        self::$log->records = [];
    }

    private function importer(): ZasInboundEmployeeImporter
    {
        return new ZasInboundEmployeeImporter(
            new ZasInboundRowMapper(new ZasLookupReverseResolver()),
            new ZasInboundDuplicateFinder()
        );
    }

    public function test_zeile_ohne_personalnummer_wird_geloggt(): void
    {
        $report = $this->importer()->import([['Name' => 'Ohne', 'Vorname' => 'Nummer']], (object) ['id' => 5], false);

        $this->assertCount(1, $report['failed']);
        $this->assertCount(1, self::$log->records, 'jede abgewiesene Zeile gehoert ins Log');
        $this->assertSame('warning', self::$log->records[0]['level']);
        $this->assertStringContainsString('ZAS', self::$log->records[0]['message']);
        $this->assertSame(5, self::$log->records[0]['context']['inbound_file_id']);
    }

    public function test_log_nennt_die_personalnummer_und_den_grund(): void
    {
        $this->importer()->import([[
            'ZasPersonalNr' => '17752',
            '|'             => 'kaputt', // Zeilenende-Marker verschoben
        ]], (object) ['id' => 5], false);

        $context = self::$log->records[0]['context'];
        $this->assertSame('17752', $context['personnel_number']);
        $this->assertStringContainsString('Zeilenende-Marker', $context['reason']);
    }

    public function test_saubere_zeilen_erzeugen_kein_rauschen(): void
    {
        // Nur Fehler gehoeren ins Log. Warnungen stehen im Bericht — sonst
        // schreibt eine 600er-Lieferung tausend Zeilen Log.
        $report = $this->importer()->import([[
            'ZasPersonalNr' => '17944',
            'Fuehrerschein' => str_repeat('X', 40), // erzeugt eine Warnung
        ]], (object) ['id' => 5], true);

        $this->assertNotSame([], $report['warnings']);
        $this->assertSame([], self::$log->records);
    }
}
