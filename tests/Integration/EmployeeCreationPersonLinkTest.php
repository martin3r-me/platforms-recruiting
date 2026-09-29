<?php

namespace Platform\Recruiting\Tests\Integration;

use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\TestCase;
use Platform\Core\Models\User;
use Platform\Crm\Models\CrmContact;
use Platform\Recruiting\Models\RecApplicant;
use Platform\Recruiting\Models\RecEmployee;
use Platform\Recruiting\Services\CreateEmployeeFromApplicantService;

/**
 * Schliesst die Luecke "neue Mitarbeiter bekommen keine Personen-Zeile"
 * (Spec 2026-09-28, Paragraph 4.3). Der Backfill (recruiting:backfill-persons)
 * stellt die Klammer fuer den BESTAND her — ohne den Haken in
 * CreateEmployeeFromApplicantService::linkPerson() waere er nur eine
 * Momentaufnahme, und jeder danach angelegte Mensch bliebe unverbunden.
 *
 * DIE ZWEI GUTFAELLE beweisen den Normalzustand (Zeile entsteht, wird
 * wiederverwendet), DER FALSIFIKATOR (testPersonenLinkFehlerLaesstDenMitarbeiterStehen)
 * ist der wichtigste: er zeigt, dass ein Sonderfall der Personen-Zuordnung
 * NICHT die Mitarbeiter-Anlage mitreisst — dieselbe Zusage wie beim
 * Zertifikat (EmployeeCreationCertificateTest), aus demselben Grund: dieser
 * Weg laeuft unter anderem beim Unterschreiben des Arbeitsvertrags, einer
 * oeffentlichen Strecke mit einem echten Menschen davor.
 *
 * WIE DER FEHLER ERZEUGT WIRD: anders als beim Zertifikat-Test (dort ein
 * Container-Stub, weil IssueTrainingCertificateService ueber app() aufgeloest
 * wird) ist PersonLinker eine reine Static-Utility-Klasse ohne
 * Container-Aufloesung — ein Stub laesst sich dort nicht einhaengen. Der
 * Fehler kommt deshalb aus der Infrastruktur selbst: die Tabelle rec_persons
 * wird VOR dem Aufruf entfernt, das INSERT in PersonLinker::legeZeileAn()
 * scheitert also mit einer echten QueryException. Das ist unschaedlich fuer
 * andere Tests, weil jede Testklasse in diesem Modul ihre EIGENE
 * :memory:-Verbindung mit eigenem Schema aufbaut (siehe setUpBeforeClass) —
 * das Entfernen hier wirkt nur innerhalb dieser Klasse, und die Tabelle wird
 * danach fuer die folgenden Tests dieser Klasse wiederhergestellt.
 *
 * PROZESSWEITER ZUSTAND: dieselben Fallen wie in EmployeeCreationCertificateTest,
 * dort ausfuehrlich begruendet — Model::clearBootedModels() und
 * Facade::clearResolvedInstances() im Setup, Extra-Field-Cache im Teardown.
 */
class EmployeeCreationPersonLinkTest extends TestCase
{
    private const TEAM = 11;

    public static function setUpBeforeClass(): void
    {
        $container = Container::getInstance();

        $container->instance('config', new ConfigRepository([
            'activity-log' => ['events' => []],
        ]));

        $dispatcher = new Dispatcher($container);
        $container->instance('events', $dispatcher);

        // Log wird gebraucht: der Falsifikator prueft die Log-Zeile des
        // gescheiterten Personen-Links.
        $container->instance('log', new class {
            public function __call(string $name, array $args): void
            {
                EmployeeCreationPersonLinkTest::merkeLog($name, $args);
            }
        });

        // CrmContactLink::creating ruft auth()->check() — Stub ohne User.
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

    protected function setUp(): void
    {
        self::$logZeilen = [];
    }

    /** @var list<array{level: string, message: string, context: array}> */
    private static array $logZeilen = [];

    public static function merkeLog(string $level, array $args): void
    {
        self::$logZeilen[] = [
            'level' => $level,
            'message' => (string) ($args[0] ?? ''),
            'context' => (array) ($args[1] ?? []),
        ];
    }

    // -----------------------------------------------------------------
    // Die Gutfaelle
    // -----------------------------------------------------------------

    /**
     * Der Normalfall: der frisch angelegte Mitarbeiter traegt danach eine
     * rec_person_id, und es existiert genau eine passende Zeile in
     * rec_persons.
     */
    public function testNeuerMitarbeiterBekommtGenauEinePersonenZeile(): void
    {
        $applicant = $this->bewerber('Fischer', 'Tanja');

        $employee = (new CreateEmployeeFromApplicantService())->createOrUpdate($applicant, null);

        // linkPerson() schreibt observer-frei per DB::table(...) NACH dem
        // Rueckgabewert von createOrUpdate() — das am Aufrufer haengende
        // Objekt spiegelt die Aenderung deshalb nicht automatisch, genau wie
        // bei jedem anderen Aufrufer dieser Klasse auch. Gemessen wird daher
        // an der DB, nicht am Objekt (siehe PersonLinker-Docblock, "EIN
        // SCHREIBER" / "OBSERVER-FREI").
        $recPersonId = $employee->fresh()->rec_person_id;
        $this->assertNotNull($recPersonId, 'Die Personen-Zeile muss in der DB stehen.');

        $this->assertSame(
            1,
            DB::table('rec_persons')->where('id', $recPersonId)->count(),
            'Genau eine passende Zeile in rec_persons.'
        );
        $this->assertSame(
            1,
            DB::table('rec_employees')->where('rec_person_id', $recPersonId)->count(),
            'Und genau eine Anstellung haengt daran.'
        );

        $this->assertSame([], self::$logZeilen, 'Der Normalfall ist kein Fehlerfall.');
    }

    /**
     * Idempotenz: ein zweiter Aufruf von createOrUpdate() fuer denselben
     * Bewerber legt keine zweite Personen-Zeile an. Der Idempotenz-Zweig ganz
     * oben in createOrUpdate() greift hier zwar schon vorher (der bestehende
     * RecEmployee wird ohne Re-Mapping zurueckgegeben) — aber genau das ist
     * die Zusicherung: createOrUpdate() heisst createOrUpdate(), auch fuer den
     * Personen-Link, den es an dieser Stelle antraegt.
     */
    public function testZweiterAufrufLegtKeineZweitePersonenZeileAn(): void
    {
        $applicant = $this->bewerber('Bergmann', 'Oskar');
        $service = new CreateEmployeeFromApplicantService();

        $ersterMitarbeiter = $service->createOrUpdate($applicant, null);
        $ersteRecPersonId = $ersterMitarbeiter->fresh()->rec_person_id;
        $anzahlNachErstemAufruf = DB::table('rec_persons')->count();

        $zweiterMitarbeiter = $service->createOrUpdate($applicant, null);

        $this->assertSame($ersterMitarbeiter->id, $zweiterMitarbeiter->id, 'Derselbe Mitarbeiter, kein zweiter.');
        $this->assertSame(
            $ersteRecPersonId,
            $zweiterMitarbeiter->fresh()->rec_person_id,
            'Dieselbe Personen-Zeile, keine zweite.'
        );
        $this->assertSame(
            $anzahlNachErstemAufruf,
            DB::table('rec_persons')->count(),
            'Der zweite Aufruf darf rec_persons nicht anfassen.'
        );
    }

    // -----------------------------------------------------------------
    // Der Falsifikator
    // -----------------------------------------------------------------

    /**
     * DER WICHTIGSTE TEST: scheitert das Verknuepfen, entsteht der
     * Mitarbeiter trotzdem — dieselbe Zusage wie beim Zertifikat, aus
     * demselben Grund (dieser Weg laeuft unter anderem beim Unterschreiben
     * des Arbeitsvertrags, einer oeffentlichen Strecke mit einem echten
     * Menschen davor).
     *
     * Drei Dinge muessen gleichzeitig gelten:
     *  - createOrUpdate() wirft nicht,
     *  - der Mitarbeiter steht in der DB und traegt KEINE rec_person_id,
     *  - der Fehler ist nicht stumm: eine Log-Zeile mit der Mitarbeiter-
     *    Kennung und dem Grund.
     */
    public function testPersonenLinkFehlerLaesstDenMitarbeiterStehen(): void
    {
        $applicant = $this->bewerber('Winkler', 'Doris');

        Schema::dropIfExists('rec_persons');
        try {
            $employee = (new CreateEmployeeFromApplicantService())->createOrUpdate($applicant, null);
        } finally {
            self::stelleRecPersonsWieder();
        }

        $this->assertInstanceOf(RecEmployee::class, $employee, 'createOrUpdate() darf nicht werfen.');
        $this->assertNotNull(RecEmployee::find($employee->id), 'Der Mitarbeiter steht in der DB.');
        $this->assertNull($employee->fresh()->rec_person_id, 'Ohne rec_persons-Tabelle konnte nichts verknuepft werden.');

        $this->assertCount(1, self::$logZeilen);
        $zeile = self::$logZeilen[0];
        $this->assertSame('error', $zeile['level']);
        $this->assertStringContainsString('Personen', $zeile['message']);
        $this->assertSame((int) $employee->id, (int) $zeile['context']['employee_id']);
        $this->assertNotEmpty($zeile['context']['error']);
    }

    // -----------------------------------------------------------------
    // Fixtures
    // -----------------------------------------------------------------

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

    /**
     * Stellt rec_persons nach dem Falsifikator-Test wieder her, exakt wie die
     * echte Migration — sonst faellt jeder Test danach in dieser Klasse ohne
     * eigenen Grund um.
     */
    private static function stelleRecPersonsWieder(): void
    {
        if (Schema::hasTable('rec_persons')) {
            return;
        }

        Schema::create('rec_persons', function ($table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('team_id')->nullable()->index();
            $table->string('phone', 32)->nullable();
            $table->string('password_hash')->nullable();
            $table->string('email')->nullable();
            $table->timestamp('invited_at')->nullable();
            $table->timestamp('registered_at')->nullable();
            $table->timestamp('locked_at')->nullable();
            $table->unsignedBigInteger('merged_into_person_id')->nullable()->index();
            $table->timestamps();
            $table->unique(['team_id', 'phone'], 'rec_persons_team_phone_unique');
        });
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
