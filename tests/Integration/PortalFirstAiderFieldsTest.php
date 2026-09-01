<?php

namespace Platform\Recruiting\Tests\Integration;

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Events\Dispatcher;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Livewire\Employees\Show;
use Platform\Recruiting\Livewire\Public\EmployeePortal;
use Platform\Recruiting\Models\RecEmployee;

/**
 * Ersthelfer im MA-Portal (Spec 2026-09-01, Kundenwunsch Markus/Clara):
 * Der MA gibt selbst an, ob er Ersthelfer ist. Bei "Ja" sind Bis-Datum und
 * hochgeladener Schein Pflicht, bei "Nein" passiert nichts.
 *
 * Geprueft wird der Datenseam, den die Livewire-Komponenten lesen —
 * RecEmployee::editableFieldGroups()/missingFields() und die HR-Gruppen in
 * Employees/Show. Kein Livewire-Test-Harness in dieser Suite (siehe
 * reference_phpunit_runner), der Blade-Zweig ist Code-Review.
 */
class PortalFirstAiderFieldsTest extends TestCase
{
    private const TEAM = 611;

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

        // Show::fieldGroups() ruft ueber die employee()-Computed
        // auth()->user()->currentTeam->id. Ohne Laravel-Bootstrap gibt es
        // keinen Auth-Guard — hier reicht ein Stub, der genau diese Kette
        // beantwortet. Team-Id ist beliebig: die Abfrage laeuft gegen ein
        // leeres rec_employees und liefert null, die Feldliste ist statisch.
        // Show::fieldGroups() ruft ueber die employee()-Computed
        // auth()->user()->currentTeam->id. Ohne Laravel-Bootstrap gibt es
        // keinen Guard — dieser Stub beantwortet genau diese Kette. Die
        // Team-Id ist beliebig: die Abfrage laeuft gegen ein leeres
        // rec_employees, geprueft wird die statische Feldliste.
        $container->instance(\Illuminate\Contracts\Auth\Factory::class, new AuthGuardStub(self::TEAM));

        $container->instance('db', $capsule->getDatabaseManager());
        $container->instance('db.schema', $capsule->getConnection()->getSchemaBuilder());
        Facade::setFacadeApplication($container);

        self::runMigrations();
    }

    public static function tearDownAfterClass(): void
    {
        Facade::clearResolvedInstances();
    }

    protected function setUp(): void
    {
        Capsule::table('rec_employees')->delete();
    }

    private function makeEmployee(array $attributes = []): RecEmployee
    {
        return RecEmployee::create(array_merge([
            'team_id'      => self::TEAM,
            'first_name'   => 'Erika',
            'last_name'    => 'Muster',
            'phone'        => '+49 151 00000002',
            'portal_token' => 'tok-first-aider-' . uniqid(),
            'is_active'    => true,
        ], $attributes));
    }

    public function test_portal_offers_the_three_first_aider_fields(): void
    {
        $groups = $this->makeEmployee()->editableFieldGroups();

        $this->assertArrayHasKey('Arbeitsschutz', $groups);
        $this->assertSame(
            ['is_first_aider', 'first_aider_valid_until', 'first_aider_certificate_file_id'],
            array_keys($groups['Arbeitsschutz']),
        );
    }

    public function test_unanswered_flag_is_reported_as_missing(): void
    {
        $missing = $this->makeEmployee(['is_first_aider' => null])->missingFields();

        $this->assertArrayHasKey('is_first_aider', $missing);
    }

    public function test_no_follow_up_fields_are_demanded_while_flag_is_unanswered(): void
    {
        $missing = $this->makeEmployee(['is_first_aider' => null])->missingFields();

        $this->assertArrayNotHasKey('first_aider_valid_until', $missing);
        $this->assertArrayNotHasKey('first_aider_certificate_file_id', $missing);
    }

    public function test_answering_no_demands_nothing(): void
    {
        $missing = $this->makeEmployee(['is_first_aider' => false])->missingFields();

        $this->assertArrayNotHasKey('is_first_aider', $missing);
        $this->assertArrayNotHasKey('first_aider_valid_until', $missing);
        $this->assertArrayNotHasKey('first_aider_certificate_file_id', $missing);
    }

    public function test_answering_yes_demands_date_and_certificate(): void
    {
        $missing = $this->makeEmployee(['is_first_aider' => true])->missingFields();

        $this->assertArrayHasKey('first_aider_valid_until', $missing);
        $this->assertArrayHasKey('first_aider_certificate_file_id', $missing);
    }

    public function test_complete_yes_answer_demands_nothing(): void
    {
        $missing = $this->makeEmployee([
            'is_first_aider'                  => true,
            'first_aider_valid_until'         => '2028-03-01',
            'first_aider_certificate_file_id' => 4242,
        ])->missingFields();

        $this->assertArrayNotHasKey('first_aider_valid_until', $missing);
        $this->assertArrayNotHasKey('first_aider_certificate_file_id', $missing);
    }

    public function test_hr_view_shows_the_uploaded_certificate(): void
    {
        $show = new Show();
        // Ohne mount(): eine Id, die keinen Datensatz trifft. Die Feldliste
        // haengt nicht am MA, nur die Non-EU-Sektion — und die ist hier egal.
        $show->employeeId = 0;

        $fields = $show->fieldGroups()['Arbeitsschutz'] ?? [];

        $this->assertArrayHasKey('first_aider_certificate_file_id', $fields);
        $this->assertSame('file', $fields['first_aider_certificate_file_id']['type']);
    }


    /**
     * Der harte Block im Portal (Kundenwunsch 2026-09-01): "Ja" ohne Datum
     * oder ohne Schein darf NICHTS speichern — auch keine unbeteiligten
     * Felder. Endzustands-Pruefung wie in der HR-Akte.
     */
    public function test_portal_save_is_blocked_when_yes_lacks_date_and_certificate(): void
    {
        $employee = $this->makeEmployee(['city' => 'Koeln']);

        $portal = $this->portalFor($employee, [
            'is_first_aider'                  => '1',
            'first_aider_valid_until'         => '',
            'first_aider_certificate_file_id' => '',
            'city'                            => 'Duesseldorf',
        ]);
        $portal->saveAll();

        $this->assertNotNull($portal->editError);
        $this->assertSame('Koeln', $employee->fresh()->city, 'Unbeteiligtes Feld darf nicht durchrutschen');
        $this->assertNull($employee->fresh()->is_first_aider);
    }

    public function test_portal_save_is_blocked_when_only_the_certificate_is_missing(): void
    {
        $employee = $this->makeEmployee();

        $portal = $this->portalFor($employee, [
            'is_first_aider'                  => '1',
            'first_aider_valid_until'         => '2028-03-01',
            'first_aider_certificate_file_id' => '',
        ]);
        $portal->saveAll();

        $this->assertNotNull($portal->editError);
        $this->assertNull($employee->fresh()->is_first_aider);
    }

    public function test_portal_save_passes_when_yes_is_complete(): void
    {
        $employee = $this->makeEmployee(['first_aider_certificate_file_id' => 4242]);

        $portal = $this->portalFor($employee, [
            'is_first_aider'                  => '1',
            'first_aider_valid_until'         => '2028-03-01',
            'first_aider_certificate_file_id' => '4242',
        ]);
        $portal->saveAll();

        $this->assertNull($portal->editError);
        $this->assertTrue($employee->fresh()->is_first_aider);
    }

    public function test_portal_save_passes_when_answer_is_no(): void
    {
        $employee = $this->makeEmployee();

        $portal = $this->portalFor($employee, [
            'is_first_aider'          => '0',
            'first_aider_valid_until' => '',
        ]);
        $portal->saveAll();

        $this->assertNull($portal->editError);
        $this->assertFalse($employee->fresh()->is_first_aider);
    }

    /**
     * Der Schein liegt NICHT in $fieldValues (Dateien laufen ueber die
     * Upload-Properties), der Guard muss ihn also vom Datensatz lesen.
     */
    public function test_portal_reads_the_certificate_from_the_record_not_the_form(): void
    {
        $employee = $this->makeEmployee(['first_aider_certificate_file_id' => 4242]);

        $portal = $this->portalFor($employee, [
            'is_first_aider'          => '1',
            'first_aider_valid_until' => '2028-03-01',
        ]);
        $portal->saveAll();

        $this->assertNull($portal->editError);
        $this->assertTrue($employee->fresh()->is_first_aider);
    }


    /**
     * Dateien laufen ausschliesslich ueber die Upload-Properties. Ein aus
     * dem Formular mitgeschickter File-Wert darf den Datensatz nicht
     * anfassen — sonst koennte derselbe Request den Guard passieren und
     * den Schein danach leeren.
     */
    public function test_portal_ignores_file_ids_coming_from_the_form(): void
    {
        $employee = $this->makeEmployee(['first_aider_certificate_file_id' => 4242]);

        $portal = $this->portalFor($employee, [
            'is_first_aider'                  => '1',
            'first_aider_valid_until'         => '2028-03-01',
            'first_aider_certificate_file_id' => '',
        ]);
        $portal->saveAll();

        $this->assertSame(4242, $employee->fresh()->first_aider_certificate_file_id);
    }

    private function portalFor(RecEmployee $employee, array $fieldValues): EmployeePortal
    {
        $portal = new EmployeePortal();
        $portal->state = 'verified';
        $portal->employeeId = $employee->id;
        $portal->fieldValues = $fieldValues;

        return $portal;
    }

    private static function runMigrations(): void
    {
        $own = dirname(__DIR__, 2);

        $files = [
            'database/migrations/2026_05_20_000001_create_rec_employees_table.php',
            'database/migrations/2026_07_17_000001_add_arbeitsschutz_fields_to_rec_employees.php',
            'database/migrations/2026_09_01_000001_add_first_aider_certificate_file_id_to_rec_employees.php',
        ];

        foreach ($files as $relative) {
            $path = $own . '/' . $relative;
            if (!file_exists($path)) {
                throw new \RuntimeException("Migration fehlt: {$path}");
            }
            (require $path)->up();
        }
    }
}

/**
 * Minimaler Guard, damit auth()->user()->currentTeam->id in der HR-Akte
 * aufloest. Nur fuer diesen Test — kein Login, keine Rechte.
 */
class AuthGuardStub implements \Illuminate\Contracts\Auth\Guard
{
    public function __construct(private int $teamId)
    {
    }

    public function user(): object
    {
        return (object) ['currentTeam' => (object) ['id' => $this->teamId]];
    }

    public function check(): bool
    {
        return true;
    }

    public function guest(): bool
    {
        return false;
    }

    public function id(): int
    {
        return 1;
    }

    public function validate(array $credentials = []): bool
    {
        return true;
    }

    public function hasUser(): bool
    {
        return true;
    }

    public function setUser(\Illuminate\Contracts\Auth\Authenticatable $user): void
    {
    }
}
