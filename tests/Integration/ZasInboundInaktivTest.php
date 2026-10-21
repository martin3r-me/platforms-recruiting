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
use Platform\Recruiting\Services\Zas\Dispo\DispoEmployeeDirectory;
use Platform\Recruiting\Services\Zas\Dispo\ZasDispoMatcher;
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
            // Personen-Spalten: Marker der MA-Schwestern beim Aktiv-Wechsel (Lohn MA F3).
            $t->integer('rec_person_id')->nullable();
            $t->string('person_key')->nullable();
            $t->boolean('is_active')->default(true);
            $t->boolean('is_test')->default(false);
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

    public function test_inaktiv_ueberschreibt_hr_deaktivierung_nicht_und_go_reaktiviert_danach_nicht(): void
    {
        // HR hat von Hand deaktiviert, der gespeicherte Status ist GO. Eine
        // INAKTIV-Zeile darf das nicht in eine ZAS-Inaktivitaet umschreiben —
        // sonst wuerde ein spaeteres GO/MA den Mitarbeiter wieder aktivieren.
        $employee = $this->makeEmployee('GO', false);

        $report = $this->importer()->import([$this->row(['Status' => 'inaktiv'])], (object) ['id' => 99], false);

        $this->assertSame('GO', $this->hrStatus($employee->id), 'HR-Herkunft der Deaktivierung bleibt erhalten');
        $this->assertFalse($this->isActive($employee->id));
        $this->assertSame([], $report['updated']);
        $this->assertSame([], $report['deactivated']);
        $this->assertSame('exists', $report['skipped'][0]['reason']);

        $report = $this->importer()->import([$this->row(['Status' => 'GO'])], (object) ['id' => 99], false);

        $this->assertFalse($this->isActive($employee->id), 'GO nach INAKTIV darf die HR-Deaktivierung nicht aufheben');
        $this->assertSame([], $report['reactivated']);
    }

    public function test_inaktiv_laesst_ma_seit_einer_hr_deaktivierten_ma_stehen(): void
    {
        $employee = $this->makeEmployee('MA', false);
        Capsule::table('rec_employee_hr_data')->where('rec_employee_id', $employee->id)->update(['status_ma_since' => '2026-01-01']);

        $report = $this->importer()->import([$this->row(['Status' => 'inaktiv', 'StatusMASeit' => ''])], (object) ['id' => 99], false);

        $this->assertSame('MA', $this->hrStatus($employee->id));
        $this->assertSame(
            '2026-01-01',
            substr((string) Capsule::table('rec_employee_hr_data')->where('rec_employee_id', $employee->id)->value('status_ma_since'), 0, 10)
        );
        $this->assertSame([], $report['updated']);
    }

    public function test_bereits_von_zas_inaktiver_bleibt_bei_erneutem_inaktiv_unberuehrt(): void
    {
        $employee = $this->makeEmployee('INAKTIV', false);

        $report = $this->importer()->import([$this->row(['Status' => 'inaktiv'])], (object) ['id' => 99], false);

        $this->assertSame('exists', $report['skipped'][0]['reason']);
        $this->assertSame([], $report['deactivated']);
        $this->assertSame([], $report['updated']);
        $this->assertFalse($this->isActive($employee->id));
    }

    public function test_unbekannter_inaktiver_zweimal_geliefert_ergibt_genau_einen_datensatz(): void
    {
        $row = $this->row([
            'ZasPersonalNr' => '9101',
            'Name'          => 'Doppelt',
            'Vorname'       => 'Inaktiv',
            'Status'        => 'inaktiv',
        ]);

        $first  = $this->importer()->import([$row], (object) ['id' => 99], false);
        $second = $this->importer()->import([$row], (object) ['id' => 100], false);

        $this->assertCount(1, $first['created']);
        $this->assertSame(1, Capsule::table('rec_employees')->where('personnel_number', '9101')->count());
        $this->assertSame([], $second['created']);
        $this->assertSame('exists', $second['skipped'][0]['reason']);
        $this->assertNull(Capsule::table('rec_employees')->where('personnel_number', '9101')->value('zas_changed_at'));
    }

    public function test_dispo_zuordnung_bevorzugt_den_aktiven_vor_dem_inaktiven_altbestand(): void
    {
        // Aktiver mit langer Nummer, von der Dispo gekuerzt als MA878 geliefert,
        // daneben der inaktive Alt-Datensatz mit der echten Nummer MA878.
        $aktiv = $this->makeEmployee('GO', true, ['personnel_number' => 'MA1000000878']);
        $alt   = $this->makeEmployee('INAKTIV', false, ['personnel_number' => 'MA878']);

        $directory = new DispoEmployeeDirectory();
        $this->assertSame([$aktiv->id], $directory->activeIds());

        $matcher = new ZasDispoMatcher($directory->map(), 'RG', $directory->activeIds());
        $this->assertSame(['employee_id' => $aktiv->id, 'reason' => 'active_preferred'], $matcher->match('MA878'));
        $this->assertSame(['employee_id' => $aktiv->id, 'reason' => 'exact'], $matcher->match('MA1000000878'));
        $this->assertNotSame($alt->id, $matcher->match('MA878')['employee_id']);
    }

    public function test_dispo_import_reicht_die_aktiven_an_den_matcher(): void
    {
        $source = file_get_contents(__DIR__ . '/../../src/Services/Zas/Dispo/ZasDispoWebexportImporter.php');

        $this->assertMatchesRegularExpression(
            '/new ZasDispoMatcher\(\s*\$this->directory->map\(\),\s*[^;]*\$this->directory->activeIds\(\)\s*\)/s',
            $source
        );
    }

    public function test_hr_aktiviert_von_zas_inaktiven_springt_status_auf_go(): void
    {
        $employee = $this->makeEmployee('INAKTIV', false);

        $employee->update(['is_active' => true]);

        $this->assertSame('GO', $this->hrStatus($employee->id), 'aktiv bei uns heisst auch Status aktiv');
        $this->assertTrue($this->isActive($employee->id));
    }

    public function test_aktivieren_laesst_ma_status_stehen_und_ohne_wechsel_kein_sprung(): void
    {
        $ma = $this->makeEmployee('MA', false);
        $ma->update(['is_active' => true]);
        $this->assertSame('MA', $this->hrStatus($ma->id), 'MA bleibt MA, nur INAKTIV springt');

        $inaktiv = $this->makeEmployee('INAKTIV', true, ['personnel_number' => '4712']);
        $inaktiv->update(['first_name' => 'Anders']);
        $this->assertSame('INAKTIV', $this->hrStatus($inaktiv->id), 'ohne Wechsel von is_active kein Sprung');

        $inaktiv->update(['is_active' => false]);
        $this->assertSame('INAKTIV', $this->hrStatus($inaktiv->id), 'Deaktivieren setzt nichts');
    }
    /**
     * Lohn MA, Final-Review F3b: ZAS deaktiviert die RG-Anstellung — die
     * MA-Schwester verliert ihre RG-Schwester und muss mit neuem Zuschlag
     * raus; die RG-Zeile selbst bleibt ohne Marker (kein Echo).
     */
    public function test_inaktiv_einer_rg_zeile_markiert_die_ma_schwester(): void
    {
        $rg = $this->makeEmployee('GO', true, ['rec_person_id' => 9]);
        $ma = $this->makeEmployee('GO', true, ['company' => 'MA', 'personnel_number' => '9911', 'rec_person_id' => 9]);
        $fremd = $this->makeEmployee('GO', true, ['company' => 'MA', 'personnel_number' => '9912', 'rec_person_id' => 10]);

        $this->importer()->import([$this->row(['Status' => 'inaktiv'])], (object) ['id' => 99], false);

        $this->assertFalse($this->isActive($rg->id));
        $this->assertNull(Capsule::table('rec_employees')->where('id', $rg->id)->value('zas_changed_at'));
        $this->assertNotNull(Capsule::table('rec_employees')->where('id', $ma->id)->value('zas_changed_at'));
        $this->assertNull(Capsule::table('rec_employees')->where('id', $fremd->id)->value('zas_changed_at'));
    }

    /** Gegenprobe: Status-Lieferung ohne Aktiv-Wechsel markiert keine Schwester. */
    public function test_statuswechsel_ohne_aktiv_wechsel_markiert_keine_ma_schwester(): void
    {
        $this->makeEmployee('GO', true, ['rec_person_id' => 9]);
        $ma = $this->makeEmployee('GO', true, ['company' => 'MA', 'personnel_number' => '9911', 'rec_person_id' => 9]);

        $this->importer()->import([$this->row(['Status' => 'MA'])], (object) ['id' => 99], false);

        $this->assertNull(Capsule::table('rec_employees')->where('id', $ma->id)->value('zas_changed_at'));
    }
}
