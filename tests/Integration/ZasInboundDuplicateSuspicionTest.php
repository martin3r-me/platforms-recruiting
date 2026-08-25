<?php

namespace Platform\Recruiting\Tests\Integration;

use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Models\RecEmployee;
use Platform\Recruiting\Services\Zas\ZasInboundDuplicateFinder;
use Platform\Recruiting\Services\Zas\ZasInboundEmployeeImporter;
use Platform\Recruiting\Services\Zas\ZasInboundRowMapper;
use Platform\Recruiting\Services\Zas\ZasLookupReverseResolver;

/**
 * Dublettenverdacht bei Neuanlage (Befund Massenimport 2026-08-25).
 *
 * Vier eigene MA wurden doppelt angelegt, weil weder UUID noch Personalnummer
 * greifbar waren — der Importer schloss aus dem fehlenden Schluessel auf eine
 * neue Person. Gefunden wurden sie hinterher ueber E-Mail und Telefon.
 *
 * Der Verdacht wird GEMELDET, nicht automatisch zusammengefuehrt: in derselben
 * Lieferung lagen drei Paare mit gemeinsamer IBAN (sehr wahrscheinlich
 * Familie), und eine falsche Verschmelzung zweier Personenakten ist schlimmer
 * als eine Dublette. Die Zeile laeuft also normal durch, aber der Bericht sagt
 * es.
 */
class ZasInboundDuplicateSuspicionTest extends TestCase
{
    private const TEAM = 11;

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

    /** Bestands-MA aus unserem Onboarding: keine Personalnummer, andere UUID. */
    private function existing(array $overrides = []): RecEmployee
    {
        return RecEmployee::create(array_merge([
            'team_id'          => self::TEAM,
            'uuid'             => 'unsere-eigene-uuid',
            'first_name'       => 'Marie',
            'last_name'        => 'Schlaffke',
            'email'            => 'Schlaffkemarie@gmail.com',
            'phone'            => '0176 1234567',
            'personnel_number' => null,
            'is_active'        => true,
        ], $overrides));
    }

    /** ZAS-Zeile OHNE unsere UUID — genau der Fall, der die Dubletten machte. */
    private function row(array $overrides = []): array
    {
        return array_merge([
            'UUID'          => '',
            'ZasPersonalNr' => '17944',
            'Name'          => 'Schlaffke',
            'Vorname'       => 'Marie',
            'Status'        => 'GO',
        ], $overrides);
    }

    public function test_gleiche_email_wird_als_verdacht_gemeldet(): void
    {
        $bestand = $this->existing();

        $report = $this->importer()->import(
            [$this->row(['Email' => 'schlaffkemarie@gmail.com'])],
            (object) ['id' => 7],
            false
        );

        $this->assertCount(1, $report['suspected']);
        $this->assertSame('17944', $report['suspected'][0]['personnel_number']);
        $this->assertSame('email', $report['suspected'][0]['matches'][0]['field']);
        $this->assertSame($bestand->id, $report['suspected'][0]['matches'][0]['employee_id']);
    }

    public function test_verdacht_verhindert_die_neuanlage_nicht(): void
    {
        $this->existing();

        $report = $this->importer()->import(
            [$this->row(['Email' => 'schlaffkemarie@gmail.com'])],
            (object) ['id' => 7],
            false
        );

        $this->assertCount(1, $report['created'], 'Zeile muss trotz Verdacht angelegt werden');
        $this->assertSame(2, Capsule::table('rec_employees')->count());
    }

    public function test_telefon_in_anderer_schreibweise_wird_erkannt(): void
    {
        $bestand = $this->existing(['email' => null]);

        $report = $this->importer()->import(
            [$this->row(['Telefon' => '+49 176 1234567'])],
            (object) ['id' => 7],
            false
        );

        $this->assertCount(1, $report['suspected']);
        $this->assertSame('phone', $report['suspected'][0]['matches'][0]['field']);
        $this->assertSame($bestand->id, $report['suspected'][0]['matches'][0]['employee_id']);
    }

    public function test_gleiche_iban_ist_ein_schwaches_signal(): void
    {
        $this->existing(['email' => null, 'phone' => null, 'iban' => 'DE85430400360217904200']);

        $report = $this->importer()->import(
            [$this->row(['IBAN' => 'DE85 4304 0036 0217 9042 00'])],
            (object) ['id' => 7],
            false
        );

        $this->assertCount(1, $report['suspected']);
        $this->assertSame('iban', $report['suspected'][0]['matches'][0]['field']);
        $this->assertSame('schwach', $report['suspected'][0]['matches'][0]['confidence']);
    }

    public function test_ohne_treffer_kein_verdacht(): void
    {
        $this->existing();

        $report = $this->importer()->import(
            [$this->row(['Email' => 'ganz.andere@example.com', 'Telefon' => '+49 30 123456'])],
            (object) ['id' => 7],
            false
        );

        $this->assertSame([], $report['suspected']);
        $this->assertCount(1, $report['created']);
    }

    public function test_dry_run_meldet_den_verdacht_ebenfalls(): void
    {
        // Der Trockenlauf ist die Stelle, an der man Dubletten VORHER sieht.
        $this->existing();

        $report = $this->importer()->import(
            [$this->row(['Email' => 'schlaffkemarie@gmail.com'])],
            (object) ['id' => 7],
            true
        );

        $this->assertCount(1, $report['suspected']);
        $this->assertSame(1, Capsule::table('rec_employees')->count(), 'Dry-Run darf nichts anlegen');
    }

    public function test_treffer_wird_nicht_als_verdacht_gemeldet(): void
    {
        // Wird die Person regulaer erkannt, ist nichts verdaechtig.
        $this->existing(['personnel_number' => '17944']);

        $report = $this->importer()->import(
            [$this->row(['Email' => 'schlaffkemarie@gmail.com'])],
            (object) ['id' => 7],
            false
        );

        $this->assertSame([], $report['suspected']);
        $this->assertSame([], $report['created']);
    }
}
