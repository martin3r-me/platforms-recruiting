<?php

namespace Platform\Recruiting\Tests\Integration;

use Carbon\Carbon;
use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Container\Container;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use Platform\Core\Models\User;
use Platform\Crm\Models\CrmContact;
use Platform\Recruiting\Models\RecApplicant;
use Platform\Recruiting\Models\RecContract;
use Platform\Recruiting\Models\RecContractTemplate;
use Platform\Recruiting\Models\RecEmployee;
use Platform\Recruiting\Support\AnstellungsZuordnung;
use Platform\Recruiting\Support\ZasPersonnelNumber;

/**
 * Harness fuer das Paket "Vertrag an der Anstellung" (Stufe 1). Echte
 * Migrationen (Core/CRM/eigene) gegen SQLite; spaetere Tasks haengen weitere
 * Testmethoden an. Siehe EmployeeCreationCompanyTest fuer die Begruendung der
 * prozessweiten Zustaende (clearBootedModels, Facade, Extra-Field-Cache).
 */
class VertragAnDerAnstellungTest extends TestCase
{
    private const TEAM = 7;

    public static function setUpBeforeClass(): void
    {
        $container = Container::getInstance();

        $container->instance('config', new ConfigRepository([
            'activity-log' => ['events' => []],
            'recruiting'   => ['zas' => [
                'company_prefix'  => 'RG',
                'inbound_team_id' => self::TEAM,
                'company_labels'  => ['RG' => 'RheinGedeck'],
            ]],
        ]));

        $dispatcher = new Dispatcher($container);
        $container->instance('events', $dispatcher);

        $container->instance('log', new class {
            public function __call(string $name, array $args): void
            {
            }
        });

        $container->instance('url', new class {
            public function route($name, $parameters = [], $absolute = true): string
            {
                return '/' . $name . '/' . http_build_query($parameters);
            }
        });

        // User mit currentTeam; check()/id() fuer CrmContactLink::creating.
        $container->singleton(AuthFactory::class, function () {
            return new class(self::TEAM) implements AuthFactory {
                public function __construct(private int $teamId) {}

                public function guard($name = null)
                {
                    return $this;
                }

                public function user(): object
                {
                    return (object) ['currentTeam' => (object) ['id' => $this->teamId]];
                }

                public function check() { return false; }
                public function id() { return null; }
                public function shouldUse($name) {}
            };
        });
        $container->alias(AuthFactory::class, 'auth');

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

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-09-15 10:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_regel_waehlt_die_firmengleiche_anstellung_und_nie_eine_ohne_firma(): void
    {
        $a = $this->bewerber('Regel');
        $ma = $this->zasAnstellung('MA100');  $this->verknuepfen($ma, $a);   // kleinere id
        $rg = $this->zasAnstellung('RG100');  $this->verknuepfen($rg, $a);
        $ohne = RecEmployee::create(['team_id' => self::TEAM, 'first_name' => 'Ohne', 'last_name' => 'Firma', 'rec_applicant_id' => $a->id, 'is_active' => true]);
        $this->assertNull($ohne->fresh()->company, 'Vorflug: diese Anstellung hat keine Firma');

        $this->assertSame($rg->id, $this->vorlage('AV-A')->anstellungFuer($a)->anstellungId());
        $this->assertSame($ma->id, $this->vorlage('AV-B', 'MA')->anstellungFuer($a)->anstellungId());

        $fremd = $this->vorlage('AV-C', 'XX');
        $this->assertSame(AnstellungsZuordnung::FIRMA_FEHLT, $fremd->anstellungFuer($a)->befund);

        // Vorlage ohne Firma darf die Anstellung ohne Firma NICHT treffen (NULL == NULL).
        $ohneFirma = $this->vorlage('AV-E');
        $ohneFirma->forceFill(['company' => null]);
        $this->assertSame(AnstellungsZuordnung::FIRMA_FEHLT, $ohneFirma->anstellungFuer($a)->befund);

        $this->assertSame(AnstellungsZuordnung::OHNE_ANSTELLUNG, $this->vorlage('AV-D')->anstellungFuer($this->bewerber('Leer'))->befund);
    }

    private function bewerber(string $nachname): RecApplicant
    {
        $applicant = RecApplicant::create([
            'team_id'    => self::TEAM,
            'is_active'  => true,
            'auto_pilot' => false,
            'zuschlag'   => 1.0,
        ]);

        $contact = CrmContact::create([
            'team_id'    => self::TEAM,
            'is_active'  => true,
            'first_name' => 'Test',
            'last_name'  => $nachname,
        ]);
        $applicant->crmContactLinks()->create(['contact_id' => $contact->id, 'team_id' => self::TEAM]);

        return $applicant;
    }

    private function vorlage(string $code, string $company = 'RG', ?string $taetigkeit = null): RecContractTemplate
    {
        return RecContractTemplate::create([
            'team_id' => self::TEAM, 'name' => $code, 'code' => $code,
            'company' => $company, 'taetigkeit' => $taetigkeit, 'is_active' => true,
            'content' => '<p>Vertrag</p>', 'field_mappings' => [],
        ]);
    }

    private function vertrag(RecApplicant $a, RecContractTemplate $t, array $attrs = []): RecContract
    {
        return RecContract::create(array_merge([
            'rec_applicant_id' => $a->id, 'rec_contract_template_id' => $t->id, 'team_id' => self::TEAM,
            'status' => 'completed', 'sent_at' => '2026-09-01 09:00:00',
            'signed_at' => '2026-09-02 09:00:00', 'completed_at' => '2026-09-02 09:00:00',
            'personalized_content' => '<p>Vertrag</p>',
        ], $attrs));
    }

    /** Zweite Anstellung "aus ZAS": ohne Bewerbung, mit Lieferungs-Kennung, Firma aus dem Praefix. */
    private function zasAnstellung(string $pnr): RecEmployee
    {
        return RecEmployee::create([
            'team_id' => self::TEAM, 'first_name' => 'Zas', 'last_name' => 'Person',
            'personnel_number' => $pnr, 'company' => ZasPersonnelNumber::prefixOf($pnr),
            'rec_zas_inbound_file_id' => 1, 'is_active' => true,
        ]);
    }

    private function verknuepfen(RecEmployee $e, RecApplicant $a): void
    {
        DB::table('rec_employees')->where('id', $e->id)->update(['rec_applicant_id' => $a->id]);
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
            $core . '/database/migrations/2026_02_23_000001_create_core_public_form_links_table.php',
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
