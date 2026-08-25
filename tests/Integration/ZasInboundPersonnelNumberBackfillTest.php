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
 * Personalnummer-Nachtrag bei einem Treffer (Befund Massenimport 2026-08-25).
 *
 * Ausgangslage: von 112 eigenen MA hatten 108 KEINE ZAS-Personalnummer im
 * Feld — der Wert sollte laut Ursprungsdesign von HR aus ZAS abgetippt werden,
 * was faktisch 4 Mal passiert ist. Folgen: (1) ohne Nummer kein Dubletten-
 * Schluessel, (2) DispoEmployeeDirectory nimmt nur MA MIT Nummer in die
 * Matching-Map — die halbe Stammbelegschaft war fuer die Dispo unsichtbar.
 *
 * ZAS liefert die Nummer in JEDER Zeile mit. Bisher hat der Importer sie bei
 * einem Treffer verworfen, weil dort nur die beiden Statusfelder gesynct
 * wurden. Jetzt wird sie nachgetragen — aber ausschliesslich in ein LEERES
 * Feld, damit ein von HR gepflegter Wert nie ueberschrieben wird.
 *
 * Kein Rueck-Export: personnel_number steht bewusst nicht in
 * RecEmployeeExportObserver::RELEVANT_EMPLOYEE_FIELDS, das Schreiben darf also
 * keinen zas_changed_at-Marker setzen.
 */
class ZasInboundPersonnelNumberBackfillTest extends TestCase
{
    private const TEAM = 9;
    private const UUID = '019e688e-d878-7834-bcc6-c1c9606328b9';

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

        // Sonst haengt der uuid-creating-Hook am Dispatcher einer frueheren
        // Testklasse und faellt im Gesamtlauf still aus.
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
            $t->string('employment_classification')->nullable();
            $t->timestamps();
        });

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

    /** Bestands-MA wie aus unserem Onboarding: UUID ja, Personalnummer nein. */
    private function makeEmployee(array $overrides = []): RecEmployee
    {
        $employee = RecEmployee::create(array_merge([
            'team_id'          => self::TEAM,
            'uuid'             => self::UUID,
            'first_name'       => 'Marie',
            'last_name'        => 'Schlaffke',
            'personnel_number' => null,
            'is_active'        => true,
        ], $overrides));

        RecEmployeeHrData::create([
            'rec_employee_id' => $employee->id,
            'team_id'         => self::TEAM,
            'export_status'   => 'GO',
        ]);

        Capsule::table('rec_employees')->where('id', $employee->id)->update(['zas_changed_at' => null]);

        return $employee->fresh();
    }

    /** Zeile wie von ZAS geliefert: unsere UUID plus deren Personalnummer. */
    private function row(array $overrides = []): array
    {
        return array_merge([
            'UUID'          => self::UUID,
            'ZasPersonalNr' => '17944',
            'Status'        => 'GO',
        ], $overrides);
    }

    public function test_leere_personalnummer_wird_bei_uuid_treffer_nachgetragen(): void
    {
        $employee = $this->makeEmployee();

        $report = $this->importer()->import([$this->row()], (object) ['id' => 42], false);

        $this->assertSame('17944', $employee->fresh()->personnel_number);
        $this->assertSame([], $report['created'], 'Bestands-MA darf nicht neu angelegt werden');
    }

    public function test_vorhandene_personalnummer_wird_nicht_ueberschrieben(): void
    {
        $employee = $this->makeEmployee(['personnel_number' => '4711']);

        $this->importer()->import([$this->row(['ZasPersonalNr' => '9999'])], (object) ['id' => 42], false);

        $this->assertSame('4711', $employee->fresh()->personnel_number, 'HR-Wert ist unantastbar');
    }

    public function test_leerstring_gilt_als_leeres_feld(): void
    {
        $employee = $this->makeEmployee(['personnel_number' => '']);

        $this->importer()->import([$this->row()], (object) ['id' => 42], false);

        $this->assertSame('17944', $employee->fresh()->personnel_number);
    }

    public function test_nachtrag_loest_keinen_rueck_export_aus(): void
    {
        $employee = $this->makeEmployee();

        $this->importer()->import([$this->row()], (object) ['id' => 42], false);

        $this->assertNull(
            Capsule::table('rec_employees')->where('id', $employee->id)->value('zas_changed_at'),
            'personnel_number ist nicht export-relevant — es darf kein Marker gesetzt werden'
        );
    }

    public function test_nachtrag_wird_im_bericht_gemeldet(): void
    {
        $this->makeEmployee();

        $report = $this->importer()->import([$this->row()], (object) ['id' => 42], false);

        $this->assertCount(1, $report['updated']);
        $this->assertContains('personnel_number', $report['updated'][0]['changed']);
    }

    public function test_dry_run_traegt_nichts_ein_meldet_es_aber(): void
    {
        // Der Fahrplan haengt daran: der Trockenlauf muss die Zahl der
        // Nachtraege ZEIGEN, bevor irgendetwas geschrieben wird.
        $employee = $this->makeEmployee();

        $report = $this->importer()->import([$this->row()], (object) ['id' => 42], true);

        $this->assertNull($employee->fresh()->personnel_number, 'Dry-Run darf nichts schreiben');
        $this->assertCount(1, $report['updated']);
        $this->assertContains('personnel_number', $report['updated'][0]['changed']);
    }

    public function test_treffer_ueber_personalnummer_bleibt_unveraendert(): void
    {
        // Kein UUID-Treffer, sondern PersNr-Treffer: hier ist das Feld per
        // Definition schon gefuellt, es darf sich nichts aendern.
        $employee = $this->makeEmployee(['uuid' => 'andere-uuid', 'personnel_number' => '17944']);

        $report = $this->importer()->import([$this->row(['UUID' => ''])], (object) ['id' => 42], false);

        $this->assertSame('17944', $employee->fresh()->personnel_number);
        $this->assertSame([], $report['created']);
    }
}
