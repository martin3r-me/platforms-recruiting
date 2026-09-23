<?php

namespace Platform\Recruiting\Tests\Integration;

use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Livewire\Employees\Show;
use Platform\Recruiting\Models\RecEmployee;

/**
 * IfSG-Belehrung (am / gueltig bis) ist ein HR-Feld: sichtbar und pflegbar in
 * der MA-Akte, NICHT im Mitarbeiter-Portal. ZAS liefert beide Werte fuer den
 * Bestand; fuer Funnel-Mitarbeiter rechnet der Export weiter aus dem Vertrag.
 */
class HrIfsgBelehrungFieldsTest extends TestCase
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

        $container->instance(\Illuminate\Contracts\Auth\Factory::class, new AuthGuardStub(self::TEAM));
        $container->instance('db', $capsule->getDatabaseManager());
        $container->instance('db.schema', $capsule->getConnection()->getSchemaBuilder());
        Facade::setFacadeApplication($container);

        $own = dirname(__DIR__, 2);
        foreach ([
            'database/migrations/2026_05_20_000001_create_rec_employees_table.php',
            'database/migrations/2026_09_23_000001_add_nationality_to_rec_employees.php',
            'database/migrations/2026_09_23_000002_add_employer_fields_to_rec_employees.php',
            'database/migrations/2026_09_23_000003_add_ifsg_instruction_to_rec_employees.php',
        ] as $relative) {
            $path = $own . '/' . $relative;
            if (!file_exists($path)) {
                throw new \RuntimeException("Migration fehlt: {$path}");
            }
            (require $path)->up();
        }
    }

    public static function tearDownAfterClass(): void
    {
        Facade::clearResolvedInstances();
    }

    public function test_hr_akte_zeigt_beide_felder_im_bereich_gesundheit(): void
    {
        $show = new Show();
        $show->employeeId = 0;

        $fields = $show->fieldGroups()['Gesundheit'] ?? [];

        $this->assertSame('date', $fields['infection_protection_instructed_at']['type'] ?? null);
        $this->assertSame('date', $fields['infection_protection_valid_until']['type'] ?? null);
    }

    public function test_portal_kennt_die_felder_nicht(): void
    {
        $employee = RecEmployee::create([
            'team_id'      => self::TEAM,
            'first_name'   => 'Erika',
            'last_name'    => 'Muster',
            'portal_token' => 'tok-ifsg-' . uniqid(),
            'is_active'    => true,
            'nationality'  => 'de',
        ]);

        $portal = array_merge(...array_values($employee->editableFieldGroups()));

        $this->assertArrayNotHasKey('infection_protection_instructed_at', $portal);
        $this->assertArrayNotHasKey('infection_protection_valid_until', $portal);
        $this->assertArrayNotHasKey('infection_protection_instructed_at', $employee->missingFields());
    }

    public function test_werte_werden_gespeichert(): void
    {
        $employee = RecEmployee::create([
            'team_id'                            => self::TEAM,
            'first_name'                         => 'Max',
            'last_name'                          => 'Muster',
            'is_active'                          => true,
            'infection_protection_instructed_at' => '2025-03-14',
            'infection_protection_valid_until'   => '2027-03-14',
        ]);

        $fresh = RecEmployee::find($employee->id);

        $this->assertSame('2025-03-14', $fresh->infection_protection_instructed_at?->format('Y-m-d'));
        $this->assertSame('2027-03-14', $fresh->infection_protection_valid_until?->format('Y-m-d'));
    }
}
