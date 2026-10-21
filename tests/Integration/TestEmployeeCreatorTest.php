<?php

namespace Platform\Recruiting\Tests\Integration;

use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Events\Dispatcher;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use Platform\Core\Models\CoreExtraFieldDefinition;
use Platform\Core\Models\User;
use Platform\Core\Services\ContextFileService;
use Platform\Crm\Models\CrmContact;
use Platform\Recruiting\Models\RecApplicant;
use Platform\Recruiting\Models\RecApplicantLegalStatus;
use Platform\Recruiting\Models\RecEmployee;
use Platform\Recruiting\Models\RecPhase;
use Platform\Recruiting\Services\HrDeskRoutingService;
use Platform\Recruiting\Services\TestEmployee\PlaceholderImage;
use Platform\Recruiting\Services\TestEmployee\TestEmployeeCreator;
use Platform\Recruiting\Services\TestEmployee\TestEmployeeProfiles;

/**
 * Test-MA ueber den ECHTEN Pfad: Bewerber mit Feldwerten →
 * CreateEmployeeFromApplicantService::createOrUpdate().
 *
 * Darum prueft dieser Test die MA-Spalten (iban, selfie_file_id, …) und nicht
 * die Feldwerte allein: ein Creator, der MA-Spalten direkt schriebe, waere
 * genau das Anti-Ziel der Spec — er testete dann nicht, was echte MA
 * durchlaufen.
 *
 * Aufbau wie EmployeeCreationCompanyTest (Container + Capsule von Hand, alle
 * eigenen Migrationen). Der Datei-Dienst ist eine Attrappe, die IDs hochzaehlt;
 * das HR-Routing eine Attrappe, die jeden Aufruf mitschreibt — der Test-MA darf
 * keinen HR-Fall ausloesen.
 */
class TestEmployeeCreatorTest extends TestCase
{
    private const TEAM = 7;
    private const OWNER = 5;

    /** Stelle mit Verantwortlichem, drei Phasen, veroeffentlichter Anzeige. */
    private const POSITION = 100;
    private const PHASE_1 = 101;
    private const PHASE_2 = 102;
    private const PHASE_3 = 103;

    /** Stelle ohne Verantwortlichen (und Team ohne Owner). */
    private const POSITION_OHNE_OWNER = 200;

    /** Stelle nur mit Entwurf-Anzeige. */
    private const POSITION_OHNE_ANZEIGE = 300;

    private static object $files;
    private static object $routing;

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

        // Datei-Dienst: zaehlt IDs hoch und merkt sich jeden Upload.
        self::$files = new class extends ContextFileService {
            public array $uploads = [];
            private int $next = 9000;

            public function __construct()
            {
            }

            public function uploadForContext(UploadedFile $file, string $contextType, int $contextId, array $options = []): array
            {
                $id = ++$this->next;
                $this->uploads[] = [
                    'id'           => $id,
                    'context_type' => $contextType,
                    'context_id'   => $contextId,
                    'name'         => $file->getClientOriginalName(),
                    'bytes'        => (string) file_get_contents($file->getPathname()),
                    'team_id'      => $options['team_id'] ?? null,
                ];

                return ['id' => $id];
            }
        };
        $container->instance(ContextFileService::class, self::$files);

        // HR-Routing: darf nie gerufen werden (setEuCitizen waere der Weg dorthin).
        self::$routing = new class extends HrDeskRoutingService {
            public int $calls = 0;

            public function evaluateAndRoute(RecApplicant $applicant, ?int $userId = null): void
            {
                $this->calls++;
            }
        };
        $container->instance(HrDeskRoutingService::class, self::$routing);

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

        $schema = $capsule->getConnection()->getSchemaBuilder();
        $schema->create('teams', function ($table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
        });

        self::runRealMigrations();
        self::seed();
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
        self::$files->uploads = [];
        self::$routing->calls = 0;
    }

    public function testLegtTestMaUeberDenEchtenPfadAn(): void
    {
        $result = $this->creator()->create(self::POSITION, 'eu', 'Test Mitarbeiter', null, [], [], [], null);

        $employee = RecEmployee::find($result->employeeId);
        $applicant = RecApplicant::find($result->applicantId);

        $this->assertTrue($employee->is_test, 'der MA muss als Test-MA markiert sein');
        $this->assertSame($result->applicantId, (int) $employee->rec_applicant_id, 'MA entsteht aus DIESEM Bewerber');
        $this->assertSame('Test', $employee->first_name);
        $this->assertSame('Mitarbeiter', $employee->last_name);
        $this->assertSame('DE89370400440532013000', $employee->iban, 'IBAN kommt ueber den Feldwert und das Mapping');
        $this->assertSame('DE', $employee->nationality);

        $selfie = $this->upload('selfie_upload');
        $this->assertNotNull($selfie, 'Selfie wurde als Platzhalter hochgeladen');
        $this->assertSame($selfie['id'], (int) $employee->selfie_file_id);
        $this->assertSame(RecApplicant::class, $selfie['context_type'], 'Kontext = Bewerber, wie das Public-Form');
        $this->assertSame($result->applicantId, $selfie['context_id']);
        $this->assertSame(self::TEAM, $selfie['team_id']);
        $this->assertStringStartsWith("\x89PNG", $selfie['bytes']);

        $this->assertTrue($applicant->is_test);
        $this->assertSame(self::PHASE_2, (int) $applicant->rec_phase_id, 'Zielphase = Phase mit creates_employee_on_completion');
        $this->assertFalse((bool) $applicant->auto_pilot, 'AutoPilot bleibt aus');
        $this->assertSame(self::OWNER, (int) $applicant->owned_by_user_id);
        $this->assertSame(self::OWNER, (int) $applicant->created_by_user_id);
        $this->assertSame(1, $applicant->postings()->count(), 'haengt an der veroeffentlichten Anzeige');

        $contact = CrmContact::find($result->contactId);
        $this->assertNotNull($contact);
        $this->assertSame('Test', $contact->first_name);
        $this->assertMatchesRegularExpression(
            '/^test-ma-[A-Za-z0-9]{8}@invalid\.local$/',
            (string) $contact->emailAddresses()->value('email_address')
        );
        $this->assertSame(0, $contact->phoneNumbers()->count(), 'ohne --phone kein Telefon');

        $this->assertContains('selfie_upload', $result->filled);
        $this->assertContains('iban', $result->filled);
    }

    public function testEuProfilBekommtKeineNichtEuDateien(): void
    {
        $result = $this->creator()->create(self::POSITION, 'eu', 'Eu Test', null, [], [], [], null);

        $employee = RecEmployee::find($result->employeeId);

        $this->assertNull($this->upload('nationalpass'), 'eu-Test-MA hat keinen Nationalpass');
        $this->assertNull($employee->nationalpass_file_id);
        $this->assertContains('nationalpass', $result->empty);
        $this->assertTrue((bool) RecApplicantLegalStatus::where('rec_applicant_id', $result->applicantId)->value('is_eu_citizen'));
        $this->assertTrue((bool) $employee->is_eu_citizen);
    }

    public function testNichtEuProfil(): void
    {
        $result = $this->creator()->create(self::POSITION, 'nicht-eu', 'Nicht Eu', null, [], [], [], null);

        $employee = RecEmployee::find($result->employeeId);
        $legal = RecApplicantLegalStatus::where('rec_applicant_id', $result->applicantId)->first();
        $pass = $this->upload('nationalpass');

        $this->assertNotNull($pass);
        $this->assertSame($pass['id'], (int) $employee->nationalpass_file_id);
        $this->assertSame($pass['id'], (int) $legal->nationalpass_file_id);
        $this->assertFalse($legal->is_eu_citizen);
        $this->assertFalse((bool) $employee->is_eu_citizen);
        $this->assertSame('TR', $employee->nationality);

        $this->assertSame(0, self::$routing->calls, 'kein HR-Routing (setEuCitizen wuerde es ausloesen)');
        $this->assertSame(0, Capsule::table('rec_hr_desk_cases')->count(), 'kein HR-Fall');
    }

    public function testPflichtfeldOhneProfilwertBekommtPlatzhalter(): void
    {
        $result = $this->creator()->create(self::POSITION, 'eu', 'Pflicht Test', null, [], [], [], null);

        $applicant = RecApplicant::find($result->applicantId);

        $this->assertSame('Test', $applicant->getExtraField('lieblingsfarbe'), 'Pflicht-Text ohne Profilwert → „Test"');
        $this->assertNull($applicant->getExtraField('hobby'), 'optionales Feld ohne Profilwert bleibt leer');
        $this->assertContains('hobby', $result->empty);

        $nachherId = CoreExtraFieldDefinition::where('name', 'nachher')->value('id');
        $this->assertSame(
            0,
            Capsule::table('core_extra_field_values')->where('definition_id', $nachherId)->where('fieldable_id', $result->applicantId)->count(),
            'Felder NACH der Zielphase werden nicht befuellt, auch wenn Pflicht'
        );
        $this->assertNotContains('nachher', array_merge($result->filled, $result->empty));
    }

    public function testSelectProfilwertWirdGegenDieOptionenAufgeloest(): void
    {
        $result = $this->creator()->create(self::POSITION, 'eu', 'Select Test', null, [], [], [], null);

        $employee = RecEmployee::find($result->employeeId);

        $this->assertSame('Ledig', $employee->marital_status, 'Profilwert „ledig" trifft die Option „Ledig" (Gross-/Kleinschreibung egal)');
        $this->assertSame('w', $employee->gender, 'Profilwert ohne passende Option → erster Optionswert');
    }

    public function testLeerGewinnt(): void
    {
        $result = $this->creator()->create(
            self::POSITION, 'eu', 'Leer Test', null,
            ['iban' => 'DE02120300000000202051'], [], ['iban', 'selfie_upload'], null
        );

        $employee = RecEmployee::find($result->employeeId);

        $this->assertNull($employee->iban, '--leer schlaegt Profil UND --set');
        $this->assertNull($employee->selfie_file_id);
        $this->assertNull($this->upload('selfie_upload'));
        $this->assertContains('iban', $result->empty);
    }

    public function testSetUeberschreibt(): void
    {
        $result = $this->creator()->create(
            self::POSITION, 'eu', 'Set Test', null, ['iban' => 'DE02120300000000202051'], [], [], null
        );

        $this->assertSame('DE02120300000000202051', RecEmployee::find($result->employeeId)->iban);
    }

    public function testDateiNimmtDieEchteDatei(): void
    {
        $pfad = tempnam(sys_get_temp_dir(), 'test-ma-');
        file_put_contents($pfad, 'ECHTES-BILD');

        try {
            $result = $this->creator()->create(
                self::POSITION, 'eu', 'Datei Test', null, [], ['selfie_upload' => $pfad], [], null
            );
        } finally {
            @unlink($pfad);
        }

        $this->assertCount(1, array_filter(self::$files->uploads, fn ($u) => $u['bytes'] === 'ECHTES-BILD'));
        $echt = array_values(array_filter(self::$files->uploads, fn ($u) => $u['bytes'] === 'ECHTES-BILD'))[0];
        $this->assertSame(basename($pfad), $echt['name'], 'Originalname der echten Datei bleibt');
        $this->assertNull($this->upload('selfie_upload'), 'kein Platzhalter, wenn eine echte Datei kommt');
        $this->assertSame($echt['id'], (int) RecEmployee::find($result->employeeId)->selfie_file_id);
    }

    public function testTelefonLandetAmKontakt(): void
    {
        $result = $this->creator()->create(self::POSITION, 'eu', 'Telefon Test', '+4915112345678', [], [], [], null);

        $contact = CrmContact::find($result->contactId);
        $this->assertSame('+4915112345678', $contact->phoneNumbers()->value('raw_input'));
        $this->assertSame('+4915112345678', RecEmployee::find($result->employeeId)->phone);
    }

    public function testUnbekanntesFeldBrichtVorJedemSchreibenAb(): void
    {
        foreach ([
            [['ibna' => 'x'], [], []],
            [[], ['selfi' => __FILE__], []],
            [[], [], ['nachher']], // existiert, aber erst NACH der Zielphase
        ] as [$set, $files, $leer]) {
            $vorher = $this->zaehler();

            try {
                $this->creator()->create(self::POSITION, 'eu', 'Tipp Fehler', null, $set, $files, $leer, null);
                $this->fail('unbekanntes Feld muss abbrechen');
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('Unbekannt', $e->getMessage());
            }

            $this->assertSame($vorher, $this->zaehler(), 'nichts angelegt');
        }
    }

    public function testUngueltigeEingabenBrechenVorJedemSchreibenAb(): void
    {
        $faelle = [
            'Profil'        => fn () => $this->creator()->create(self::POSITION, 'rentner', 'X Y', null, [], [], [], null),
            'Datei fehlt'   => fn () => $this->creator()->create(self::POSITION, 'eu', 'X Y', null, [], ['selfie_upload' => '/nicht/da.jpg'], [], null),
            'kein Datei-Feld' => fn () => $this->creator()->create(self::POSITION, 'eu', 'X Y', null, [], ['iban' => __FILE__], [], null),
            'Stelle'        => fn () => $this->creator()->create(999999, 'eu', 'X Y', null, [], [], [], null),
        ];

        foreach ($faelle as $fall => $aufruf) {
            $vorher = $this->zaehler();
            try {
                $aufruf();
                $this->fail("{$fall}: muss abbrechen");
            } catch (\InvalidArgumentException) {
            }
            $this->assertSame($vorher, $this->zaehler(), "{$fall}: nichts angelegt");
        }
    }

    public function testOhneAusschreibungBrichtAb(): void
    {
        $vorher = $this->zaehler();

        try {
            $this->creator()->create(self::POSITION_OHNE_ANZEIGE, 'eu', 'X Y', null, [], [], [], null);
            $this->fail('ohne veroeffentlichte Ausschreibung muss abbrechen');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('Ausschreibung', $e->getMessage());
        }

        $this->assertSame($vorher, $this->zaehler());
    }

    public function testOhneOwnerBrichtAb(): void
    {
        $vorher = $this->zaehler();

        try {
            $this->creator()->create(self::POSITION_OHNE_OWNER, 'eu', 'X Y', null, [], [], [], null);
            $this->fail('ohne Owner muss abbrechen');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('--owner', $e->getMessage());
        }

        $this->assertSame($vorher, $this->zaehler());

        // Gegenprobe: mit ausdruecklichem Owner geht es.
        $result = $this->creator()->create(self::POSITION_OHNE_OWNER, 'eu', 'X Y', null, [], [], [], 42);
        $this->assertSame(42, (int) RecApplicant::find($result->applicantId)->owned_by_user_id);
    }

    public function testSozialversicherungsnummerHatGueltigePruefziffer(): void
    {
        // 65 010195 T 00 → Ziffern 6 5 0 1 0 1 9 5 2 0 0 0 (T = 20),
        // Gewichte 2 1 2 5 7 1 2 1 2 1 2 1, Quersummen 3+5+0+5+0+1+9+5+4+0+0+0 = 32 → 2.
        $this->assertSame('65010195T002', TestEmployeeProfiles::values('eu')['sozialversicherungsnummer']);
        $this->assertSame('1995-01-01', TestEmployeeProfiles::values('eu')['geburtsdatum']);
    }

    public function testPlatzhalterbildIstEinPng(): void
    {
        $info = getimagesizefromstring(PlaceholderImage::png('TEST – Selfie'));

        $this->assertSame([600, 400], [$info[0], $info[1]]);
        $this->assertSame('image/png', $info['mime']);
    }

    private function creator(): TestEmployeeCreator
    {
        return new TestEmployeeCreator();
    }

    private function upload(string $feld): ?array
    {
        foreach (self::$files->uploads as $upload) {
            if (str_contains($upload['name'], $feld)) {
                return $upload;
            }
        }

        return null;
    }

    /** @return array<string,int> */
    private function zaehler(): array
    {
        return [
            'applicants' => Capsule::table('rec_applicants')->count(),
            'contacts'   => Capsule::table('crm_contacts')->count(),
            'links'      => Capsule::table('crm_contact_links')->count(),
            'employees'  => Capsule::table('rec_employees')->count(),
            'values'     => Capsule::table('core_extra_field_values')->count(),
            'uploads'    => count(self::$files->uploads),
        ];
    }

    private static function seed(): void
    {
        $db = Capsule::connection();
        $now = date('Y-m-d H:i:s');

        $db->table('teams')->insert(['id' => self::TEAM, 'user_id' => null]);
        $db->table('crm_email_types')->insert(['id' => 1, 'uuid' => 'et-1', 'code' => 'PRIVATE', 'name' => 'Privat', 'is_active' => true]);
        $db->table('crm_phone_types')->insert(['id' => 1, 'uuid' => 'pt-1', 'code' => 'MOBILE', 'name' => 'Mobil', 'is_active' => true]);

        foreach ([
            [self::POSITION, self::OWNER],
            [self::POSITION_OHNE_OWNER, null],
            [self::POSITION_OHNE_ANZEIGE, self::OWNER],
        ] as [$id, $owner]) {
            $db->table('rec_positions')->insert([
                'id' => $id, 'uuid' => 'pos-' . $id, 'team_id' => self::TEAM, 'title' => 'Stelle ' . $id,
                'is_active' => true, 'owned_by_user_id' => $owner, 'created_at' => $now, 'updated_at' => $now,
            ]);
            $db->table('rec_postings')->insert([
                'id' => $id, 'uuid' => 'post-' . $id, 'rec_position_id' => $id, 'team_id' => self::TEAM,
                'title' => 'Anzeige ' . $id, 'status' => $id === self::POSITION_OHNE_ANZEIGE ? 'draft' : 'published',
                'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }

        $phasen = [
            [self::PHASE_1, self::POSITION, 1, null],
            [self::PHASE_2, self::POSITION, 2, ['creates_employee_on_completion' => true]],
            [self::PHASE_3, self::POSITION, 3, null],
            [201, self::POSITION_OHNE_OWNER, 1, null],
            [301, self::POSITION_OHNE_ANZEIGE, 1, null],
        ];
        foreach ($phasen as [$id, $position, $order, $config]) {
            $db->table('rec_phases')->insert([
                'id' => $id, 'uuid' => 'phase-' . $id, 'team_id' => self::TEAM, 'rec_position_id' => $position,
                'name' => 'Phase ' . $order, 'order' => $order, 'is_active' => true,
                'completion_config' => $config === null ? null : json_encode($config),
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }

        $felder = [
            [self::PHASE_1, 'vorname', 'text', false, null],
            [self::PHASE_1, 'nachname', 'text', false, null],
            [self::PHASE_1, 'iban', 'text', false, null],
            [self::PHASE_1, 'nationalitaet', 'text', false, null],
            [self::PHASE_1, 'hobby', 'text', false, null],
            [self::PHASE_1, 'geschlecht', 'select', true, ['choices' => ['w', 'm', 'd']]],
            [self::PHASE_1, 'familienstand', 'select', false, ['choices' => ['verheiratet', 'Ledig']]],
            [self::PHASE_1, 'telefonnummer', 'phone', false, null],
            [self::PHASE_2, 'selfie_upload', 'file', false, null],
            [self::PHASE_2, 'nationalpass', 'file', false, null],
            [self::PHASE_2, 'lieblingsfarbe', 'text', true, null],
            [self::PHASE_3, 'nachher', 'text', true, null],
            [201, 'vorname', 'text', false, null],
        ];
        foreach ($felder as $i => [$phase, $name, $type, $required, $options]) {
            CoreExtraFieldDefinition::create([
                'team_id' => self::TEAM, 'context_type' => RecPhase::class, 'context_id' => $phase,
                'name' => $name, 'label' => ucfirst($name), 'type' => $type,
                'is_required' => $required, 'is_mandatory' => false, 'order' => $i, 'options' => $options,
            ]);
        }
    }

    /** Siehe EmployeeCreationCertificateTest::leereExtraFieldCache() — hier fuer Bewerber UND Phase. */
    private static function leereExtraFieldCache(): void
    {
        foreach ([RecApplicant::class, RecPhase::class] as $class) {
            foreach (['extraFieldDefinitionsCache', 'extraFieldInheritanceStack'] as $name) {
                $property = new \ReflectionProperty($class, $name);
                $property->setValue(null, []);
            }
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
        ];
        // Alle Feld-Migrationen: is_mandatory/visibility_config liest der Creator mit.
        $extraFelder = glob($core . '/database/migrations/*extra_field*.php');
        sort($extraFelder);
        $fremd = array_merge($fremd, $extraFelder, [
            $crm . '/database/migrations/2024_01_01_000011_create_crm_phone_types_table.php',
            $crm . '/database/migrations/2024_01_01_000012_create_crm_email_types_table.php',
            $crm . '/database/migrations/2024_01_01_000016_create_crm_contacts_table.php',
            $crm . '/database/migrations/2024_01_01_000020_create_crm_contact_links_table.php',
            $crm . '/database/migrations/2026_02_18_220000_make_created_by_user_id_nullable_on_crm_contact_links.php',
        ]);
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
