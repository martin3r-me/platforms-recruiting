<?php

namespace Platform\Recruiting\Tests\Integration;

use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Models\RecEmployee;
use Platform\Recruiting\Models\RecEmployeeHrData;
use Platform\Recruiting\Observers\RecEmployeeExportObserver;
use Platform\Recruiting\Services\Zas\ZasInboundDuplicateFinder;
use Platform\Recruiting\Services\Zas\ZasInboundEmployeeImporter;
use Platform\Recruiting\Services\Zas\ZasInboundRowMapper;
use Platform\Recruiting\Services\Zas\ZasLookupReverseResolver;

/**
 * Grundlohn + Zuschlag laut ZAS (Abgleich 19.10.2026).
 *
 * Die Werte gehoeren ZAS und werden bei JEDEM Treffer uebernommen — mit und
 * ohne Bewerbung, unabhaengig von isZasOwned und dem Overwrite-Schalter. Sie
 * wandern nie zurueck: kein Export-Marker, kein Echo.
 */
class ZasInboundLohnTest extends TestCase
{
    private const TEAM = 17;

    private static ConfigRepository $config;

    public static function setUpBeforeClass(): void
    {
        $container = Container::getInstance();
        self::$config = new ConfigRepository([
            'activity-log' => ['events' => []],
            'recruiting'   => ['zas' => ['inbound_team_id' => self::TEAM]],
        ]);
        $container->instance('config', self::$config);

        $dispatcher = new \Illuminate\Events\Dispatcher($container);
        $container->instance('events', $dispatcher);

        $container->instance('log', new class {
            public function __call(string $name, array $args): void
            {
            }
        });
        Facade::setFacadeApplication($container);
        Facade::clearResolvedInstance('log');
        if (!class_exists('Log', false)) {
            class_alias(\Illuminate\Support\Facades\Log::class, 'Log');
        }

        $capsule = new Capsule();
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $capsule->setEventDispatcher($dispatcher);
        $capsule->setAsGlobal();
        $capsule->bootEloquent();
        $container->instance('db', $capsule->getDatabaseManager());

        Model::clearBootedModels();
        Model::unguard();

        $schema = Capsule::schema();
        $schema->create('rec_employees', function ($t) {
            $t->increments('id');
            $t->string('uuid')->nullable();
            $t->string('portal_token')->nullable();
            $t->integer('team_id');
            $t->string('first_name')->nullable();
            $t->string('last_name')->nullable();
            $t->string('email')->nullable();
            $t->string('phone')->nullable();
            $t->string('iban')->nullable();
            $t->string('country_code')->nullable();
            $t->string('company')->nullable();
            $t->string('personnel_number')->nullable();
            $t->integer('rec_applicant_id')->nullable();
            $t->boolean('is_active')->default(true); $t->boolean('is_test')->default(false);
            $t->dateTime('zas_changed_at')->nullable();
            $t->dateTime('zas_initial_exported_at')->nullable();
            $t->integer('rec_zas_inbound_file_id')->nullable();
            $t->timestamps();
        });
        $schema->create('rec_employee_hr_data', function ($t) {
            $t->increments('id');
            $t->string('uuid')->nullable();
            $t->integer('rec_employee_id');
            $t->integer('team_id')->nullable();
            $t->string('export_status')->nullable();
            $t->date('status_ma_since')->nullable();
            $t->date('contract_signed_at')->nullable();
            $t->string('employment_classification')->nullable();
            $t->decimal('zas_grundlohn', 6, 2)->nullable();
            $t->decimal('zas_zuschlag', 6, 2)->nullable();
            $t->timestamps();
        });

        // Echter Observer: wuerde bei einem relevanten Feld den Marker setzen.
        RecEmployeeExportObserver::register();
    }

    public static function tearDownAfterClass(): void
    {
        Capsule::schema()->dropAllTables();
        Facade::clearResolvedInstances();
    }

    protected function setUp(): void
    {
        Capsule::table('rec_employee_hr_data')->delete();
        Capsule::table('rec_employees')->delete();
        self::$config->set('recruiting.zas.inbound_overwrite_zas_owned', true);
    }

    private function importer(): ZasInboundEmployeeImporter
    {
        return new ZasInboundEmployeeImporter(
            new ZasInboundRowMapper(new ZasLookupReverseResolver()),
            new ZasInboundDuplicateFinder()
        );
    }

    /** @param array<string,mixed> $overrides */
    private function makeEmployee(array $overrides = [], array $hr = []): RecEmployee
    {
        $employee = RecEmployee::create(array_merge([
            'team_id'          => self::TEAM,
            'first_name'       => 'Bestand',
            'last_name'        => 'Mitarbeiter',
            'personnel_number' => 'RG4711',
            'company'          => 'RG',
            'is_active'        => true,
        ], $overrides));
        RecEmployeeHrData::create(array_merge([
            'rec_employee_id' => $employee->id,
            'team_id'         => self::TEAM,
            'export_status'   => 'GO',
        ], $hr));
        Capsule::table('rec_employees')->where('id', $employee->id)->update(['zas_changed_at' => null]);

        return $employee->fresh();
    }

    private function row(array $overrides = []): array
    {
        return array_merge([
            'ZasPersonalNr' => 'RG4711',
            'Status'        => 'GO',
            'Grundlohn'     => '13,9',
            'Zuschlag'      => '1,1',
        ], $overrides);
    }

    /** @return array{0: ?string, 1: ?string} */
    private function lohn(int $employeeId): array
    {
        $hr = RecEmployeeHrData::where('rec_employee_id', $employeeId)->firstOrFail();

        return [$hr->zas_grundlohn, $hr->zas_zuschlag];
    }

    public function test_neuer_mitarbeiter_bekommt_beide_werte(): void
    {
        $report = $this->importer()->import([$this->row([
            'ZasPersonalNr' => 'MA9001',
            'Name'          => 'Neu',
            'Vorname'       => 'Mitarbeiter',
            'Grundlohn'     => '15,33',
            'Zuschlag'      => '0,67',
        ])], (object) ['id' => 99], false);

        $this->assertCount(1, $report['created']);
        $this->assertSame(['15.33', '0.67'], $this->lohn($report['created'][0]['employee_id']));
    }

    public function test_bestand_mit_bewerbung_bekommt_die_werte(): void
    {
        $employee = $this->makeEmployee(['rec_applicant_id' => 5]);

        $report = $this->importer()->import([$this->row()], (object) ['id' => 99], false);

        $this->assertSame(['13.90', '1.10'], $this->lohn($employee->id));
        $this->assertCount(1, $report['updated']);
    }

    public function test_bestand_ohne_bewerbung_bekommt_die_werte_auch_bei_abgeschaltetem_ueberschreiben(): void
    {
        self::$config->set('recruiting.zas.inbound_overwrite_zas_owned', false);
        $employee = $this->makeEmployee(['rec_zas_inbound_file_id' => 42]);

        $this->importer()->import([$this->row()], (object) ['id' => 99], false);

        $this->assertSame(['13.90', '1.10'], $this->lohn($employee->id));
    }

    public function test_zweite_lieferung_mit_anderen_werten_ueberschreibt(): void
    {
        $employee = $this->makeEmployee([], ['zas_grundlohn' => '12.82', 'zas_zuschlag' => '2.18']);

        $this->importer()->import([$this->row(['Grundlohn' => '13,9', 'Zuschlag' => '2,1'])], (object) ['id' => 99], false);

        $this->assertSame(['13.90', '2.10'], $this->lohn($employee->id));
    }

    public function test_gleiche_werte_zaehlen_als_unveraendert(): void
    {
        $this->makeEmployee([], ['zas_grundlohn' => '13.90', 'zas_zuschlag' => '1.10']);

        $report = $this->importer()->import([$this->row()], (object) ['id' => 99], false);

        $this->assertSame([], $report['updated']);
        $this->assertCount(1, $report['skipped']);
    }

    public function test_leere_zelle_laesst_den_stand_stehen(): void
    {
        $employee = $this->makeEmployee([], ['zas_grundlohn' => '13.90', 'zas_zuschlag' => '1.10']);

        $this->importer()->import([$this->row(['Grundlohn' => '', 'Zuschlag' => ''])], (object) ['id' => 99], false);

        $this->assertSame(['13.90', '1.10'], $this->lohn($employee->id));
    }

    public function test_lohn_aus_zas_setzt_keinen_export_marker(): void
    {
        $employee = $this->makeEmployee(['rec_applicant_id' => 5]);

        $this->importer()->import([$this->row()], (object) ['id' => 99], false);

        $this->assertNull(
            $employee->fresh()->zas_changed_at,
            'Der empfangene Lohn darf nicht als Update an ZAS zurueckgehen'
        );
    }
}
