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
use Platform\Recruiting\Models\RecApplicant;
use Platform\Recruiting\Services\CreateEmployeeFromApplicantService;

/**
 * Die Firma (RG/MA) muss schon bei der Uebernahme am Mitarbeiter stehen.
 *
 * BEFUND, DER DIESEN TEST AUSGELOEST HAT (Kundenmeldung 10.09.2026): 23 frisch
 * uebernommene Moenchengladbacher MA kamen mit LEERER Spalte `Firma` im
 * ZAS-Export an. ZAS leitet die Filiale aus Firma + Kostenstelle ab und ist
 * ohne Firma auf DUS zurueckgefallen, obwohl die Kostenstelle 200 (MGL) stimmte.
 *
 * Ursache: `company` hatte nur zwei Schreiber — den ZAS-Inbound (Praefix der
 * Personalnummer) und die HR-Maske. Eine Neuanlage aus dem Funnel hat noch
 * keine Personalnummer (die vergibt ZAS erst), also blieb die Spalte NULL.
 * Bis zum 26.08.2026 hat das niemand gemerkt, weil die Migration von damals
 * ALLE bis dahin bestehenden Zeilen einmalig befuellt hat.
 *
 * WARUM DIE KONFIGURATION UND KEIN LITERAL: 'RG' ist unsere eigene Firma, aber
 * derselbe Code laeuft laut config/recruiting.php auch mit einem anderen
 * Praefix. testDieFirmaKommtAusDerKonfiguration ist der Falsifikator dagegen,
 * dass hier jemand 'RG' fest verdrahtet — der Test wuerde dann rot.
 *
 * PROZESSWEITER ZUSTAND: dieselben Fallen wie in
 * EmployeeCreationCertificateTest, dort ausfuehrlich begruendet —
 * Model::clearBootedModels() und Facade::clearResolvedInstances() im Setup,
 * Extra-Field-Cache im Teardown.
 */
class EmployeeCreationCompanyTest extends TestCase
{
    private const TEAM = 7;

    public static function setUpBeforeClass(): void
    {
        $container = Container::getInstance();

        $container->instance('config', new ConfigRepository([
            'activity-log' => ['events' => []],
            'recruiting'   => ['zas' => ['company_prefix' => 'RG']],
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

        Model::clearBootedModels();

        $container->instance('db', $capsule->getDatabaseManager());
        $container->instance('db.schema', $capsule->getConnection()->getSchemaBuilder());
        Facade::setFacadeApplication($container);
        Facade::clearResolvedInstances();

        self::runRealMigrations();
    }

    public static function tearDownAfterClass(): void
    {
        Model::clearBootedModels();
        Facade::clearResolvedInstances();
        self::leereExtraFieldCache();
    }

    public function testUebernahmeStempeltDieEigeneFirmaAnDenMitarbeiter(): void
    {
        $applicant = $this->bewerber('Santos', 'Maria');

        $employee = (new CreateEmployeeFromApplicantService())->createOrUpdate($applicant);

        $this->assertSame('RG', $employee->company);
        $this->assertSame('RG', $employee->fresh()->company, 'Die Firma muss in der DB stehen, nicht nur am Objekt.');
    }

    /**
     * Falsifikator gegen ein fest verdrahtetes 'RG': mit einem anderen
     * konfigurierten Praefix muss auch ein anderer Wert am MA stehen.
     */
    public function testDieFirmaKommtAusDerKonfiguration(): void
    {
        config()->set('recruiting.zas.company_prefix', 'MA');

        try {
            $employee = (new CreateEmployeeFromApplicantService())
                ->createOrUpdate($this->bewerber('Puce', 'Emils'));

            $this->assertSame('MA', $employee->fresh()->company);
        } finally {
            config()->set('recruiting.zas.company_prefix', 'RG');
        }
    }

    private function bewerber(string $nachname, string $vorname): RecApplicant
    {
        $applicant = RecApplicant::create([
            'team_id'    => self::TEAM,
            'is_active'  => true,
            'auto_pilot' => false,
        ]);

        $contact = CrmContact::create([
            'team_id'    => self::TEAM,
            'is_active'  => true,
            'first_name' => $vorname,
            'last_name'  => $nachname,
        ]);
        $applicant->crmContactLinks()->create(['contact_id' => $contact->id, 'team_id' => self::TEAM]);

        return $applicant;
    }

    /** Siehe EmployeeCreationCertificateTest::leereExtraFieldCache(). */
    private static function leereExtraFieldCache(): void
    {
        foreach (['extraFieldDefinitionsCache', 'extraFieldInheritanceStack'] as $name) {
            $property = new \ReflectionProperty(RecApplicant::class, $name);
            $property->setValue(null, []);
        }
    }

    /** Siehe EmployeeCreationCertificateTest::runRealMigrations(). */
    private static function runRealMigrations(): void
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
            $parent = dirname($dir);
            if ($parent === $dir) {
                break;
            }
            $dir = $parent;
        }

        throw new \RuntimeException('Paketwurzel nicht gefunden: ' . $class);
    }
}
