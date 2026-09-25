<?php

namespace Platform\Recruiting\Tests\Integration;

use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Livewire\Public\EmployeePortal;
use Platform\Recruiting\Models\RecEmployee;

/**
 * Haupt-/Nebenarbeitgeber ist im MA-Portal Pflicht (Markus 24.09.2026).
 * saveAll() blockt ohne Angabe — auch Saves, die nur andere Felder aendern.
 *
 * DIE FALLE, die dieser Test festnagelt: is_main_employer ist als boolean
 * gecastet und dreiwertig. `false` (= "nein, wir sind es nicht") wird beim
 * Umwandeln in Text zu einem LEEREN String und saehe damit aus wie
 * "unbeantwortet". Wer die Rueckfall-Ebene auf den Datensatz naiv baut,
 * sperrt genau die Mitarbeiter aus, die ordentlich geantwortet haben.
 *
 * Harness wie PortalNationalityRequiredTest.
 */
class PortalMainEmployerRequiredTest extends TestCase
{
    private const TEAM = 613;

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

        $own = dirname(__DIR__, 2);
        foreach ([
            'database/migrations/2026_05_20_000001_create_rec_employees_table.php',
            'database/migrations/2026_09_23_000001_add_nationality_to_rec_employees.php',
            'database/migrations/2026_09_23_000002_add_employer_fields_to_rec_employees.php',
        ] as $relative) {
            (require $own . '/' . $relative)->up();
        }
    }

    public static function tearDownAfterClass(): void
    {
        Facade::clearResolvedInstances();
    }

    protected function setUp(): void
    {
        Capsule::table('rec_employees')->delete();
    }

    public function test_save_blockt_ohne_angabe_und_fasst_nichts_an(): void
    {
        $employee = $this->makeEmployee(['city' => 'Koeln', 'is_main_employer' => null]);

        $portal = $this->portalFor($employee, ['is_main_employer' => '', 'city' => 'Duesseldorf']);
        $portal->saveAll();

        $this->assertNotNull($portal->editError);
        $this->assertStringContainsString('Hauptarbeitgeber', $portal->editError);
        $this->assertSame('Koeln', $employee->fresh()->city, 'Unbeteiligtes Feld darf nicht durchrutschen');
        $this->assertNull($employee->fresh()->is_main_employer);
    }

    public function test_ja_geht_durch(): void
    {
        $employee = $this->makeEmployee(['city' => 'Koeln']);

        $portal = $this->portalFor($employee, ['is_main_employer' => '1', 'city' => 'Duesseldorf']);
        $portal->saveAll();

        $this->assertNull($portal->editError);
        $this->assertTrue($employee->fresh()->is_main_employer);
        $this->assertSame('Duesseldorf', $employee->fresh()->city);
    }

    public function test_nein_ohne_namen_blockt(): void
    {
        $employee = $this->makeEmployee(['city' => 'Koeln', 'is_main_employer' => null]);

        $portal = $this->portalFor($employee, ['is_main_employer' => '0', 'other_employer' => '', 'city' => 'Duesseldorf']);
        $portal->saveAll();

        $this->assertNotNull($portal->editError);
        $this->assertSame('Koeln', $employee->fresh()->city);
        $this->assertNull($employee->fresh()->is_main_employer);
    }

    public function test_nein_mit_namen_geht_durch(): void
    {
        $employee = $this->makeEmployee();

        $portal = $this->portalFor($employee, [
            'is_main_employer' => '0',
            'other_employer'   => 'Musterkantine GmbH',
        ]);
        $portal->saveAll();

        $this->assertNull($portal->editError);
        $this->assertFalse($employee->fresh()->is_main_employer);
        $this->assertSame('Musterkantine GmbH', $employee->fresh()->other_employer);
    }

    public function test_ja_mit_nebenjob_geht_durch(): void
    {
        $employee = $this->makeEmployee();

        $portal = $this->portalFor($employee, [
            'is_main_employer' => '1',
            'other_employer'   => 'Musterkantine GmbH',
        ]);
        $portal->saveAll();

        $this->assertNull($portal->editError);
        $this->assertTrue($employee->fresh()->is_main_employer);
        $this->assertSame('Musterkantine GmbH', $employee->fresh()->other_employer);
    }

    public function test_vorhandenes_ja_am_datensatz_reicht_ohne_formularschluessel(): void
    {
        $employee = $this->makeEmployee(['city' => 'Koeln', 'is_main_employer' => true]);

        $portal = $this->portalFor($employee, ['city' => 'Bonn']);
        $portal->saveAll();

        $this->assertNull($portal->editError);
        $this->assertSame('Bonn', $employee->fresh()->city);
    }

    /**
     * Der Kern dieses Tests: "nein" am Datensatz ist eine gueltige Antwort.
     * Ein naiver (string)-Cast macht aus false einen leeren String, und der
     * Mitarbeiter koennte nie wieder etwas speichern.
     */
    public function test_vorhandenes_nein_am_datensatz_sperrt_nicht_aus(): void
    {
        $employee = $this->makeEmployee([
            'city'             => 'Koeln',
            'is_main_employer' => false,
            'other_employer'   => 'Musterkantine GmbH',
        ]);

        $portal = $this->portalFor($employee, ['city' => 'Bonn']);
        $portal->saveAll();

        $this->assertNull($portal->editError, 'Wer "nein" geantwortet hat, darf weiter speichern.');
        $this->assertSame('Bonn', $employee->fresh()->city);
        $this->assertFalse($employee->fresh()->is_main_employer);
    }

    private function makeEmployee(array $attributes = []): RecEmployee
    {
        return RecEmployee::create(array_merge([
            'team_id'          => self::TEAM,
            'first_name'       => 'Erika',
            'last_name'        => 'Muster',
            'portal_token'     => 'tok-emp-' . uniqid(),
            'is_active'        => true,
            // Pflichtfeld seit 23.09.2026 — der Staatsangehoerigkeits-Guard
            // laeuft vorher und wuerde sonst jeden Fall hier abfangen.
            'nationality'      => 'de',
            'is_main_employer' => true,
        ], $attributes));
    }

    private function portalFor(RecEmployee $employee, array $fieldValues): EmployeePortal
    {
        $portal = new EmployeePortal();
        $portal->state = 'verified';
        $portal->employeeId = $employee->id;
        $portal->fieldValues = $fieldValues;

        return $portal;
    }
}
