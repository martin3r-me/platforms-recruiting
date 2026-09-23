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
 * Staatsangehoerigkeit ist im MA-Portal Pflicht (Kundenentscheidung
 * 23.09.2026): saveAll() blockt ohne Wert — auch Saves, die nur andere
 * Felder aendern (Endzustands-Pruefung wie beim Ersthelfer). Ein Wert am
 * Datensatz reicht, wenn das Formular den Schluessel nicht mitschickt.
 *
 * Harness wie PortalFirstAiderFieldsTest: echte Komponente, echte
 * Migrationen, SQLite im Speicher.
 */
class PortalNationalityRequiredTest extends TestCase
{
    private const TEAM = 612;

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
            'database/migrations/2026_07_17_000001_add_arbeitsschutz_fields_to_rec_employees.php',
            'database/migrations/2026_09_01_000001_add_first_aider_certificate_file_id_to_rec_employees.php',
            'database/migrations/2026_09_23_000001_add_nationality_to_rec_employees.php',
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

    public function test_save_is_blocked_without_nationality_and_touches_nothing(): void
    {
        $employee = $this->makeEmployee(['city' => 'Koeln', 'nationality' => null]);

        $portal = $this->portalFor($employee, ['nationality' => '', 'city' => 'Duesseldorf']);
        $portal->saveAll();

        $this->assertNotNull($portal->editError);
        $this->assertStringContainsString('Staatsangehoerigkeit', $portal->editError);
        $this->assertSame('Koeln', $employee->fresh()->city, 'Unbeteiligtes Feld darf nicht durchrutschen');
        $this->assertNull($employee->fresh()->nationality);
    }

    public function test_save_passes_once_nationality_is_chosen(): void
    {
        $employee = $this->makeEmployee(['city' => 'Koeln', 'nationality' => null]);

        $portal = $this->portalFor($employee, ['nationality' => 'bd', 'city' => 'Duesseldorf']);
        $portal->saveAll();

        $this->assertNull($portal->editError);
        $this->assertSame('bd', $employee->fresh()->nationality);
        $this->assertSame('Duesseldorf', $employee->fresh()->city);
    }

    public function test_existing_value_on_the_record_satisfies_the_guard(): void
    {
        // Formular ohne den Schluessel (z.B. aeltere Maske) — der Datensatz hat den Wert.
        $employee = $this->makeEmployee(['city' => 'Koeln', 'nationality' => 'de']);

        $portal = $this->portalFor($employee, ['city' => 'Bonn']);
        $portal->saveAll();

        $this->assertNull($portal->editError);
        $this->assertSame('Bonn', $employee->fresh()->city);
        $this->assertSame('de', $employee->fresh()->nationality);
    }

    private function makeEmployee(array $attributes = []): RecEmployee
    {
        return RecEmployee::create(array_merge([
            'team_id'      => self::TEAM,
            'first_name'   => 'Erika',
            'last_name'    => 'Muster',
            'phone'        => '+49 151 00000003',
            'portal_token' => 'tok-nat-' . uniqid(),
            'is_active'    => true,
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
