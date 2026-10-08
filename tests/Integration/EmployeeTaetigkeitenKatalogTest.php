<?php

namespace Platform\Recruiting\Tests\Integration;

use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Container\Container;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Livewire\Employees\Show;
use Platform\Recruiting\Models\RecEmployee;
use Platform\Recruiting\Services\Zas\ZasDispoTaetigkeitSync;

/**
 * Die MA-Akte zeigt den ganzen ZAS-Katalog mit Haken, damit "kann der
 * Logistik?" beantwortbar ist. Gehakt ist, was ZAS zugewiesen hat — klickbar
 * ist nichts, ZAS ist fuer dieses Feld fuehrend.
 */
class EmployeeTaetigkeitenKatalogTest extends TestCase
{
    private const TEAM = 1101;

    public static function setUpBeforeClass(): void
    {
        $container = Container::getInstance();

        $capsule = new Capsule();
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $capsule->setEventDispatcher(new Dispatcher($container));
        $capsule->setAsGlobal();
        $capsule->bootEloquent();
        Model::unguard();
        Model::clearBootedModels();

        $container->instance('db', $capsule->getDatabaseManager());
        $container->instance('db.schema', $capsule->getConnection()->getSchemaBuilder());
        Facade::setFacadeApplication($container);
        Facade::clearResolvedInstances();
        $container->instance('config', new ConfigRepository([]));

        // EventBus als Singleton: Livewire verdrahtet die Magic-Getter der
        // #[Computed]-Properties ueber ihn; ohne Singleton greift jede
        // Verdrahtung ins Leere (siehe DressTestCase).
        $container->singleton(\Livewire\EventBus::class);

        // auth()->user()->currentTeam->id — die einzige Framework-Abhaengigkeit
        // der Komponente. Attrappe im Container, kein Umbau der Komponente:
        // getestet werden soll der Produktionspfad. Der auth()-Helper hat
        // einen Rueckgabetyp, die Attrappe muss die Schnittstelle wirklich
        // implementieren.
        $container->instance(AuthFactory::class, new class(self::TEAM) implements AuthFactory
        {
            public function __construct(private int $teamId) {}

            public function user(): object
            {
                return new class($this->teamId)
                {
                    public object $currentTeam;

                    public function __construct(int $teamId)
                    {
                        $this->currentTeam = (object) ['id' => $teamId];
                    }
                };
            }

            public function guard($name = null)
            {
                return $this;
            }

            public function shouldUse($name) {}
        });

        self::runMigrations();
    }

    public static function tearDownAfterClass(): void
    {
        Container::getInstance()->forgetInstance('config');
        Container::getInstance()->forgetInstance(\Livewire\EventBus::class);
        Container::getInstance()->forgetInstance(AuthFactory::class);
        Facade::clearResolvedInstances();
    }

    protected function setUp(): void
    {
        foreach (['rec_employees', 'rec_employee_hr_data', 'core_lookups', 'core_lookup_values'] as $t) {
            Capsule::table($t)->delete();
        }
    }

    private static function runMigrations(): void
    {
        $own  = dirname(__DIR__, 2);
        $core = self::packageRootOf(\Platform\Core\Models\CoreLookup::class);

        $files = [
            [$own,  'database/migrations/2026_05_20_000001_create_rec_employees_table.php'],
            [$own,  'database/migrations/2026_05_21_000005_add_zas_export_markers_to_rec_employees.php'],
            [$own,  'database/migrations/2026_05_22_000001_add_personnel_number_to_rec_employees.php'],
            [$own,  'database/migrations/2026_04_15_100000_create_rec_contract_tables.php'],
            [$own,  'database/migrations/2026_10_07_000002_add_employee_anchor_to_contracts.php'],
            [$own,  'database/migrations/2026_05_21_000002_create_rec_employee_hr_data_table.php'],
            [$own,  'database/migrations/2026_05_21_000004_add_linen_package_to_hr_data.php'],
            [$own,  'database/migrations/2026_09_15_000001_add_dispo_taetigkeiten_to_hr_data.php'],
            [$core, 'database/migrations/2026_02_12_000003_create_core_lookups_tables.php'],
        ];

        foreach ($files as [$root, $relative]) {
            $path = $root . '/' . $relative;
            if (!file_exists($path)) {
                throw new \RuntimeException("Migration fehlt: {$path}");
            }
            (require $path)->up();
        }
    }

    private static function packageRootOf(string $class): string
    {
        $file = (new \ReflectionClass($class))->getFileName();
        $dir = dirname((string) $file);
        while ($dir !== '/' && !file_exists($dir . '/composer.json')) {
            $dir = dirname($dir);
        }

        return $dir;
    }

    /**
     * @param list<string> $zugewiesen
     * @param list<string> $katalog
     */
    private function komponente(array $zugewiesen, array $katalog, string $suche = ''): Show
    {
        $employee = RecEmployee::create([
            'team_id' => self::TEAM, 'first_name' => 'E', 'last_name' => 'Muster',
            'personnel_number' => 'RG1464', 'portal_token' => 'tok-1464', 'is_active' => true,
        ]);
        (new ZasDispoTaetigkeitSync())->syncMany([$employee->id => $zugewiesen], $katalog);

        // Baut die Komponente OHNE Livewire-Mount (kein Testbench) und
        // verdrahtet die #[Computed]-Getter von Hand — ohne das wirft jeder
        // Zugriff auf $this->employee eine PropertyNotFoundException.
        $c = new Show();
        $c->employeeId = $employee->id;
        $c->taetigkeitenSuche = $suche;
        ComputedWiring::wire($c);

        return $c;
    }

    public function test_catalogue_marks_what_the_employee_has(): void
    {
        $c = $this->komponente(['Logistiker'], ['Servicekräfte', 'Logistiker', 'Kasse']);

        $this->assertSame(
            [
                ['label' => 'Logistiker', 'hat' => true],
                ['label' => 'Kasse', 'hat' => false],
                ['label' => 'Servicekräfte', 'hat' => false],
            ],
            $c->dispoTaetigkeitenKatalog(),
            'Zugewiesene zuerst, dahinter der Rest alphabetisch.'
        );
    }

    public function test_search_filters_the_catalogue(): void
    {
        $c = $this->komponente(['Logistiker'], ['Servicekräfte', 'Logistiker', 'Kasse'], suche: 'kas');

        $this->assertSame([['label' => 'Kasse', 'hat' => false]], $c->dispoTaetigkeitenKatalog());
    }

    public function test_search_ignores_case_and_surrounding_space(): void
    {
        $c = $this->komponente([], ['Servicekräfte', 'Kasse'], suche: '  KAS ');

        $this->assertSame([['label' => 'Kasse', 'hat' => false]], $c->dispoTaetigkeitenKatalog());
    }

    public function test_employee_without_assignment_still_sees_the_catalogue(): void
    {
        $c = $this->komponente([], ['Servicekräfte', 'Logistiker']);

        $this->assertCount(2, $c->dispoTaetigkeitenKatalog(),
            'Bei 266 Neuzugaengen ohne Zuordnung waere das Fenster sonst leer und unerreichbar.');
        $this->assertSame([false, false], array_column($c->dispoTaetigkeitenKatalog(), 'hat'));
    }

    public function test_assigned_value_missing_from_the_lookup_is_still_shown(): void
    {
        // Zuweisung von Hand setzen, ohne sie in die Auswahlliste zu legen —
        // so sieht es aus, wenn die Liste der Lieferung hinterherhinkt.
        $c = $this->komponente([], ['Servicekräfte']);
        Capsule::table('rec_employee_hr_data')
            ->where('rec_employee_id', $c->employeeId)
            ->update(['dispo_taetigkeiten' => json_encode(['Sonderposten'], JSON_UNESCAPED_UNICODE)]);

        $this->assertSame(
            [['label' => 'Sonderposten', 'hat' => true], ['label' => 'Servicekräfte', 'hat' => false]],
            $c->dispoTaetigkeitenKatalog(),
            'Was der Mitarbeiter hat, verschwindet nie — auch wenn die Liste hinterherhinkt.'
        );
    }

    public function test_total_stays_unfiltered_while_searching(): void
    {
        $c = $this->komponente(
            ['Logistiker', 'Servicekräfte', 'Kasse'],
            ['Servicekräfte', 'Logistiker', 'Kasse', 'Bar'],
            suche: 'kas'
        );

        $this->assertCount(1, $c->dispoTaetigkeitenKatalog(), 'Die Liste ist gefiltert.');
        $this->assertSame(4, $c->dispoTaetigkeitenGesamt(),
            'Der Nenner der Ueberschrift ist die ungefilterte Kataloggroesse, nicht die Trefferzahl.');
    }

    public function test_closing_the_modal_resets_the_search(): void
    {
        $c = $this->komponente([], ['Kasse'], suche: 'kas');
        $c->openTaetigkeiten();
        $this->assertTrue($c->showTaetigkeitenModal);
        $c->taetigkeitenSuche = 'kas';
        $c->closeTaetigkeiten();

        $this->assertFalse($c->showTaetigkeitenModal);
        $this->assertSame('', $c->taetigkeitenSuche);
    }
}
