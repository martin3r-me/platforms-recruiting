<?php

namespace Platform\Recruiting\Tests\Integration;

use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Facade;
use Platform\Recruiting\Observers\RecEmployeeExportObserver;

/**
 * Gemeinsame Welt fuer die Personen-Spiegel-Tests: Capsule + SQLite mit den
 * echten Migrationen (Muster EmployerFieldsExportMarkerTest).
 */
trait SpiegelHarness
{
    protected static function baueWelt(bool $mitObserver): void
    {
        if (!class_exists('Log', false)) {
            class_alias(\Illuminate\Support\Facades\Log::class, 'Log');
        }

        $container = Container::getInstance();
        $container->instance('config', new ConfigRepository([]));
        $container->instance('log', new class {
            public function __call($m, $a) {}
        });

        $dispatcher = new Dispatcher($container);
        $capsule = new Capsule();
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $capsule->setEventDispatcher($dispatcher);
        $capsule->setAsGlobal();
        $capsule->bootEloquent();

        $container->instance('events', $dispatcher);
        $container->instance('db', $capsule->getDatabaseManager());
        $container->instance('db.schema', $capsule->getConnection()->getSchemaBuilder());
        Facade::setFacadeApplication($container);
        Facade::clearResolvedInstances();

        Model::unguard();
        Model::clearBootedModels();

        $own = dirname(__DIR__, 2);
        foreach ([
            '2026_05_20_000001_create_rec_employees_table',
            '2026_05_21_000001_add_full_field_set_to_rec_employees',
            '2026_05_21_000002_create_rec_employee_hr_data_table',
            '2026_05_21_000003_add_full_hr_field_set_to_rec_employees_and_hr_data',
            '2026_05_21_000005_add_zas_export_markers_to_rec_employees',
            '2026_05_22_000001_add_personnel_number_to_rec_employees',
            '2026_07_07_000001_add_cost_center_to_rec_employees',
            '2026_06_05_000001_add_payroll_tracking_to_rec_employees',
            '2026_09_10_000001_add_person_key_to_rec_employees',
            '2026_09_23_000002_add_employer_fields_to_rec_employees',
            '2026_09_28_000001_create_rec_persons_table',
            '2026_09_28_000002_add_rec_person_id_to_rec_employees',
        ] as $name) {
            (require $own . '/database/migrations/' . $name . '.php')->up();
        }

        Capsule::schema()->create('rec_applicant_settings', function ($t) {
            $t->increments('id');
            $t->integer('team_id')->unique();
            $t->text('settings')->nullable();
            $t->timestamps();
        });

        if ($mitObserver) {
            RecEmployeeExportObserver::register();
        }
    }

    public static function tearDownAfterClass(): void
    {
        Capsule::schema()->dropAllTables();
        Container::getInstance()->forgetInstance('config');
        Container::getInstance()->forgetInstance('log');
        Facade::clearResolvedInstances();
    }

    protected function setUp(): void
    {
        Capsule::table('rec_employees')->delete();
        Capsule::table('rec_persons')->delete();
        Capsule::table('rec_applicant_settings')->delete();
    }

    protected function akte(array $attr = []): int
    {
        return (int) Capsule::table('rec_employees')->insertGetId(array_merge([
            'uuid' => uniqid('u', true), 'team_id' => 614, 'first_name' => 'Max', 'last_name' => 'Muster',
            'portal_token' => uniqid('t', true), 'is_active' => 1,
        ], $attr));
    }

    protected function person(): int
    {
        return (int) Capsule::table('rec_persons')->insertGetId(['uuid' => uniqid('p', true), 'team_id' => 614, 'created_at' => now(), 'updated_at' => now()]);
    }

    protected function zeile(int $id): object
    {
        return Capsule::table('rec_employees')->find($id);
    }

    protected function lohn(int $id): array
    {
        return json_decode((string) $this->zeile($id)->payroll_data_changed_fields, true) ?: [];
    }

    protected function setzeEinstellung(array $s): void
    {
        Capsule::table('rec_applicant_settings')->updateOrInsert(['team_id' => 614], ['settings' => json_encode($s)]);
    }
}
