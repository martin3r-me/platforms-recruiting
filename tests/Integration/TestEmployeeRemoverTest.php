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
use Platform\Crm\Models\CrmContactLink;
use Platform\Core\Models\CoreExtraFieldDefinition;
use Platform\Core\Models\User;
use Platform\Core\Services\ContextFileService;
use Platform\Crm\Models\CrmContact;
use Platform\Recruiting\Models\RecApplicant;
use Platform\Recruiting\Models\RecApplicantLegalStatus;
use Platform\Recruiting\Models\RecEmployee;
use Platform\Recruiting\Models\RecPhase;
use Platform\Recruiting\Services\HrDeskRoutingService;
use Platform\Recruiting\Services\TestEmployee\TestEmployeeCreator;
use Platform\Recruiting\Services\TestEmployee\TestEmployeeRemover;

/**
 * recruiting:test-ma --remove: Test-MA restlos entfernen. Fixture ist der
 * TestEmployeeCreator (echter Anlegepfad) — so wird auch alles mitgeprueft,
 * was der Creator nebenbei schreibt (Phasen-Uebergang, AutoPilot-Log, Pivot,
 * Rechtsstatus, Dateien, Kontakt-Mail/-Telefon).
 *
 * Aufbau wie TestEmployeeCreatorTest (Container + Capsule von Hand, alle
 * eigenen Migrationen). Der Datei-Dienst ist eine Attrappe, die IDs hochzaehlt;
 * das HR-Routing eine Attrappe, die jeden Aufruf mitschreibt — der Test-MA darf
 * keinen HR-Fall ausloesen.
 */
class TestEmployeeRemoverTest extends TestCase
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
            public array $deleted = [];
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

                Capsule::table('context_files')->insert([
                    'id' => $id, 'context_type' => $contextType, 'context_id' => $contextId,
                ]);

                return ['id' => $id];
            }

            public function delete(int $contextFileId, ?int $teamId = null): void
            {
                $this->deleted[] = $contextFileId;
                Capsule::table('context_files')->where('id', $contextFileId)->delete();
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

        // Minimal: nur die Spalten, die Creator-Attrappe und Remover anfassen.
        $schema->create('context_files', function ($table) {
            $table->unsignedBigInteger('id')->primary();
            $table->string('context_type');
            $table->unsignedBigInteger('context_id');
            $table->timestamps();
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
        self::$files->deleted = [];
        self::$routing->calls = 0;
    }


    public function testEntferntAllesRestlos(): void
    {
        $echt = $this->creator()->create(self::POSITION, 'eu', 'Echter Rest', null, [], [], [], null);
        $this->markiereAlsEcht($echt);
        $result = $this->creator()->create(self::POSITION, 'nicht-eu', 'Test Weg', '+4915112345678', [], [], [], null);
        $this->nebendatenAnlegen($result);

        // Ausgangslage: der Aufraeum-Test prueft nur, wenn auch etwas da ist.
        foreach ($this->zeilen($result) as $tabelle => $anzahl) {
            $this->assertGreaterThan(0, $anzahl, "Fixture: {$tabelle} muss Zeilen haben");
        }
        $fremdVorher = $this->fremdZeilen($echt);

        $report = $this->remover()->remove($result->employeeId);

        $this->assertNotSame([], $report);
        foreach ($this->zeilen($result) as $tabelle => $anzahl) {
            $this->assertSame(0, $anzahl, "{$tabelle} muss leer sein");
        }
        $this->assertNotEmpty(self::$files->deleted, 'Dateien laufen ueber den Core-Loeschweg');
        $this->assertSame(0, Capsule::table('context_files')->whereIn('id', self::$files->deleted)->count());

        // Gegenprobe: der echte MA (gleiche Stelle, gleiches Team) bleibt unberuehrt.
        $this->assertSame($fremdVorher, $this->fremdZeilen($echt));
    }

    public function testVerweigertEchtenMa(): void
    {
        $result = $this->creator()->create(self::POSITION, 'eu', 'Echter Ma', null, [], [], [], null);
        $this->markiereAlsEcht($result);
        $vorher = $this->zeilen($result);

        try {
            $this->remover()->remove($result->employeeId);
            $this->fail('echter MA darf nicht geloescht werden');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('Test-MA', $e->getMessage());
        }

        $this->assertSame($vorher, $this->zeilen($result), 'nichts geloescht');
        $this->assertSame([], self::$files->deleted);
    }

    public function testVerweigertWennDerBewerberKeinTestBewerberIst(): void
    {
        $result = $this->creator()->create(self::POSITION, 'eu', 'Echter Bewerber', null, [], [], [], null);
        Capsule::table('rec_applicants')->where('id', $result->applicantId)->update(['is_test' => false]);
        $vorher = $this->zeilen($result);

        try {
            $this->remover()->remove($result->employeeId);
            $this->fail('echter Bewerber darf nicht geloescht werden');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('Bewerber', $e->getMessage());
        }

        $this->assertSame($vorher, $this->zeilen($result));
    }

    public function testZweiterAufrufIstNoop(): void
    {
        $result = $this->creator()->create(self::POSITION, 'eu', 'Zweimal Weg', null, [], [], [], null);

        $this->assertNotSame([], $this->remover()->remove($result->employeeId));
        $this->assertSame([], $this->remover()->remove($result->employeeId));
        $this->assertSame([], $this->remover()->remove(999999), 'unbekannte ID ist ebenfalls ein No-op');
    }

    public function testKontaktMitFremdemLinkBleibtBestehen(): void
    {
        $result = $this->creator()->create(self::POSITION, 'eu', 'Geteilter Kontakt', '+4915112345678', [], [], [], null);
        Capsule::table('crm_contact_links')->insert([
            'uuid' => 'fremd-link', 'contact_id' => $result->contactId, 'linkable_type' => 'fremdes_modul', 'linkable_id' => 1,
            'team_id' => self::TEAM, 'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
        ]);

        $report = $this->remover()->remove($result->employeeId);

        $this->assertSame(1, Capsule::table('crm_contacts')->where('id', $result->contactId)->count(), 'Kontakt gehoert auch anderen');
        $this->assertSame(1, $report['crm_contacts_behalten'] ?? 0, 'wird im Report genannt');
        $this->assertSame(
            1,
            Capsule::table('crm_contact_links')->where('contact_id', $result->contactId)->count(),
            'nur unsere Links weg, der fremde bleibt'
        );
        $this->assertSame(0, Capsule::table('rec_employees')->where('id', $result->employeeId)->count());
        $this->assertSame(0, Capsule::table('rec_applicants')->where('id', $result->applicantId)->count());
    }

    public function testVerweigertBeiDispoBuchungUndNenntDieRef(): void
    {
        $result = $this->creator()->create(self::POSITION, 'eu', 'Gebucht Test', null, [], [], [], null);
        $now = date('Y-m-d H:i:s');
        Capsule::table('rec_dispo_events')->insert([
            'id' => 1, 'uuid' => 'ev-1', 'einsatz_ref' => 'TEST-VA', 'created_at' => $now, 'updated_at' => $now,
        ]);
        Capsule::table('rec_dispo_assignments')->insert([
            'uuid' => 'as-1', 'ds_ref' => '1', 'pnr_raw' => '1', 'datum' => '2026-11-01', 'rec_dispo_event_id' => 1, 'rec_employee_id' => $result->employeeId,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $vorher = $this->zeilen($result);

        try {
            $this->remover()->remove($result->employeeId);
            $this->fail('MA mit Dispo-Buchung darf nicht geloescht werden');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('recruiting:dispo-test-va --remove --ref=TEST-VA', $e->getMessage());
        }

        $this->assertSame($vorher, $this->zeilen($result), 'nichts geloescht');
        $this->assertSame([], self::$files->deleted);
    }

    public function testDateiMitKlartextKontextWirdGeloescht(): void
    {
        $result = $this->creator()->create(self::POSITION, 'eu', 'Datei Kontext', null, [], [], [], null);
        Capsule::table('context_files')->insert(['id' => 7777, 'context_type' => 'rec_employee', 'context_id' => $result->employeeId]);
        Capsule::table('context_files')->insert(['id' => 7778, 'context_type' => 'rec_applicant', 'context_id' => $result->applicantId]);
        Capsule::table('context_files')->insert(['id' => 7779, 'context_type' => 'rec_employee', 'context_id' => $result->employeeId + 1000]);

        $this->remover()->remove($result->employeeId);

        $this->assertContains(7777, self::$files->deleted);
        $this->assertContains(7778, self::$files->deleted);
        $this->assertNotContains(7779, self::$files->deleted, 'Datei eines anderen MA bleibt');
        $this->assertSame(1, Capsule::table('context_files')->where('id', 7779)->count());
    }

    private function remover(): TestEmployeeRemover
    {
        return new TestEmployeeRemover();
    }

    private function markiereAlsEcht(\Platform\Recruiting\Services\TestEmployee\TestEmployeeResult $r): void
    {
        Capsule::table('rec_employees')->where('id', $r->employeeId)->update(['is_test' => false]);
        Capsule::table('rec_applicants')->where('id', $r->applicantId)->update(['is_test' => false]);
    }

    /** Was der Creator nicht anlegt, der Remover aber mitnehmen muss. */
    private function nebendatenAnlegen(\Platform\Recruiting\Services\TestEmployee\TestEmployeeResult $r): void
    {
        $now = date('Y-m-d H:i:s');
        $db = Capsule::connection();

        $listeId = $db->table('crm_contact_lists')->insertGetId([
            'uuid' => 'liste-' . $r->employeeId, 'name' => 'Aktive Mitarbeiter', 'team_id' => self::TEAM,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $db->table('crm_contact_list_members')->insert([
            'uuid' => 'mem-' . $r->employeeId, 'contact_list_id' => $listeId, 'contact_id' => $r->contactId,
            'status' => 'subscribed', 'created_at' => $now, 'updated_at' => $now,
        ]);
        // Phasen-Uebergang und AutoPilot-Log schreiben in Produktion Observer/Trait,
        // die in dieser Container-Suite nicht laufen — wo sie fehlen, hier nachstellen.
        if ($db->table('rec_phase_transitions')->where('rec_applicant_id', $r->applicantId)->count() === 0) {
            $db->table('rec_phase_transitions')->insert([
                'team_id' => self::TEAM, 'rec_applicant_id' => $r->applicantId, 'to_phase_id' => self::PHASE_2,
                'trigger' => 'unknown', 'source' => 'live', 'occurred_at' => $now, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
        if ($db->table('rec_auto_pilot_logs')->where('rec_applicant_id', $r->applicantId)->count() === 0) {
            $db->table('rec_auto_pilot_logs')->insert([
                'rec_applicant_id' => $r->applicantId, 'type' => 'anzeige_verknuepft', 'summary' => 'x',
            ]);
        }
        // Produktive Upload-Pfade speichern den Kontext als 'rec_employee' (Portal, HR, ZAS).
        $db->table('context_files')->insert(['id' => 8000 + $r->employeeId, 'context_type' => 'rec_employee', 'context_id' => $r->employeeId]);
        $db->table('rec_dispo_job_offers')->insert([
            'id' => $r->employeeId, 'wave_id' => 1, 'request_id' => 1, 'rec_employee_id' => $r->employeeId,
            'anchor_employee_id' => $r->employeeId, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $db->table('rec_dispo_job_offer_shifts')->insert(['offer_id' => $r->employeeId, 'shift_id' => 1, 'created_at' => $now, 'updated_at' => $now]);
        $db->table('rec_training_certificates')->insert([
            'uuid' => 'cert-' . $r->employeeId, 'team_id' => self::TEAM, 'rec_applicant_id' => $r->applicantId,
            'kind' => 'service-basis', 'personalized_content' => 'x', 'issued_at' => $now,
            'created_at' => $now, 'updated_at' => $now,
        ]);
    }

    /** @return array<string,int> Zeilen, die zu diesem Test-MA gehoeren. */
    private function zeilen(\Platform\Recruiting\Services\TestEmployee\TestEmployeeResult $r): array
    {
        $db = Capsule::connection();
        $applicantMorph = (new RecApplicant())->getMorphClass();
        $employeeMorph = (new RecEmployee())->getMorphClass();

        return [
            'rec_employees'                => $db->table('rec_employees')->where('id', $r->employeeId)->count(),
            'rec_employee_hr_data'         => $db->table('rec_employee_hr_data')->where('rec_employee_id', $r->employeeId)->count(),
            'rec_applicants'               => $db->table('rec_applicants')->where('id', $r->applicantId)->count(),
            'rec_applicant_posting'        => $db->table('rec_applicant_posting')->where('rec_applicant_id', $r->applicantId)->count(),
            'rec_phase_transitions'        => $db->table('rec_phase_transitions')->where('rec_applicant_id', $r->applicantId)->count(),
            'rec_auto_pilot_logs'          => $db->table('rec_auto_pilot_logs')->where('rec_applicant_id', $r->applicantId)->count(),
            'rec_applicant_legal_statuses' => $db->table('rec_applicant_legal_statuses')->where('rec_applicant_id', $r->applicantId)->count(),
            'rec_training_certificates'    => $db->table('rec_training_certificates')->where('rec_applicant_id', $r->applicantId)->count(),
            'core_extra_field_values'      => $db->table('core_extra_field_values')->where('fieldable_id', $r->applicantId)->count(),
            'crm_contacts'                 => $db->table('crm_contacts')->where('id', $r->contactId)->count(),
            'crm_contact_links'            => $db->table('crm_contact_links')->where('contact_id', $r->contactId)->count(),
            'crm_contact_list_members'     => $db->table('crm_contact_list_members')->where('contact_id', $r->contactId)->count(),
            'crm_email_addresses'          => $db->table('crm_email_addresses')->where('emailable_id', $r->contactId)->count(),
            'crm_phone_numbers'            => $db->table('crm_phone_numbers')->where('phoneable_id', $r->contactId)->count(),
            'context_files'                => $db->table('context_files')->where(fn ($q) => $q
                ->whereIn('context_type', ['rec_applicant', RecApplicant::class])->where('context_id', $r->applicantId)
                ->orWhereIn('context_type', ['rec_employee', RecEmployee::class])->where('context_id', $r->employeeId))->count(),
            'rec_dispo_job_offers'         => $db->table('rec_dispo_job_offers')->where('rec_employee_id', $r->employeeId)->count(),
            'rec_dispo_job_offer_shifts'   => $db->table('rec_dispo_job_offer_shifts')->where('offer_id', $r->employeeId)->count(),
        ];
    }

    /** Alles, was einem anderen (echten) MA gehoert — muss das Loeschen ueberleben. */
    private function fremdZeilen(\Platform\Recruiting\Services\TestEmployee\TestEmployeeResult $r): array
    {
        return $this->zeilen($r);
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
        foreach ([
            '2026_04_23_000001_create_crm_contact_lists_table.php',
            '2026_04_23_000002_create_crm_contact_list_members_table.php',
            '2026_05_15_000002_add_subscription_fields_to_crm_contact_list_members_table.php',
        ] as $liste) {
            $fremd[] = $crm . '/database/migrations/' . $liste;
        }
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
