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
 * ZAS-Status INAKTIV (Spec 2026-10-14, §2).
 *
 * Der Status gehoert ZAS: INAKTIV deaktiviert den Mitarbeiter bei uns. Zurueck
 * auf GO/MA reaktiviert aber NUR, wenn die Inaktivitaet von ZAS kam
 * (gespeicherter Status INAKTIV) — eine Deaktivierung von Hand durch HR darf
 * ZAS nicht ueberstimmen. Unbekannte Inaktive werden inaktiv angelegt, ohne
 * Dublettenpruefung (bei tausenden Altfaellen nur Laerm).
 *
 * Die Kontaktlisten-Synchronisation wird hier nicht geprueft: ohne CRM-Tabellen
 * scheitert sie und wird vom Importer abgefangen — genau das muss sie auch.
 */
class ZasInboundInaktivTest extends TestCase
{
    private const TEAM = 13;

    public static function setUpBeforeClass(): void
    {
        $container = Container::getInstance();
        $container->instance('config', new ConfigRepository([
            'activity-log' => ['events' => []],
            'recruiting'   => ['zas' => ['inbound_team_id' => self::TEAM]],
        ]));

        $dispatcher = new \Illuminate\Events\Dispatcher($container);
        $container->instance('events', $dispatcher);

        $container->instance('log', new class {
            public function __call(string $name, array $args): void
            {
            }
        });
        Facade::setFacadeApplication($container);
        Facade::clearResolvedInstance('log');

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
            $t->boolean('is_active')->default(true);
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
            $t->timestamps();
        });

        // Echter Observer: setzt bei export_status-Aenderung den Export-Marker,
        // den der Import danach wieder zuruecknehmen muss.
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
    }

    private function importer(): ZasInboundEmployeeImporter
    {
        return new ZasInboundEmployeeImporter(
            new ZasInboundRowMapper(new ZasLookupReverseResolver()),
            new ZasInboundDuplicateFinder()
        );
    }

    private function makeEmployee(string $status, bool $active, array $overrides = []): RecEmployee
    {
        $employee = RecEmployee::create(array_merge([
            'team_id'          => self::TEAM,
            'first_name'       => 'Bestand',
            'last_name'        => 'Mitarbeiter',
            'personnel_number' => '4711',
            'company'          => 'RG',
            'is_active'        => $active,
        ], $overrides));
        RecEmployeeHrData::create([
            'rec_employee_id' => $employee->id,
            'team_id'         => self::TEAM,
            'export_status'   => $status,
        ]);
        Capsule::table('rec_employees')->where('id', $employee->id)->update(['zas_changed_at' => null]);

        return $employee->fresh();
    }

    private function row(array $overrides = []): array
    {
        return array_merge([
            'ZasPersonalNr' => '4711',
            'Status'        => 'GO',
        ], $overrides);
    }

    private function hrStatus(int $employeeId): ?string
    {
        return Capsule::table('rec_employee_hr_data')->where('rec_employee_id', $employeeId)->value('export_status');
    }

    private function isActive(int $employeeId): bool
    {
        return (bool) Capsule::table('rec_employees')->where('id', $employeeId)->value('is_active');
    }

    public function test_inaktiv_deaktiviert_aktiven_bestand(): void
    {
        $employee = $this->makeEmployee('GO', true);

        $report = $this->importer()->import([$this->row(['Status' => 'inaktiv'])], (object) ['id' => 99], false);

        $this->assertFalse($this->isActive($employee->id));
        $this->assertSame('INAKTIV', $this->hrStatus($employee->id));
        $this->assertSame([['employee_id' => $employee->id, 'personnel_number' => '4711']], $report['deactivated']);
        $this->assertSame([], $report['reactivated']);
        $this->assertSame([], $report['skipped']);
        $this->assertContains('is_active', $report['updated'][0]['changed']);
        $this->assertNull(
            Capsule::table('rec_employees')->where('id', $employee->id)->value('zas_changed_at'),
            'Die Deaktivierung kommt von ZAS und darf nicht als Update zurueckgehen'
        );
        $this->assertSame('processed', $report['status']);
    }

    public function test_go_reaktiviert_von_zas_deaktivierten_bestand(): void
    {
        $employee = $this->makeEmployee('INAKTIV', false);

        $report = $this->importer()->import([$this->row(['Status' => 'GO'])], (object) ['id' => 99], false);

        $this->assertTrue($this->isActive($employee->id));
        $this->assertSame('GO', $this->hrStatus($employee->id));
        $this->assertSame([['employee_id' => $employee->id, 'personnel_number' => '4711']], $report['reactivated']);
        $this->assertSame([], $report['deactivated']);
        $this->assertNull(Capsule::table('rec_employees')->where('id', $employee->id)->value('zas_changed_at'));
    }

    public function test_go_reaktiviert_von_hr_deaktivierten_bestand_nicht(): void
    {
        $employee = $this->makeEmployee('GO', false);

        $report = $this->importer()->import([$this->row(['Status' => 'GO'])], (object) ['id' => 99], false);

        $this->assertFalse($this->isActive($employee->id), 'HR-Deaktivierung darf ZAS nicht ueberstimmen');
        $this->assertSame([], $report['reactivated']);
        $this->assertSame('exists', $report['skipped'][0]['reason']);
    }

    public function test_ma_reaktiviert_von_hr_deaktivierten_bestand_nicht_auch_bei_statuswechsel(): void
    {
        $employee = $this->makeEmployee('GO', false);

        $report = $this->importer()->import([$this->row(['Status' => 'MA', 'StatusMASeit' => '01.10.2026'])], (object) ['id' => 99], false);

        $this->assertFalse($this->isActive($employee->id));
        $this->assertSame('MA', $this->hrStatus($employee->id));
        $this->assertSame([], $report['reactivated']);
    }

    public function test_unbekannter_inaktiver_wird_inaktiv_ohne_dublettenpruefung_angelegt(): void
    {
        $this->makeEmployee('GO', true, ['personnel_number' => '1', 'phone' => '0176 1234567']);

        $report = $this->importer()->import([$this->row([
            'ZasPersonalNr' => '9001',
            'Name'          => 'Neu',
            'Vorname'       => 'Inaktiv',
            'Telefon'       => '+49 176 1234567',
            'Status'        => 'inaktiv',
        ])], (object) ['id' => 99], false);

        $this->assertCount(1, $report['created']);
        $this->assertTrue($report['created'][0]['inactive']);
        $this->assertSame(1, $report['created_inactive']);
        $this->assertSame([], $report['suspected'], 'Inaktive Altfaelle laufen ohne Dublettenpruefung');
        $id = $report['created'][0]['employee_id'];
        $this->assertFalse($this->isActive($id));
        $this->assertSame('INAKTIV', $this->hrStatus($id));
        $this->assertNull(Capsule::table('rec_employees')->where('id', $id)->value('zas_changed_at'));
        $this->assertSame('processed', $report['status']);
    }

    public function test_unbekannter_go_wird_wie_bisher_aktiv_mit_dublettenpruefung_angelegt(): void
    {
        $this->makeEmployee('GO', true, ['personnel_number' => '1', 'phone' => '0176 1234567']);

        $report = $this->importer()->import([$this->row([
            'ZasPersonalNr' => '9002',
            'Name'          => 'Neu',
            'Vorname'       => 'Aktiv',
            'Telefon'       => '+49 176 1234567',
            'Status'        => 'GO',
        ])], (object) ['id' => 99], false);

        $this->assertCount(1, $report['created']);
        $this->assertFalse($report['created'][0]['inactive']);
        $this->assertSame(0, $report['created_inactive']);
        $this->assertNotSame([], $report['suspected']);
        $this->assertTrue($this->isActive($report['created'][0]['employee_id']));
    }

    public function test_unveraenderter_aktiver_go_bleibt_skipped(): void
    {
        $employee = $this->makeEmployee('GO', true);

        $report = $this->importer()->import([$this->row(['Status' => 'GO'])], (object) ['id' => 99], false);

        $this->assertSame([], $report['updated']);
        $this->assertSame('exists', $report['skipped'][0]['reason']);
        $this->assertSame([], $report['deactivated']);
        $this->assertSame([], $report['reactivated']);
        $this->assertTrue($this->isActive($employee->id));
    }

    public function test_dry_run_meldet_wechsel_ohne_zu_schreiben(): void
    {
        $aktiv   = $this->makeEmployee('GO', true);
        $inaktiv = $this->makeEmployee('INAKTIV', false, ['personnel_number' => '4712']);

        $report = $this->importer()->import([
            $this->row(['Status' => 'inaktiv']),
            $this->row(['ZasPersonalNr' => '4712', 'Status' => 'MA', 'StatusMASeit' => '01.10.2026']),
            $this->row(['ZasPersonalNr' => '9003', 'Name' => 'Neu', 'Vorname' => 'X', 'Status' => 'Inaktive']),
        ], (object) ['id' => 99], true);

        $this->assertTrue($this->isActive($aktiv->id));
        $this->assertFalse($this->isActive($inaktiv->id));
        $this->assertSame(2, Capsule::table('rec_employees')->count());
        $this->assertTrue($report['deactivated'][0]['would_deactivate']);
        $this->assertSame($aktiv->id, $report['deactivated'][0]['employee_id']);
        $this->assertTrue($report['reactivated'][0]['would_reactivate']);
        $this->assertSame($inaktiv->id, $report['reactivated'][0]['employee_id']);
        $this->assertTrue($report['created'][0]['inactive']);
        $this->assertSame(1, $report['created_inactive']);
    }

    public function test_unbekannter_status_wird_gemeldet_und_aendert_nichts_am_aktiv_stand(): void
    {
        $employee = $this->makeEmployee('GO', true);

        $report = $this->importer()->import([$this->row(['Status' => 'xy'])], (object) ['id' => 99], false);

        $this->assertStringContainsString("Status 'xy' unbekannt", implode(' | ', $report['warnings']));
        $this->assertSame('XY', $this->hrStatus($employee->id));
        $this->assertTrue($this->isActive($employee->id));
        $this->assertSame([], $report['deactivated']);
    }
}
