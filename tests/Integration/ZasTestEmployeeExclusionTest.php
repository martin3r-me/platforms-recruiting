<?php

namespace Platform\Recruiting\Tests\Integration;

use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use Platform\Core\Models\User;
use Platform\Crm\Models\CrmContact;
use Platform\Crm\Models\CrmContactLink;
use Platform\Recruiting\Http\Controllers\ZasEmployeeFileController;
use Platform\Recruiting\Http\Controllers\ZasEmployeeInitialExportController;
use Platform\Recruiting\Http\Controllers\ZasEmployeeUpdateExportController;
use Platform\Recruiting\Models\RecDispoAssignment;
use Platform\Recruiting\Models\RecDispoEvent;
use Platform\Recruiting\Models\RecEmployee;
use Platform\Recruiting\Models\RecPosition;
use Platform\Recruiting\Observers\RecEmployeeExportObserver;
use Platform\Recruiting\Services\Zas\Dispo\DispoEmployeeDirectory;
use Platform\Recruiting\Services\Zas\Dispo\DispoIdentityResolver;
use Platform\Recruiting\Services\Zas\Dispo\ZasDispoConfirmationExport;
use Platform\Recruiting\Services\Zas\PersonPairLinker;
use Platform\Recruiting\Services\Zas\ZasCsvBuilder;
use Platform\Recruiting\Services\Zas\ZasEmployeeFieldResolver;
use Platform\Recruiting\Services\Zas\ZasEmployeeMatcher;
use Platform\Recruiting\Services\Zas\ZasInboundDuplicateFinder;
use Platform\Recruiting\Services\Zas\ZasSignedUrlGenerator;

/**
 * Test-Mitarbeiter (is_test) duerfen an KEINEM ZAS-Ausgang auftauchen: weder in
 * initial.csv/updates.csv noch als Update-Marker, im Dispo-Verzeichnis oder in
 * den Bestaetigungen. Ein vergessener Filter schickt Testdaten still an ZAS —
 * deshalb hat jeder Filter hier eine Gegenprobe mit einem echten MA.
 */
class ZasTestEmployeeExclusionTest extends TestCase
{
    private const TEAM = 7;

    public static function setUpBeforeClass(): void
    {
        $container = Container::getInstance();
        $container->instance('config', new ConfigRepository([
            'activity-log' => ['events' => []],
            'recruiting'   => ['zas' => ['company_prefix' => 'RG', 'inbound_team_id' => self::TEAM]],
        ]));

        $dispatcher = new Dispatcher($container);
        $container->instance('events', $dispatcher);
        $container->instance('log', new class {
            public function __call(string $name, array $args): void
            {
            }
        });


        // CrmContactLink::creating ruft auth()->check().
        $container->singleton(\Illuminate\Contracts\Auth\Factory::class, function () {
            return new class implements \Illuminate\Contracts\Auth\Factory {
                public function guard($name = null)
                {
                    return new class implements \Illuminate\Contracts\Auth\Guard {
                        public function check() { return false; }
                        public function guest() { return true; }
                        public function user() { return null; }
                        public function id() { return null; }
                        public function validate(array $credentials = []) { return false; }
                        public function hasUser() { return false; }
                        public function setUser(\Illuminate\Contracts\Auth\Authenticatable $user) { return $this; }
                    };
                }
                public function shouldUse($name) {}
                public function __call($method, $args) { return $this->guard()->{$method}(...$args); }
            };
        });
        $container->alias(\Illuminate\Contracts\Auth\Factory::class, 'auth');

        $capsule = new Capsule();
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $capsule->setEventDispatcher($dispatcher);
        $capsule->setAsGlobal();
        $capsule->bootEloquent();
        Model::unguard();
        Model::clearBootedModels();

        $container->instance('db', $capsule->getDatabaseManager());
        $container->instance('db.schema', $capsule->getConnection()->getSchemaBuilder());
        Facade::setFacadeApplication($container);
        Facade::clearResolvedInstances();
        // response() im Datei-Controller; make() braucht weder View noch Redirector.
        $container->bind(\Illuminate\Contracts\Routing\ResponseFactory::class, fn () => (new \ReflectionClass(\Illuminate\Routing\ResponseFactory::class))->newInstanceWithoutConstructor());

        self::runMigrations();
        RecEmployeeExportObserver::register();
    }

    public static function tearDownAfterClass(): void
    {
        Model::clearBootedModels();
        Facade::clearResolvedInstances();
    }

    protected function setUp(): void
    {
        foreach (['rec_dispo_assignments', 'rec_dispo_events', 'rec_employees', 'rec_positions'] as $table) {
            Capsule::table($table)->delete();
        }
    }

    private function ma(bool $test, array $extra = []): RecEmployee
    {
        static $n = 0;
        $n++;

        return RecEmployee::create(array_merge([
            'uuid'       => 'uuid-' . $n,
            'team_id'    => self::TEAM,
            'first_name' => 'Max',
            'last_name'  => 'Muster',
            'birth_date' => '1990-01-01',
            'email'      => "ma{$n}@example.org",
            'is_active'  => true,
            'is_test'    => $test,
        ], $extra));
    }

    private function exportController(string $class): object
    {
        $resolver = (new \ReflectionClass(ZasEmployeeFieldResolver::class))->newInstanceWithoutConstructor();

        return new class($class, $resolver) {
            private object $inner;

            public function __construct(string $class, $resolver)
            {
                $this->inner = new $class($resolver, new ZasCsvBuilder());
            }

            public function initialIds(): array
            {
                $m = new \ReflectionMethod($this->inner, 'fetchInitialEmployees');

                return $m->invoke($this->inner, null)->pluck('id')->all();
            }

            public function changedIds(): array
            {
                $m = new \ReflectionMethod($this->inner, 'fetchChangedEmployees');

                return $m->invoke($this->inner, null)->pluck('id')->all();
            }
        };
    }

    public function testInitialExportLaesstTestMaWeg(): void
    {
        $echt = $this->ma(false);
        $this->ma(true);

        $ids = $this->exportController(ZasEmployeeInitialExportController::class)->initialIds();

        $this->assertSame([$echt->id], $ids);
    }

    public function testUpdateExportLaesstTestMaWeg(): void
    {
        $echt = $this->ma(false);
        $test = $this->ma(true);
        Capsule::table('rec_employees')->whereIn('id', [$echt->id, $test->id])
            ->update(['zas_initial_exported_at' => now(), 'zas_changed_at' => now()]);

        $ids = $this->exportController(ZasEmployeeUpdateExportController::class)->changedIds();

        $this->assertSame([$echt->id], $ids);
    }

    public function testObserverMarkiertTestMaNicht(): void
    {
        $echt = $this->ma(false);
        $test = $this->ma(true);

        $test->update(['last_name' => 'Geaendert']);
        $echt->update(['last_name' => 'Geaendert']);

        $this->assertNull(Capsule::table('rec_employees')->where('id', $test->id)->value('zas_changed_at'));
        $this->assertNotNull(Capsule::table('rec_employees')->where('id', $echt->id)->value('zas_changed_at'), 'Gegenprobe: echter MA wird markiert.');
    }

    public function testLohnAenderungStempeltTestMaNicht(): void
    {
        $echt = $this->ma(false, ['iban' => 'DE02100100100006820101']);
        $test = $this->ma(true, ['iban' => 'DE02100100100006820101']);

        $test->update(['iban' => 'DE89370400440532013000']);
        $echt->update(['iban' => 'DE89370400440532013000']);

        $this->assertNull(Capsule::table('rec_employees')->where('id', $test->id)->value('payroll_data_changed_at'));
        $this->assertNull(Capsule::table('rec_employees')->where('id', $test->id)->value('payroll_data_changed_fields'));
        $this->assertNotNull(Capsule::table('rec_employees')->where('id', $echt->id)->value('payroll_data_changed_at'), 'Gegenprobe: echter MA wird gestempelt.');
    }

    public function testKostenstellenwechselMarkiertTestMaNicht(): void
    {
        $position = RecPosition::create(['team_id' => self::TEAM, 'title' => 'Service', 'cost_center' => '100']);
        $echt = $this->ma(false, ['rec_position_id' => $position->id]);
        $test = $this->ma(true, ['rec_position_id' => $position->id]);

        $position->update(['cost_center' => '200']);

        $this->assertNull(Capsule::table('rec_employees')->where('id', $test->id)->value('zas_changed_at'));
        $this->assertNotNull(Capsule::table('rec_employees')->where('id', $echt->id)->value('zas_changed_at'), 'Gegenprobe: echter MA wird markiert.');
    }

    public function testDateiEndpunktGibtFuerTestMaNotFound(): void
    {
        $echt = $this->ma(false);
        $test = $this->ma(true);
        $gen = new ZasSignedUrlGenerator('geheim');
        $controller = new ZasEmployeeFileController($gen);

        $response = fn (RecEmployee $e) => $controller(
            \Illuminate\Http\Request::create('/x', 'GET', $this->signedQuery($gen, $e->uuid, 'unbekannt')),
            $e->uuid,
            'unbekannt'
        );

        // Inhalt, nicht nur Status: ein unbekannter Slot waere ebenfalls 404.
        $this->assertSame('Not found', $response($test)->getContent());
        // Gegenprobe: echter MA kommt am Test-MA-Check vorbei (404 erst wegen unbekanntem Slot).
        $this->assertSame('Unknown slot', $response($echt)->getContent());
    }

    public function testDispoVerzeichnisKenntTestMaNicht(): void
    {
        $echt = $this->ma(false, ['personnel_number' => 'RG100']);
        $test = $this->ma(true, ['personnel_number' => 'RG200']);

        $dir = new DispoEmployeeDirectory();

        $this->assertSame(['RG100' => $echt->id], $dir->map());
        $this->assertSame([$echt->id], $dir->activeIds());
        $this->assertNotContains($test->id, $dir->activeIds());
    }

    public function testIdentityResolverLiefertTestMaNichtZumKontakt(): void
    {
        $echt = $this->ma(false);
        $test = $this->ma(true);
        $contact = CrmContact::create(['team_id' => self::TEAM, 'is_active' => true, 'first_name' => 'Max', 'last_name' => 'Muster']);
        foreach ([$echt, $test] as $e) {
            CrmContactLink::create([
                'contact_id' => $contact->id, 'team_id' => self::TEAM,
                'linkable_type' => $e->getMorphClass(), 'linkable_id' => $e->id,
            ]);
        }

        $byContact = (new DispoIdentityResolver())->employeeIdsByContact([$contact->id]);

        $this->assertSame([$contact->id => [$echt->id]], $byContact, 'Echter MA bleibt, Test-MA fehlt.');
    }

    public function testBestaetigungsExportLaesstTestMaWeg(): void
    {
        $echt = $this->ma(false);
        $test = $this->ma(true);
        $event = RecDispoEvent::create(['einsatz_ref' => 'RG1', 'name' => 'Einsatz']);
        foreach ([['DS-E', $echt->id], ['DS-T', $test->id], ['DS-N', null]] as [$ds, $empId]) {
            RecDispoAssignment::create([
                'ds_ref' => $ds, 'rec_dispo_event_id' => $event->id, 'pnr_raw' => 'RG1',
                'datum' => now()->addDay()->toDateString(), 'von' => '09:00', 'bis' => '17:00',
                'status_id' => RecDispoAssignment::STATUS_AUFTRAG,
                'confirmed_at' => now(), 'rec_employee_id' => $empId,
            ]);
        }

        $dsids = array_column((new ZasDispoConfirmationExport())->rows(7), 'DSID');
        sort($dsids);

        $this->assertSame(['DS-E', 'DS-N'], $dsids, 'Test-MA fehlt; echter MA und Buchung ohne MA bleiben.');
    }

    public function testBestaetigungsExportLaesstPnrTestWegAuchOhneMa(): void
    {
        $event = RecDispoEvent::create(['einsatz_ref' => 'RG1', 'name' => 'Einsatz']);
        foreach ([['DS-T', 'TEST'], ['DS-E', 'RG1']] as [$ds, $pnr]) {
            RecDispoAssignment::create([
                'ds_ref' => $ds, 'rec_dispo_event_id' => $event->id, 'pnr_raw' => $pnr,
                'datum' => now()->addDay()->toDateString(), 'von' => '09:00', 'bis' => '17:00',
                'status_id' => RecDispoAssignment::STATUS_AUFTRAG,
                'confirmed_at' => now(), 'rec_employee_id' => null,
            ]);
        }

        $this->assertSame(['DS-E'], array_column((new ZasDispoConfirmationExport())->rows(7), 'DSID'));
    }

    public function testMatcherTrifftTestMaNie(): void
    {
        $echt = $this->ma(false, ['personnel_number' => 'RG300']);
        $test = $this->ma(true, ['personnel_number' => 'RG400']);
        $matcher = new ZasEmployeeMatcher();

        $this->assertNull($matcher->match($test->uuid, null, self::TEAM, 'RG')['employee']);
        $this->assertNull($matcher->match(null, 'RG400', self::TEAM, 'RG')['employee']);
        $this->assertSame($echt->id, $matcher->match($echt->uuid, null, self::TEAM, 'RG')['employee']->id);
        $this->assertSame($echt->id, $matcher->match(null, 'RG300', self::TEAM, 'RG')['employee']->id);
    }

    public function testDublettenfinderMeldetTestMaNicht(): void
    {
        $echt = $this->ma(false, ['email' => 'echt@example.org']);
        $this->ma(true, ['email' => 'test@example.org']);
        $finder = new ZasInboundDuplicateFinder();

        $this->assertSame([], $finder->suspicions(['email' => 'test@example.org'], self::TEAM));
        $hits = $finder->suspicions(['email' => 'echt@example.org'], self::TEAM);
        $this->assertSame($echt->id, $hits[0]['employee_id']);
    }

    public function testPersonenPaarungNimmtTestMaNichtAlsPartner(): void
    {
        $neu = $this->ma(false, ['first_name' => 'Eva', 'last_name' => 'Gleich']);
        $this->ma(true, ['first_name' => 'Eva', 'last_name' => 'Gleich']);
        $linker = new PersonPairLinker();

        $this->assertSame('none', $linker->pairIfExact($neu)['status']);

        $echterZwilling = $this->ma(false, ['first_name' => 'Eva', 'last_name' => 'Gleich']);
        $this->assertSame('paired', $linker->pairIfExact($echterZwilling)['status'], 'Gegenprobe: echter Partner wird gefunden.');
    }

    private function signedQuery(ZasSignedUrlGenerator $gen, string $uuid, string $slot): array
    {
        $expires = time() + 600;
        $ref = new \ReflectionClass($gen);
        foreach (['sign', 'signature', 'compute'] as $name) {
            if ($ref->hasMethod($name)) {
                $m = $ref->getMethod($name);
                $m->setAccessible(true);

                return ['expires' => $expires, 'sig' => $m->invoke($gen, $uuid, $slot, $expires)];
            }
        }
        throw new \RuntimeException('Signatur-Methode nicht gefunden');
    }

    private static function runMigrations(): void
    {
        $core = self::packageRootOf(User::class);
        $crm  = self::packageRootOf(CrmContact::class);
        $own  = dirname(__DIR__, 2);

        $fremd = [
            $core . '/database/migrations/0001_01_01_000000_create_users_table.php',
            $core . '/database/migrations/2026_02_07_000001_create_core_extra_field_definitions_table.php',
            $core . '/database/migrations/2026_02_07_000002_create_core_extra_field_values_table.php',
            $crm . '/database/migrations/2024_01_01_000016_create_crm_contacts_table.php',
            $crm . '/database/migrations/2024_01_01_000020_create_crm_contact_links_table.php',
            $crm . '/database/migrations/2026_02_18_220000_make_created_by_user_id_nullable_on_crm_contact_links.php',
        ];
        foreach (glob($crm . '/database/migrations/*email_address*.php') as $file) {
            $fremd[] = $file;
        }
        foreach (glob($crm . '/database/migrations/*phone_number*.php') as $file) {
            $fremd[] = $file;
        }

        $eigene = glob($own . '/database/migrations/*.php');
        sort($eigene);

        foreach (array_merge($fremd, $eigene) as $path) {
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
            $dir = dirname($dir);
        }

        throw new \RuntimeException('Paketwurzel nicht gefunden: ' . $class);
    }
}
