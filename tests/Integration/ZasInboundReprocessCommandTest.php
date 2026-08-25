<?php

namespace Platform\Recruiting\Tests\Integration;

use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Console\Commands\ZasInboundReprocess;
use Platform\Recruiting\Models\RecEmployee;
use Platform\Recruiting\Models\RecEmployeeHrData;
use Platform\Recruiting\Models\RecZasInboundFile;
use Platform\Recruiting\Services\Zas\ZasInboundCsvParser;
use Platform\Recruiting\Services\Zas\ZasInboundDuplicateFinder;
use Platform\Recruiting\Services\Zas\ZasInboundEmployeeImporter;
use Platform\Recruiting\Services\Zas\ZasInboundRowMapper;
use Platform\Recruiting\Services\Zas\ZasLookupReverseResolver;

/**
 * Reprocess einer gespeicherten Lieferung.
 *
 * Zweck (Befund Massenimport 2026-08-25): die Rohdateien liegen bei uns, aber es
 * gab keinen Weg, sie nochmal durch den Import zu schicken — fuer eine Probe
 * vorher, fuer einen Nachweis hinterher, oder um nach einer Code-Aenderung die
 * fehlenden Personalnummern aus den SCHON gelieferten Zeilen nachzutragen, ohne
 * ZAS um eine neue Lieferung zu bitten.
 *
 * Probe-Muster wie DispoEscalateCommandTest: der Kern ist aus handle()
 * herausgehoben und bekommt den Dateiinhalt als Parameter — der Storage-Zugriff
 * bleibt in handle() und damit aus dem Test heraus.
 *
 * Wichtige Regel, die hier festgenagelt wird: der Bericht einer BEREITS
 * verarbeiteten Lieferung wird nicht ueberschrieben. Er ist der Beleg dafuer,
 * was damals passiert ist.
 */
class ZasInboundReprocessCommandTest extends TestCase
{
    private const TEAM = 13;
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

        Capsule::schema()->create('rec_employees', function ($t) {
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
            $t->string('personnel_number')->nullable();
            $t->integer('rec_applicant_id')->nullable();
            $t->boolean('is_active')->default(true);
            $t->dateTime('zas_changed_at')->nullable();
            $t->dateTime('zas_initial_exported_at')->nullable();
            $t->integer('rec_zas_inbound_file_id')->nullable();
            $t->timestamps();
        });
        Capsule::schema()->create('rec_employee_hr_data', function ($t) {
            $t->increments('id');
            $t->string('uuid')->nullable();
            $t->integer('rec_employee_id');
            $t->integer('team_id')->nullable();
            $t->string('export_status')->nullable();
            $t->date('status_ma_since')->nullable();
            $t->string('employment_classification')->nullable();
            $t->timestamps();
        });
        Capsule::schema()->create('rec_zas_inbound_files', function ($t) {
            $t->increments('id');
            $t->string('uuid')->nullable();
            $t->string('original_filename')->nullable();
            $t->string('disk')->nullable();
            $t->string('stored_path')->nullable();
            $t->integer('row_count')->nullable();
            $t->boolean('is_test')->default(false);
            $t->string('status')->default('received');
            $t->dateTime('processed_at')->nullable();
            $t->text('notes')->nullable();
            $t->timestamps();
        });
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
        Capsule::table('rec_zas_inbound_files')->delete();
    }

    private function command(): ZasInboundReprocess
    {
        return new ZasInboundReprocess(
            new ZasInboundEmployeeImporter(
                new ZasInboundRowMapper(new ZasLookupReverseResolver()),
                new ZasInboundDuplicateFinder()
            ),
            new ZasInboundCsvParser()
        );
    }

    private function file(array $overrides = []): RecZasInboundFile
    {
        return RecZasInboundFile::create(array_merge([
            'uuid'        => 'datei-uuid',
            'disk'        => 'local',
            'stored_path' => 'zas-inbound/2026/08/25/x.csv',
            'row_count'   => 1,
            'is_test'     => false,
            'status'      => 'processed',
            'processed_at' => '2026-08-25 13:56:19',
            'notes'       => '{"created":[],"skipped":[{"reason":"exists"}]}',
        ], $overrides));
    }

    private function employee(array $overrides = []): RecEmployee
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

        return $employee->fresh();
    }

    private function csv(int $rows = 1): string
    {
        $out = "UUID;ZasPersonalNr;Status\n";
        for ($i = 0; $i < $rows; $i++) {
            $uuid = $i === 0 ? self::UUID : 'fremde-uuid-' . $i;
            $out .= $uuid . ';' . (17944 + $i) . ";GO\n";
        }

        return $out;
    }

    public function test_personalnummer_wird_aus_der_gespeicherten_datei_nachgetragen(): void
    {
        $employee = $this->employee();
        $file     = $this->file();

        $summary = $this->command()->reprocess($file, $this->csv(), false, 100);

        $this->assertSame('17944', $employee->fresh()->personnel_number);
        $this->assertSame(1, $summary['rows']);
        $this->assertSame(1, $summary['updated']);
    }

    public function test_dry_run_schreibt_nichts_meldet_es_aber(): void
    {
        $employee = $this->employee();
        $file     = $this->file();

        $summary = $this->command()->reprocess($file, $this->csv(), true, 100);

        $this->assertNull($employee->fresh()->personnel_number, 'Trockenlauf darf nichts schreiben');
        $this->assertSame(1, $summary['updated'], 'die Zahl muss der Trockenlauf trotzdem zeigen');
    }

    public function test_chunking_verarbeitet_alle_zeilen(): void
    {
        $this->employee();
        $file = $this->file(['row_count' => 5]);

        $summary = $this->command()->reprocess($file, $this->csv(5), true, 2);

        $this->assertSame(5, $summary['rows']);
        $this->assertSame(1, $summary['updated']);
        $this->assertSame(4, $summary['created'], 'die vier fremden UUIDs sind Neuanlagen');
    }

    public function test_bericht_einer_bereits_verarbeiteten_lieferung_bleibt_unberuehrt(): void
    {
        $this->employee();
        $file     = $this->file();
        $original = $file->notes;

        $this->command()->reprocess($file, $this->csv(), false, 100);

        $this->assertSame($original, $file->fresh()->notes, 'der Beleg von damals darf nicht verloren gehen');
        $this->assertSame('2026-08-25 13:56:19', $file->fresh()->processed_at->format('Y-m-d H:i:s'));
    }

    public function test_nie_verarbeitete_lieferung_wird_als_verarbeitet_markiert(): void
    {
        // Fall: Lieferung war zu gross und wurde abgewiesen (status rejected,
        // processed_at null). Hier gibt es keinen Bericht zu schuetzen.
        $this->employee();
        $file = $this->file(['status' => 'rejected', 'processed_at' => null, 'notes' => null]);

        $this->command()->reprocess($file, $this->csv(), false, 100);

        $fresh = $file->fresh();
        $this->assertNotNull($fresh->processed_at);
        $this->assertSame('processed', $fresh->status);
        $this->assertStringContainsString('updated', (string) $fresh->notes);
    }
}
