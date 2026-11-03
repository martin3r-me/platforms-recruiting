<?php

namespace Platform\Recruiting\Tests\Integration;

use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Jobs\ProcessZasDispoInboundFile;
use Platform\Recruiting\Models\RecZasDispoInboundFile;
use Platform\Recruiting\Services\Zas\Dispo\DispoEmployeeDirectory;
use Platform\Recruiting\Services\Zas\Dispo\DispoReconfirmMarker;
use Platform\Recruiting\Services\Zas\Dispo\ZasDispoBlockSplitter;
use Platform\Recruiting\Services\Zas\Dispo\ZasDispoImportPlanner;
use Platform\Recruiting\Services\Zas\Dispo\ZasDispoWebexportImporter;

/**
 * Hintergrund-Import des ZAS-Dispo-Eingangs (Spec 2026-11-04).
 *
 * Gegen den echten Importer mit allen Migrationen (Muster
 * DispoImportBedarfeTest): entscheidend ist die REIHENFOLGE — eine aeltere
 * Lieferung nach einer neueren wuerde deren Einbuchungen als verschwunden
 * markieren. Der Beweis laeuft ueber genau diese Markierung.
 */
final class ZasDispoInboundJobTest extends TestCase
{
    private Capsule $capsule;

    /** @var array<string,string> stored_path => Dateiinhalt */
    private array $inhalte = [];

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-04 10:00:00');

        $container = Container::getInstance();
        Container::setInstance($container);
        $container->instance('config', new ConfigRepository([
            'recruiting' => ['zas' => ['company_prefix' => '']],
        ]));
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

                    public function get(string $pfad): string
                    {
                        return $this->test->inhaltLesen($pfad);
                    }
                };
            }
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

        $dateien = glob(dirname(__DIR__, 2) . '/database/migrations/*.php');
        sort($dateien);
        foreach ($dateien as $datei) {
            try {
                (require $datei)->up();
            } catch (\Throwable) {
                // welche Migration hier nicht laufen kann, haelt MassenzuweisungGeschlosseneWeltTest fest
            }
        }
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        Model::unsetConnectionResolver();
        Model::clearBootedModels();
        $container = Container::getInstance();
        foreach (['config', 'log', 'filesystem', 'db', 'db.schema'] as $name) {
            $container->forgetInstance($name);
        }
        Facade::clearResolvedInstances();
        parent::tearDown();
    }

    public function inhaltLesen(string $pfad): string
    {
        return $this->inhalte[$pfad] ?? '';
    }

    /** @param list<string> $dsIds Einbuchungen (DS-IDs) am 10.11.2026 in VA RG20407 */
    private function datei(array $dsIds, bool $test = false, ?string $verarbeitet = null): RecZasDispoInboundFile
    {
        static $n = 0;
        $n++;
        $pfad = "zas/dispo/{$n}.csv";

        $zeilen = ['{Dispo}'];
        foreach ($dsIds as $ds) {
            // datum;einsatzfirma_kurz;pnr;einsatz_id;ze;tlp_nr;ds_id;von;bis;status_id;essengeld;taetigkeit;tlp_nr2;verrechnungssatz
            $zeilen[] = "10.11.2026;BHG;RG18424;RG20407;1;;{$ds};10:00;18:00;1;0;Service;;27,49";
        }
        $this->inhalte[$pfad] = implode("\n", $zeilen) . "\n";

        return RecZasDispoInboundFile::create([
            'source'       => 'zas',
            'disk'         => 'local',
            'stored_path'  => $pfad,
            'is_test'      => $test,
            'parse_status' => $verarbeitet === null ? 'viewable' : 'processed',
            'processed_at' => $verarbeitet,
            'notes'        => $verarbeitet === null ? null : json_encode(['blocks_found' => true, 'stats' => ['assignments' => 1]]),
        ]);
    }

    private function lauf(int $ausloeser): void
    {
        (new ProcessZasDispoInboundFile($ausloeser))->handle(new ZasDispoWebexportImporter(
            new ZasDispoBlockSplitter(),
            new ZasDispoImportPlanner(),
            new DispoEmployeeDirectory(),
            new DispoReconfirmMarker(),
        ));
    }

    public function test_verarbeitet_alle_anstehenden_in_id_reihenfolge_auch_wenn_die_juengere_ausloest(): void
    {
        $aeltere = $this->datei(['1001', '1002']);
        $juengere = $this->datei(['1001']);

        $this->lauf($juengere->id);

        $this->assertNotNull($aeltere->fresh()->processed_at, 'aeltere Lieferung verarbeitet');
        $this->assertNotNull($juengere->fresh()->processed_at, 'juengere Lieferung verarbeitet');
        $this->assertSame('processed', $juengere->fresh()->parse_status);

        // Reihenfolge-Beweis: 1002 kam mit der aelteren Datei und fehlt in der
        // juengeren -> verschwunden. Andersherum (juengere zuerst) stuende 1002
        // als frische Einbuchung ohne Markierung da.
        $rows = DB::table('rec_dispo_assignments')->orderBy('ds_ref')->get();
        $this->assertSame(['1001', '1002'], $rows->pluck('ds_ref')->map(fn ($v) => (string) $v)->all());
        $this->assertNull($rows->firstWhere('ds_ref', '1001')->missing_since);
        $this->assertNotNull($rows->firstWhere('ds_ref', '1002')->missing_since, 'aeltere lief VOR der juengeren');
    }

    public function test_liegengebliebene_unterhalb_der_letzten_verarbeiteten_bleiben_liegen(): void
    {
        $alt = $this->datei(['1001', '1002']);          // nie verarbeitet (z. B. Hook-Fehler am 30.10.)
        $this->datei(['1001'], verarbeitet: '2026-11-03 14:51:00');
        $neu = $this->datei(['1001', '1003']);

        $this->lauf($neu->id);

        $this->assertNull($alt->fresh()->processed_at, 'alte Datei wird NICHT nachgeholt (wuerde Neueres ueberschreiben)');
        $this->assertNotNull($neu->fresh()->processed_at);
        $this->assertSame(['1001', '1003'], DB::table('rec_dispo_assignments')->orderBy('ds_ref')->pluck('ds_ref')->map(fn ($v) => (string) $v)->all());
    }

    public function test_testdateien_werden_nicht_angefasst(): void
    {
        $probe = $this->datei(['1001'], test: true);

        $this->lauf($probe->id);

        $this->assertNull($probe->fresh()->processed_at);
        $this->assertSame(0, DB::table('rec_dispo_assignments')->count());
    }

    public function test_ohne_anstehende_datei_passiert_nichts(): void
    {
        $fertig = $this->datei(['1001'], verarbeitet: '2026-11-04 09:00:00');

        $this->lauf($fertig->id);

        $this->assertSame(0, DB::table('rec_dispo_assignments')->count(), 'verarbeitete Datei laeuft nicht noch einmal');
    }

    public function test_gescheiterte_datei_gilt_als_verarbeitet_und_sperrt_aeltere(): void
    {
        $alt = $this->datei(['1001']);
        $this->datei(['1001'], verarbeitet: '2026-11-04 09:00:00')->update(['parse_status' => 'failed', 'notes' => '{"rolled_back":true}']);

        $this->lauf($alt->id);

        $this->assertNull($alt->fresh()->processed_at, 'failed zaehlt als Abschluss: die aeltere bleibt liegen');
    }

    public function test_hoechstens_max_je_lauf_der_rest_wird_gemeldet(): void
    {
        $a = $this->datei(['1001']);
        $b = $this->datei(['1001']);
        $c = $this->datei(['1001']);

        $rest = ProcessZasDispoInboundFile::verarbeiten($this->importer(), 2);

        $this->assertTrue($rest, 'eine Datei steht noch an');
        $this->assertNotNull($a->fresh()->processed_at);
        $this->assertNotNull($b->fresh()->processed_at);
        $this->assertNull($c->fresh()->processed_at);

        $this->assertFalse(ProcessZasDispoInboundFile::verarbeiten($this->importer(), 2), 'zweiter Lauf raeumt den Rest');
        $this->assertNotNull($c->fresh()->processed_at);
    }

    /**
     * Review 04.11. Fund 1: kann der Importer den Abschluss nicht schreiben
     * (z. B. notes zu lang), darf die Datei nicht alle folgenden blockieren.
     */
    public function test_datei_ohne_abschluss_wird_kompakt_abgeschlossen_und_die_naechste_kommt_dran(): void
    {
        $kaputt = $this->datei(['1001']);
        $danach = $this->datei(['1001', '1002']);

        $importer = new class extends ZasDispoWebexportImporter {
            public int $zaehler = 0;

            public function __construct()
            {
                parent::__construct(new ZasDispoBlockSplitter(), new ZasDispoImportPlanner(), new DispoEmployeeDirectory(), new DispoReconfirmMarker());
            }

            public function import(RecZasDispoInboundFile $file, bool $dryRun = false): array
            {
                $this->zaehler++;
                if ($this->zaehler === 1) {
                    // Daten importiert, aber der Abschluss (processed_at + notes) scheiterte.
                    return ['blocks_found' => true, 'stats' => ['assignments' => 1], 'errors' => array_fill(0, 5000, 'Assignment x: Datum unparsebar'), 'rolled_back' => false];
                }

                return parent::import($file, $dryRun);
            }
        };

        $rest = ProcessZasDispoInboundFile::verarbeiten($importer, 5);

        $this->assertFalse($rest);
        $k = $kaputt->fresh();
        $this->assertNotNull($k->processed_at, 'vom Job nachgezogen');
        $this->assertSame('processed', $k->parse_status);
        $n = $k->notizen();
        $this->assertCount(20, $n['errors'], 'gekuerzt');
        $this->assertSame(5000, $n['errors_gesamt']);
        $this->assertSame(1, $k->einbuchungen());
        $this->assertNotNull($danach->fresh()->processed_at, 'die naechste Datei kam trotzdem dran');
        $this->assertSame(2, DB::table('rec_dispo_assignments')->count());
    }

    public function test_datei_die_sich_gar_nicht_abschliessen_laesst_wird_uebersprungen(): void
    {
        $kaputt = $this->datei(['1001']);
        $danach = $this->datei(['1001', '1002']);

        $importer = new class($kaputt->id) extends ZasDispoWebexportImporter {
            public function __construct(private int $kaputtId)
            {
                parent::__construct(new ZasDispoBlockSplitter(), new ZasDispoImportPlanner(), new DispoEmployeeDirectory(), new DispoReconfirmMarker());
            }

            public function import(RecZasDispoInboundFile $file, bool $dryRun = false): array
            {
                if ($file->id === $this->kaputtId) {
                    // Importer-Abschluss gescheitert, Datei bleibt ohne processed_at.
                    return ['blocks_found' => false, 'errors' => []];
                }

                return parent::import($file, $dryRun);
            }
        };
        // Auch das kompakte Nachziehen scheitert: jedes Update dieser Zeile wird abgewiesen.
        DB::statement("CREATE TRIGGER kaputt_bleibt BEFORE UPDATE ON rec_zas_dispo_inbound_files WHEN NEW.id = {$kaputt->id} BEGIN SELECT RAISE(ABORT, 'Data too long'); END");

        $this->assertFalse(ProcessZasDispoInboundFile::verarbeiten($importer, 5));
        $this->assertNull($kaputt->fresh()->processed_at, 'bleibt wirklich offen');
        $this->assertNotNull($danach->fresh()->processed_at, 'kein Endlos-Lauf, die naechste kam dran');
        $this->assertNull(ProcessZasDispoInboundFile::naechste(), 'danach liegt die kaputte unterhalb der letzten verarbeiteten — wird nicht mehr angefasst');
        $this->assertSame([$kaputt->id], ProcessZasDispoInboundFile::liegengebliebene()->pluck('id')->all());
    }

    public function test_sperre_und_warteschleife_sind_gesetzt(): void
    {
        $job = new ProcessZasDispoInboundFile(1);
        $mw = $job->middleware();

        $this->assertCount(1, $mw);
        $this->assertInstanceOf(\Illuminate\Queue\Middleware\WithoutOverlapping::class, $mw[0]);
        $this->assertSame(15, $mw[0]->releaseAfter, 'wartet in kurzen Schleifen statt zu verfallen');
        $this->assertGreaterThan($job->timeout, $mw[0]->expiresAfter, 'Sperre ueberlebt den laengsten Lauf');
        $this->assertGreaterThanOrEqual(60, $job->tries, 'genug Schleifen fuer mehrere Lieferungen hintereinander');
        $this->assertSame(ProcessZasDispoInboundFile::sperrSchluessel(), $mw[0]->getLockKey($job), 'Befehl und Job teilen sich die Sperre');
        $this->assertGreaterThan(ProcessZasDispoInboundFile::MAX_JE_LAUF * 150, $job->timeout, 'fuenf volle Lieferungen passen in einen Lauf');
    }

    private function importer(): ZasDispoWebexportImporter
    {
        return new ZasDispoWebexportImporter(
            new ZasDispoBlockSplitter(),
            new ZasDispoImportPlanner(),
            new DispoEmployeeDirectory(),
            new DispoReconfirmMarker(),
        );
    }
}
