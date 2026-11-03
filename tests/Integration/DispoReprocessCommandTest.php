<?php

namespace Platform\Recruiting\Tests\Integration;

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use Illuminate\Console\OutputStyle;
use Platform\Recruiting\Console\Commands\DispoReprocessCommand;
use Platform\Recruiting\Jobs\ProcessZasDispoInboundFile;
use Platform\Recruiting\Models\RecZasDispoInboundFile;
use Platform\Recruiting\Services\Zas\Dispo\DispoEmployeeDirectory;
use Platform\Recruiting\Services\Zas\Dispo\DispoReconfirmMarker;
use Platform\Recruiting\Services\Zas\Dispo\ZasDispoBlockSplitter;
use Platform\Recruiting\Services\Zas\Dispo\ZasDispoImportPlanner;
use Platform\Recruiting\Services\Zas\Dispo\ZasDispoWebexportImporter;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * recruiting:dispo-reprocess nach dem Umbau auf den Hintergrund-Import
 * (Review 04.11. Fund 2): ohne ID dieselbe Regel und dieselbe Sperre wie der
 * Job, Liegengebliebene werden nur genannt; mit ID bewusst alles.
 */
final class DispoReprocessCommandTest extends TestCase
{
    /** @var array<string,string> stored_path => Dateiinhalt */
    private array $inhalte = [];

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-04 10:00:00');

        $container = Container::getInstance();
        Container::setInstance($container);
        $container->instance('config', new ConfigRepository(['recruiting' => ['zas' => ['company_prefix' => '']]]));
        $container->instance('log', new class {
            public function __call($m, $a) {}
        });
        $container->instance('cache', new CacheRepository(new ArrayStore()));

        $test = $this;
        $container->instance('filesystem', new class($test) {
            public function __construct(private object $test) {}

            public function disk($name = null): object
            {
                return new class($this->test) {
                    public function __construct(private object $test) {}

                    public function get(string $pfad): string
                    {
                        return $this->test->inhaltLesen($pfad);
                    }
                };
            }
        });

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

        $dateien = glob(dirname(__DIR__, 2) . '/database/migrations/*.php');
        sort($dateien);
        foreach ($dateien as $datei) {
            try {
                (require $datei)->up();
            } catch (\Throwable) {
            }
        }
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        Model::unsetConnectionResolver();
        Model::clearBootedModels();
        $container = Container::getInstance();
        foreach (['config', 'log', 'filesystem', 'db', 'db.schema', 'cache'] as $name) {
            $container->forgetInstance($name);
        }
        Facade::clearResolvedInstances();
        parent::tearDown();
    }

    public function inhaltLesen(string $pfad): string
    {
        return $this->inhalte[$pfad] ?? '';
    }

    /** @param list<string> $dsIds */
    private function datei(array $dsIds, ?string $verarbeitet = null): RecZasDispoInboundFile
    {
        static $n = 0;
        $n++;
        $pfad = "zas/dispo/{$n}.csv";
        $zeilen = ['{Dispo}'];
        foreach ($dsIds as $ds) {
            $zeilen[] = "10.11.2026;BHG;RG18424;RG20407;1;;{$ds};10:00;18:00;1;0;Service;;27,49";
        }
        $this->inhalte[$pfad] = implode("\n", $zeilen) . "\n";

        return RecZasDispoInboundFile::create([
            'source' => 'zas', 'disk' => 'local', 'stored_path' => $pfad, 'is_test' => false,
            'parse_status' => $verarbeitet === null ? 'viewable' : 'processed',
            'processed_at' => $verarbeitet,
            'notes'        => $verarbeitet === null ? null : '{"blocks_found":true,"stats":{"assignments":1}}',
        ]);
    }

    /**
     * handle() direkt mit gesetzter Ein-/Ausgabe — run() braucht den vollen
     * Laravel-Kern (Prompts, runningUnitTests), den die Capsule-Suite nicht hat.
     *
     * @return array{0:int, 1:string} Exit-Code, Ausgabe
     */
    private function befehl(array $args = []): array
    {
        $cmd = new class extends DispoReprocessCommand {
            public function einrichten(ArrayInput $in, OutputStyle $out): void
            {
                $this->input = $in;
                $this->output = $out;
            }
        };
        $in = new ArrayInput($args, $cmd->getDefinition());
        $buffer = new BufferedOutput();
        $cmd->einrichten($in, new OutputStyle($in, $buffer));

        $code = $cmd->handle(new ZasDispoWebexportImporter(
            new ZasDispoBlockSplitter(),
            new ZasDispoImportPlanner(),
            new DispoEmployeeDirectory(),
            new DispoReconfirmMarker(),
        ));

        return [$code, $buffer->fetch()];
    }

    public function test_ohne_id_nur_anstehende_liegengebliebene_werden_genannt(): void
    {
        $alt = $this->datei(['1001', '1002']);
        $this->datei(['1001'], '2026-11-03 14:51:00');
        $neu = $this->datei(['1001', '1003']);

        [$code, $out] = $this->befehl();

        $this->assertSame(0, $code);
        $this->assertStringContainsString("Liegengeblieben unterhalb der letzten verarbeiteten Datei (#2): #{$alt->id}", $out);
        $this->assertStringContainsString("#{$neu->id} ", $out);
        $this->assertNull($alt->fresh()->processed_at, 'aelter als der Bestand: nicht angefasst');
        $this->assertNotNull($neu->fresh()->processed_at);
        $this->assertSame(['1001', '1003'], DB::table('rec_dispo_assignments')->orderBy('ds_ref')->pluck('ds_ref')->map(fn ($v) => (string) $v)->all());
    }

    public function test_ohne_id_bricht_ab_wenn_der_job_gerade_laeuft(): void
    {
        $neu = $this->datei(['1001']);
        $this->assertTrue(Cache::lock(ProcessZasDispoInboundFile::sperrSchluessel(), 60)->get(), 'Sperre des Jobs gesetzt');

        [$code, $out] = $this->befehl();

        $this->assertSame(1, $code);
        $this->assertStringContainsString('laeuft gerade', $out);
        $this->assertNull($neu->fresh()->processed_at);
    }

    public function test_mit_id_wird_auch_eine_liegengebliebene_bewusst_verarbeitet(): void
    {
        $alt = $this->datei(['1001', '1002']);
        $this->datei(['1001'], '2026-11-03 14:51:00');

        [$code, $out] = $this->befehl(['fileId' => (string) $alt->id]);

        $this->assertSame(0, $code);
        $this->assertStringContainsString("#{$alt->id} ", $out);
        $this->assertNotNull($alt->fresh()->processed_at);
    }

    public function test_dry_run_ohne_id_schreibt_nichts(): void
    {
        $neu = $this->datei(['1001']);

        [$code, $out] = $this->befehl(['--dry-run' => true]);

        $this->assertSame(0, $code);
        $this->assertStringContainsString('[DRY-RUN]', $out);
        $this->assertStringContainsString('geplant: 1 Events, 1 Einbuchungen', $out);
        $this->assertNull($neu->fresh()->processed_at);
        $this->assertSame(0, DB::table('rec_dispo_assignments')->count());
    }
}
