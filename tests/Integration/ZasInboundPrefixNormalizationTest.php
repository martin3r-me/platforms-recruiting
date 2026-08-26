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
use Platform\Recruiting\Services\Zas\ZasInboundDuplicateFinder;
use Platform\Recruiting\Services\Zas\ZasInboundEmployeeImporter;
use Platform\Recruiting\Services\Zas\ZasInboundRowMapper;
use Platform\Recruiting\Services\Zas\ZasLookupReverseResolver;

/**
 * Praefix-Normalisierung beim Mitarbeiter-Import.
 *
 * ZAS stellt den Mitarbeiter-Export auf `RG353`/`MA353` um, liefert bis dahin
 * aber weiter blanke Nummern. Damit es keinen Stichtag braucht, setzt der
 * Import den eigenen Praefix selbst vor, wenn keiner mitkommt.
 *
 * Der wichtigste Fall ist deshalb der UEBERGANG: bei uns steht bereits
 * `RG353`, ZAS liefert noch `353` — das muss ein Treffer sein, sonst legen wir
 * denselben Menschen ein zweites Mal an. Genau daran waeren wir gescheitert,
 * haetten wir die Bestandsnummern einfach praefixt, ohne den Import
 * anzupassen.
 */
class ZasInboundPrefixNormalizationTest extends TestCase
{
    private const TEAM = 21;

    public static function setUpBeforeClass(): void
    {
        $container = Container::getInstance();
        $container->instance('config', new ConfigRepository([
            'activity-log' => ['events' => []],
            'recruiting'   => ['zas' => [
                'inbound_team_id' => self::TEAM,
                'company_prefix'  => 'RG',
            ]],
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
            $t->string('company')->nullable();
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

    private function existing(string $personnelNumber, array $overrides = []): RecEmployee
    {
        $employee = RecEmployee::create(array_merge([
            'team_id'          => self::TEAM,
            'uuid'             => 'uuid-' . $personnelNumber,
            'first_name'       => 'Bestand',
            'last_name'        => 'Person',
            'personnel_number' => $personnelNumber,
            'is_active'        => true,
        ], $overrides));

        RecEmployeeHrData::create([
            'rec_employee_id' => $employee->id,
            'team_id'         => self::TEAM,
            'export_status'   => 'GO',
        ]);

        return $employee->fresh();
    }

    private function row(string $pnr, array $overrides = []): array
    {
        return array_merge([
            'UUID'          => '',
            'ZasPersonalNr' => $pnr,
            'Name'          => 'Neu',
            'Vorname'       => 'Person',
            'Status'        => 'GO',
        ], $overrides);
    }

    public function test_blanke_nummer_wird_praefixt_angelegt(): void
    {
        $report = $this->importer()->import([$this->row('353')], (object) ['id' => 1], false);

        $this->assertCount(1, $report['created']);
        $this->assertSame('RG353', Capsule::table('rec_employees')->value('personnel_number'));
    }

    public function test_uebergang_blanke_lieferung_trifft_praefixten_bestand(): void
    {
        // DER kritische Fall: Migration hat uns auf RG353 gezogen, ZAS liefert
        // noch 353. Ohne Normalisierung entstuende hier eine Dublette.
        $employee = $this->existing('RG353');

        $report = $this->importer()->import([$this->row('353')], (object) ['id' => 1], false);

        $this->assertSame([], $report['created'], 'darf keine Dublette anlegen');
        $this->assertSame(1, Capsule::table('rec_employees')->count());
        $this->assertSame('RG353', $employee->fresh()->personnel_number);
    }

    public function test_praefixte_lieferung_trifft_praefixten_bestand(): void
    {
        $this->existing('RG353');

        $report = $this->importer()->import([$this->row('RG353')], (object) ['id' => 1], false);

        $this->assertSame([], $report['created']);
        $this->assertSame(1, Capsule::table('rec_employees')->count());
    }

    public function test_fremder_praefix_ist_eine_andere_person(): void
    {
        // Der eigentliche Zweck der ganzen Uebung: MA353 und RG353 sind zwei
        // verschiedene Menschen und muessen es auch bleiben.
        $this->existing('RG353');

        $report = $this->importer()->import([$this->row('MA353')], (object) ['id' => 1], false);

        $this->assertCount(1, $report['created'], 'MA353 ist nicht RG353');
        $this->assertSame(2, Capsule::table('rec_employees')->count());
        $this->assertSame(
            'MA353',
            Capsule::table('rec_employees')->orderByDesc('id')->value('personnel_number'),
            'fremder Praefix darf nicht ueberschrieben werden'
        );
    }

    public function test_bestand_ohne_praefix_wird_trotz_normalisierung_gefunden(): void
    {
        // Deploy-Fenster: Code ist live, die Migration lief noch nicht. Bei uns
        // steht also 353, geliefert wird 353, normalisiert RG353. Ohne Suche in
        // beiden Formen entstuende hier eine Dublette — und zwar genau in den
        // Minuten zwischen zwei Deploys.
        $employee = $this->existing('353');

        $report = $this->importer()->import([$this->row('353')], (object) ['id' => 1], false);

        $this->assertSame([], $report['created'], 'darf keine Dublette anlegen');
        $this->assertSame(1, Capsule::table('rec_employees')->count());
        $this->assertSame('353', $employee->fresh()->personnel_number, 'Bestandswert bleibt unangetastet');
    }

    public function test_firma_wird_aus_dem_praefix_gesetzt(): void
    {
        $this->importer()->import([$this->row('MA1000000878')], (object) ['id' => 1], false);

        $this->assertSame('MA', Capsule::table('rec_employees')->value('company'));
    }

    public function test_blanke_nummer_ergibt_die_eigene_firma(): void
    {
        $this->importer()->import([$this->row('353')], (object) ['id' => 1], false);

        $this->assertSame('RG', Capsule::table('rec_employees')->value('company'));
    }

    public function test_firma_wird_bei_treffer_nachgetragen(): void
    {
        $employee = $this->existing('RG353');

        $this->importer()->import([$this->row('RG353')], (object) ['id' => 1], false);

        $this->assertSame('RG', $employee->fresh()->company);
    }

    public function test_gesetzte_firma_wird_nicht_ueberschrieben(): void
    {
        // Von HR gepflegt oder bewusst korrigiert — ZAS darf das nicht kippen.
        $employee = $this->existing('RG353', ['company' => 'MA']);

        $this->importer()->import([$this->row('RG353')], (object) ['id' => 1], false);

        $this->assertSame('MA', $employee->fresh()->company);
    }

    public function test_lange_lieferung_findet_gekuerzten_bestand(): void
    {
        // Szenario ab der ZAS-Umstellung auf ungekuerzte Nummern: bei uns steht
        // die gekuerzte Form aus frueheren Lieferungen, geliefert wird die
        // volle. Ohne diesen Kandidaten waere das fuer jeden ohne UUID eine
        // Dublette — und die Nummer ist gleichzeitig unser Dubletten-Schluessel.
        $this->existing('RG17944');

        $report = $this->importer()->import([$this->row('RG1000017944')], (object) ['id' => 1], false);

        $this->assertSame([], $report['created'], 'darf keine Dublette anlegen');
        $this->assertSame(1, Capsule::table('rec_employees')->count());
    }

    public function test_lange_lieferung_greift_nicht_auf_die_andere_firma_ueber(): void
    {
        // MA1000017944 kuerzt sich zu MA17944 — und darf unseren RG17944
        // niemals finden.
        $this->existing('RG17944');

        $report = $this->importer()->import([$this->row('MA1000017944')], (object) ['id' => 1], false);

        $this->assertCount(1, $report['created']);
        $this->assertSame(2, Capsule::table('rec_employees')->count());
    }

    public function test_nachtrag_schreibt_die_praefixte_form(): void
    {
        // Bestands-MA ohne Nummer, Treffer ueber UUID: nachgetragen wird die
        // normalisierte Form, nicht die blanke.
        $employee = $this->existing('', ['uuid' => 'bekannte-uuid']);

        $this->importer()->import(
            [$this->row('353', ['UUID' => 'bekannte-uuid'])],
            (object) ['id' => 1],
            false
        );

        $this->assertSame('RG353', $employee->fresh()->personnel_number);
    }
}
