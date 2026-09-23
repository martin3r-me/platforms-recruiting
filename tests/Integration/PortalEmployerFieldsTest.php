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
use Platform\Recruiting\Observers\RecEmployeeExportObserver;

/**
 * Haupt-/Nebenarbeitgeber im MA-Portal — Claras Liste (28.08.2026):
 * „Hauptarbeitgeber / Nebenarbeitgeber fehlt".
 *
 * ZWEI Felder, nicht eines: Rheingedeck kann Hauptarbeitgeber sein UND der
 * Mitarbeiter nebenher woanders arbeiten. Ein einzelnes Kennzeichen koennte
 * den Fall nicht abbilden.
 *
 * Der Nachweis ist nur dann Pflicht, wenn wir NICHT der Hauptarbeitgeber
 * sind — dann muessen wir wissen, wer es ist (Steuerklasse VI). Sagt jemand
 * "ja, ihr seid es", bleibt das zweite Feld freiwillig.
 *
 * NICHT im ZAS-Export: die Felder gehen erst raus, wenn der Kunde die Frage
 * beantwortet hat (Rueckfrage in der Mail). Bis dahin duerfen sie auch den
 * Update-Marker nicht setzen — sonst spuelte die erste Portal-Eingabe den
 * halben Bestand in die updates.csv (Vorfall 02.09.2026).
 */
class PortalEmployerFieldsTest extends TestCase
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

        $container->instance(\Illuminate\Contracts\Auth\Factory::class, new AuthGuardStub(self::TEAM));
        $container->instance('db', $capsule->getDatabaseManager());
        $container->instance('db.schema', $capsule->getConnection()->getSchemaBuilder());
        Facade::setFacadeApplication($container);

        $own = dirname(__DIR__, 2);
        foreach ([
            'database/migrations/2026_05_20_000001_create_rec_employees_table.php',
            'database/migrations/2026_09_23_000001_add_nationality_to_rec_employees.php',
            'database/migrations/2026_09_23_000002_add_employer_fields_to_rec_employees.php',
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
            'portal_token' => 'tok-employer-' . uniqid(),
            'is_active'    => true,
            'nationality'  => 'de',
        ], $attributes));
    }

    public function test_portal_bietet_beide_arbeitgeber_felder(): void
    {
        $groups = $this->makeEmployee()->editableFieldGroups();

        $this->assertArrayHasKey('Arbeitgeber', $groups);
        $this->assertSame(
            ['is_main_employer', 'other_employer'],
            array_keys($groups['Arbeitgeber']),
        );
        $this->assertSame('bool', $groups['Arbeitgeber']['is_main_employer']['type']);
        $this->assertSame('text', $groups['Arbeitgeber']['other_employer']['type']);
    }

    public function test_unbeantwortetes_kennzeichen_wird_als_fehlend_gemeldet(): void
    {
        $missing = $this->makeEmployee(['is_main_employer' => null])->missingFields();

        $this->assertArrayHasKey('is_main_employer', $missing);
        $this->assertArrayNotHasKey(
            'other_employer',
            $missing,
            'Solange die Frage offen ist, darf der andere Arbeitgeber nicht rot sein.',
        );
    }

    public function test_ja_verlangt_den_anderen_arbeitgeber_nicht(): void
    {
        $missing = $this->makeEmployee(['is_main_employer' => true])->missingFields();

        $this->assertArrayNotHasKey('is_main_employer', $missing);
        $this->assertArrayNotHasKey(
            'other_employer',
            $missing,
            'Wer nur bei uns arbeitet, soll kein Pflichtfeld vor sich haben.',
        );
    }

    public function test_nein_verlangt_den_hauptarbeitgeber(): void
    {
        $missing = $this->makeEmployee(['is_main_employer' => false])->missingFields();

        $this->assertArrayHasKey('other_employer', $missing);
    }

    public function test_nein_mit_angabe_verlangt_nichts_mehr(): void
    {
        $missing = $this->makeEmployee([
            'is_main_employer' => false,
            'other_employer'   => 'Musterkantine GmbH',
        ])->missingFields();

        $this->assertArrayNotHasKey('other_employer', $missing);
    }

    public function test_hr_akte_pflegt_dieselben_beiden_felder(): void
    {
        $show = new Show();
        $show->employeeId = 0;

        $fields = $show->fieldGroups()['Arbeitgeber'] ?? [];

        $this->assertArrayHasKey('is_main_employer', $fields);
        $this->assertArrayHasKey('other_employer', $fields);
    }

    /**
     * Beide Felder gehen (noch) NICHT nach ZAS. Stuenden sie in der
     * Beobachtungsliste, setzte die erste Portal-Eingabe den Update-Marker
     * und der Mitarbeiter landete in updates.csv — mit einer VOLLEN Zeile,
     * die in ZAS gepflegte Felder ueberschreibt.
     */
    public function test_kein_zas_export_marker(): void
    {
        $this->assertNotContains('is_main_employer', RecEmployeeExportObserver::RELEVANT_EMPLOYEE_FIELDS);
        $this->assertNotContains('other_employer', RecEmployeeExportObserver::RELEVANT_EMPLOYEE_FIELDS);
    }

    public function test_werte_werden_gespeichert_und_bool_gecastet(): void
    {
        $employee = $this->makeEmployee([
            'is_main_employer' => false,
            'other_employer'   => 'Musterkantine GmbH',
        ]);

        $fresh = $employee->fresh();
        $this->assertFalse($fresh->is_main_employer);
        $this->assertSame('Musterkantine GmbH', $fresh->other_employer);
    }
}
