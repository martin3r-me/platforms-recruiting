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
use Platform\Recruiting\Services\Zas\ZasInboundEmployeeImporter;
use Platform\Recruiting\Services\Zas\ZasInboundRowMapper;
use Platform\Recruiting\Services\Zas\ZasLookupReverseResolver;

/**
 * Inbound-Status-Sync fuer BESTEHENDE Mitarbeiter (Kundenwunsch 2026-08-18).
 *
 * Vorher hat der Importer jede Zeile mit UUID-/PersNr-Treffer komplett
 * verworfen ("Neuanlage-only") — ein neues ZAS-Feld haette damit genau die
 * Bestands-MA nie erreicht. Der Sync schreibt deshalb gezielt NUR die beiden
 * Statusfelder und laesst alles andere unangetastet, damit ZAS keine
 * HR-gepflegten Felder ueberschreibt.
 *
 * Der Export-Marker (zas_changed_at) wird bewusst auf seinem VORHERIGEN Wert
 * wiederhergestellt: ZAS den gerade empfangenen Wert zurueckzuschicken waere
 * ein Echo, und bei einer Bestandslieferung mit hunderten Zeilen wuerde es den
 * Update-Export fluten.
 */
class ZasInboundStatusSyncTest extends TestCase
{
    private const TEAM = 7;

    public static function setUpBeforeClass(): void
    {
        $container = Container::getInstance();
        $container->instance('config', new ConfigRepository([
            'activity-log' => ['events' => []],
            'recruiting'   => ['zas' => ['inbound_team_id' => self::TEAM]],
        ]));

        $dispatcher = new \Illuminate\Events\Dispatcher($container);
        $container->instance('events', $dispatcher);

        // Log-Attrappe VOR dem ersten Facade-Zugriff binden, sonst cacht die
        // Facade die Aufloesung und der Observer-Fehlerpfad knallt.
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
        Model::unguard();

        $schema = Capsule::schema();
        $schema->create('rec_employees', function ($t) {
            $t->increments('id');
            $t->string('uuid')->nullable();
            $t->string('portal_token')->nullable(); // wird vom Model-creating-Hook gesetzt
            $t->integer('team_id');
            $t->string('first_name')->nullable();
            $t->string('last_name')->nullable();
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

        // Echter Observer: setzt bei export_status-Aenderung den Export-Marker.
        // Genau den muss der Sync danach wieder zuruecknehmen.
        RecEmployeeExportObserver::register();
    }

    public static function tearDownAfterClass(): void
    {
        Capsule::schema()->dropAllTables();
    }

    protected function setUp(): void
    {
        Capsule::table('rec_employee_hr_data')->delete();
        Capsule::table('rec_employees')->delete();
    }

    private function importer(): ZasInboundEmployeeImporter
    {
        return new ZasInboundEmployeeImporter(new ZasInboundRowMapper(new ZasLookupReverseResolver()));
    }

    /** @param array<string,mixed> $hr */
    private function makeEmployee(array $hr = [], ?string $marker = null): RecEmployee
    {
        $employee = RecEmployee::create([
            'team_id'          => self::TEAM,
            'first_name'       => 'Bestand',
            'last_name'        => 'Mitarbeiter',
            'personnel_number' => '4711',
            'is_active'        => true,
        ]);
        RecEmployeeHrData::create(array_merge([
            'rec_employee_id' => $employee->id,
            'team_id'         => self::TEAM,
            'export_status'   => 'GO',
        ], $hr));

        // Marker unabhaengig vom Observer setzen (direkt, wie in Produktion).
        Capsule::table('rec_employees')->where('id', $employee->id)->update(['zas_changed_at' => $marker]);

        return $employee->fresh();
    }

    private function row(array $overrides = []): array
    {
        return array_merge([
            'ZasPersonalNr' => '4711',
            'Status'        => 'MA',
            'StatusMASeit'  => '19.08.2026',
        ], $overrides);
    }

    private function hr(RecEmployee $employee): RecEmployeeHrData
    {
        return RecEmployeeHrData::where('rec_employee_id', $employee->id)->firstOrFail();
    }

    public function test_bestands_ma_bekommt_status_und_datum(): void
    {
        $employee = $this->makeEmployee();

        $report = $this->importer()->import([$this->row()], (object) ['id' => 99], false);

        $hr = $this->hr($employee);
        $this->assertSame('MA', $hr->export_status);
        $this->assertSame('2026-08-19', $hr->status_ma_since->format('Y-m-d'));
        $this->assertSame([], $report['created'], 'Bestands-MA darf nicht neu angelegt werden');
        $this->assertCount(1, $report['updated']);
        $this->assertSame($employee->id, $report['updated'][0]['employee_id']);
    }

    public function test_rueckstellung_auf_go_leert_das_datum(): void
    {
        $employee = $this->makeEmployee(['export_status' => 'MA', 'status_ma_since' => '2026-07-01']);

        $this->importer()->import([$this->row(['Status' => 'GO', 'StatusMASeit' => ''])], (object) ['id' => 99], false);

        $hr = $this->hr($employee);
        $this->assertSame('GO', $hr->export_status);
        $this->assertNull($hr->status_ma_since, 'Datum muss bei Rueckstellung aktiv geleert werden');
    }

    public function test_lieferfehler_leeres_datum_bei_status_ma_laesst_wert_stehen(): void
    {
        $employee = $this->makeEmployee(['export_status' => 'MA', 'status_ma_since' => '2026-07-01']);

        $report = $this->importer()->import([$this->row(['StatusMASeit' => ''])], (object) ['id' => 99], false);

        $this->assertSame('2026-07-01', $this->hr($employee)->status_ma_since->format('Y-m-d'));
        $this->assertStringContainsString('Status=MA', implode(' | ', $report['warnings']));
    }

    public function test_andere_felder_aus_der_lieferung_werden_nicht_uebernommen(): void
    {
        $employee = $this->makeEmployee(['contract_signed_at' => '2026-01-15']);

        $this->importer()->import([$this->row([
            'Vorname'          => 'Geaendert',
            'VertragZurueckAm' => '01.02.2026',
        ])], (object) ['id' => 99], false);

        $this->assertSame('Bestand', $employee->fresh()->first_name);
        $this->assertSame('2026-01-15', $this->hr($employee)->contract_signed_at->format('Y-m-d'));
    }

    public function test_unveraenderter_status_zaehlt_als_skipped(): void
    {
        $employee = $this->makeEmployee(['export_status' => 'MA', 'status_ma_since' => '2026-08-19']);

        $report = $this->importer()->import([$this->row()], (object) ['id' => 99], false);

        $this->assertSame([], $report['updated']);
        $this->assertCount(1, $report['skipped']);
    }

    public function test_sync_setzt_keinen_export_marker(): void
    {
        $employee = $this->makeEmployee();

        $this->importer()->import([$this->row()], (object) ['id' => 99], false);

        $this->assertNull(
            $employee->fresh()->zas_changed_at,
            'Der empfangene Wert darf nicht als Update an ZAS zurueckgespielt werden'
        );
    }

    public function test_sync_verschluckt_keinen_bestehenden_export_marker(): void
    {
        $employee = $this->makeEmployee(marker: '2026-08-18 10:00:00');

        $this->importer()->import([$this->row()], (object) ['id' => 99], false);

        $this->assertSame(
            '2026-08-18 10:00:00',
            $employee->fresh()->zas_changed_at?->format('Y-m-d H:i:s'),
            'Ein vorher gesetzter Marker stammt aus einer echten Aenderung und muss erhalten bleiben'
        );
    }

    public function test_dry_run_schreibt_nicht(): void
    {
        $employee = $this->makeEmployee();

        $report = $this->importer()->import([$this->row()], (object) ['id' => 99], true);

        $this->assertSame('GO', $this->hr($employee)->export_status);
        $this->assertNull($this->hr($employee)->status_ma_since);
        $this->assertTrue($report['updated'][0]['would_update']);
    }
}
