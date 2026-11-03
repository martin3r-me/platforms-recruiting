<?php

namespace Platform\Recruiting\Tests\Integration;

use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Events\Dispatcher;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Http\Controllers\ZasDispoInboundController;
use Platform\Recruiting\Jobs\ProcessZasDispoInboundFile;
use Platform\Recruiting\Models\RecZasDispoInboundFile;

/**
 * Eingangs-Endpunkt antwortet sofort und reiht die Verarbeitung ein
 * (Spec 2026-11-04). Vorfall 03.11.: der Import lief in der Anfrage, Olafs
 * Client brach nach seinem Zeitlimit ab und meldete einen Fehler fuer eine
 * laengst angekommene Datei.
 */
final class ZasDispoInboundControllerTest extends TestCase
{
    /** @var list<object> eingereihte Jobs */
    private array $eingereiht = [];

    /** @var array<string,string> gespeicherte Dateien */
    private array $gespeichert = [];

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-04 10:00:00');

        $container = Container::getInstance();
        $container->instance('config', new ConfigRepository(['recruiting' => ['zas' => ['inbound_disk' => 'local']]]));
        $container->instance('log', new class {
            public function __call($m, $a) {}
        });

        $test = $this;
        $container->instance('filesystem', new class($test) {
            public function __construct(private object $test) {}

            public function disk($name = null): object
            {
                return new class($this->test) {
                    public function __construct(private object $test) {}

                    public function put(string $pfad, string $inhalt): bool
                    {
                        $this->test->merken($pfad, $inhalt);

                        return true;
                    }
                };
            }
        });
        $container->instance(\Illuminate\Contracts\Bus\Dispatcher::class, new class($test) {
            public function __construct(private object $test) {}

            public function dispatch($job): void
            {
                $this->test->einreihen($job);
            }
        });
        // response()->json() braucht weder View noch Redirector.
        $container->bind(\Illuminate\Contracts\Routing\ResponseFactory::class, fn () => (new \ReflectionClass(\Illuminate\Routing\ResponseFactory::class))->newInstanceWithoutConstructor());

        $capsule = new Capsule($container);
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $capsule->setEventDispatcher(new Dispatcher($container));
        $capsule->setAsGlobal();
        $capsule->bootEloquent();
        Model::clearBootedModels();
        $container->instance('db', $capsule->getDatabaseManager());
        $container->instance('db.schema', $capsule->getConnection()->getSchemaBuilder());
        Facade::setFacadeApplication($container);
        Facade::clearResolvedInstances();

        $basis = dirname(__DIR__, 2) . '/database/migrations/';
        (require $basis . '2026_08_06_000001_create_rec_zas_dispo_inbound_files_table.php')->up();
        (require $basis . '2026_08_12_000003_add_processed_at_to_rec_zas_dispo_inbound_files.php')->up();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        Model::unsetConnectionResolver();
        Model::clearBootedModels();
        $container = Container::getInstance();
        foreach (['config', 'log', 'filesystem', 'db', 'db.schema', \Illuminate\Contracts\Bus\Dispatcher::class] as $name) {
            $container->forgetInstance($name);
        }
        Facade::clearResolvedInstances();
        parent::tearDown();
    }

    public function merken(string $pfad, string $inhalt): void
    {
        $this->gespeichert[$pfad] = $inhalt;
    }

    private bool $einreihenScheitert = false;

    public function einreihen(object $job): void
    {
        if ($this->einreihenScheitert) {
            throw new \RuntimeException('jobs-Tabelle nicht erreichbar');
        }
        $this->eingereiht[] = $job;
    }

    private function senden(string $body, string $query = ''): array
    {
        $request = Request::create('/recruiting/zas/dispo-inbound' . $query, 'POST', [], [], [], ['CONTENT_TYPE' => 'text/plain'], $body);
        $response = (new ZasDispoInboundController())($request);
        // PendingDispatch reiht erst beim Zerstoeren ein.
        gc_collect_cycles();

        return [$response->getStatusCode(), json_decode($response->getContent(), true)];
    }

    private const LIEFERUNG = "{Dispo}\n10.11.2026;BHG;RG18424;RG20407;1;;1001;10:00;18:00;1;0;Service;;27,49\n";

    public function test_echte_lieferung_wird_gespeichert_eingereiht_und_sofort_quittiert(): void
    {
        [$status, $json] = $this->senden(self::LIEFERUNG);

        $this->assertSame(201, $status);
        $this->assertSame('received', $json['status']);
        $this->assertSame('queued', $json['processing']);
        $this->assertArrayNotHasKey('import', $json, 'kein synchroner Import mehr in der Antwort');

        $datei = RecZasDispoInboundFile::query()->sole();
        $this->assertSame($json['id'], $datei->id);
        $this->assertNull($datei->processed_at, 'Verarbeitung passiert spaeter im Job');
        $this->assertSame('viewable', $datei->parse_status);
        $this->assertTrue($datei->wartet(0));
        $this->assertSame(self::LIEFERUNG, $this->gespeichert[$datei->stored_path] ?? null, 'Rohdatei 1:1 abgelegt');

        $this->assertCount(1, $this->eingereiht);
        $this->assertInstanceOf(ProcessZasDispoInboundFile::class, $this->eingereiht[0]);
        $this->assertSame($datei->id, $this->eingereiht[0]->fileId);
    }

    /** Review 04.11. Fund 5: Einreihen scheitert -> Datei bleibt gespeichert, Antwort bleibt 201, Status sagt es. */
    public function test_scheitert_das_einreihen_bleibt_die_datei_gespeichert_und_die_antwort_201(): void
    {
        $this->einreihenScheitert = true;

        [$status, $json] = $this->senden(self::LIEFERUNG);

        $this->assertSame(201, $status);
        $this->assertSame('received', $json['status']);
        $this->assertSame('not_queued', $json['processing']);
        $datei = RecZasDispoInboundFile::query()->sole();
        $this->assertTrue($datei->wartet(0), 'der naechste Job nimmt sie mit');
        $this->assertSame([], $this->eingereiht);
    }

    public function test_probe_wird_nicht_eingereiht(): void
    {
        [$status, $json] = $this->senden(self::LIEFERUNG, '?dry_run=true');

        $this->assertSame(201, $status);
        $this->assertTrue($json['is_test']);
        $this->assertSame('none', $json['processing']);
        $this->assertSame([], $this->eingereiht);
        $this->assertFalse(RecZasDispoInboundFile::query()->sole()->wartet(0));
    }

    public function test_leerer_body_bleibt_422(): void
    {
        [$status, $json] = $this->senden('');

        $this->assertSame(422, $status);
        $this->assertSame('error', $json['status']);
        $this->assertSame([], $this->eingereiht);
        $this->assertSame(0, RecZasDispoInboundFile::query()->count());
    }
}
