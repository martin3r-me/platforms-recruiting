<?php

namespace Platform\Recruiting\Tests\Integration;

use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Console\Commands\LohnMaExport;
use Platform\Recruiting\Services\LohnMaErmittlung;

/**
 * Vorflug-Kommando fuer die MA-Loehne (Spec Lohn MA §9): Zaehlung je Fall,
 * Vergleich mit dem ZAS-Stand, CSV, Marker nur auf Wunsch.
 */
class LohnMaExportCommandTest extends TestCase
{
    private const TEAM = 618;

    private string $csv;

    public static function setUpBeforeClass(): void
    {
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
            'database/migrations/2026_05_20_000001_create_rec_employees_table.php',
            'database/migrations/2026_05_21_000005_add_zas_export_markers_to_rec_employees.php',
            'database/migrations/2026_08_26_000002_add_company_to_rec_employees.php',
            'database/migrations/2026_09_10_000001_add_person_key_to_rec_employees.php',
            'database/migrations/2026_09_28_000002_add_rec_person_id_to_rec_employees.php',
            'database/migrations/2026_10_19_000002_add_zuschlag_manuell_to_rec_employees.php',
            'database/migrations/2026_10_21_000001_add_is_test_to_rec_employees.php',
            'database/migrations/2026_05_21_000002_create_rec_employee_hr_data_table.php',
            'database/migrations/2026_10_19_000001_add_zas_lohn_to_hr_data.php',
        ] as $relative) {
            (require $own . '/' . $relative)->up();
        }

        // Steuerklasse und Personalnummer kommen aus grossen Sammelmigrationen:
        // hier nur die Spalten, falls sie nicht schon da sind.
        foreach (['tax_class' => 20, 'personnel_number' => 30] as $spalte => $laenge) {
            if (!Capsule::schema()->hasColumn('rec_employees', $spalte)) {
                Capsule::schema()->table('rec_employees', fn ($t) => $t->string($spalte, $laenge)->nullable());
            }
        }

        Capsule::schema()->create('rec_applicants', function ($t) {
            $t->increments('id');
            $t->decimal('zuschlag', 8, 2)->nullable();
            $t->integer('contract_template_id')->nullable();
        });
        Capsule::schema()->create('rec_contract_templates', function ($t) {
            $t->increments('id');
            $t->string('code', 20)->nullable();
        });
        Capsule::schema()->create('rec_applicant_settings', function ($t) {
            $t->increments('id');
            $t->integer('team_id')->unique();
            $t->text('settings')->nullable();
            $t->timestamps();
        });
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
        LohnMaErmittlung::vergessen();
        foreach (['rec_employees', 'rec_employee_hr_data', 'rec_applicants', 'rec_applicant_settings'] as $t) {
            Capsule::table($t)->delete();
        }
        // RG-Bewerbung 500 mit Zuschlag 1,10: Mindestlohn 13,90 + 1,10 = 15,00.
        Capsule::table('rec_applicants')->insert(['id' => 500, 'zuschlag' => 1.10]);
        foreach ([self::TEAM, self::TEAM + 1] as $team) {
            Capsule::table('rec_applicant_settings')->insert([
                'team_id' => $team, 'settings' => json_encode(['minimum_wage_hourly' => 13.90]),
                'created_at' => '2026-10-01 00:00:00', 'updated_at' => '2026-10-01 00:00:00',
            ]);
        }
        $this->csv = sys_get_temp_dir() . '/lohn-ma-test-' . getmypid() . '.csv';
    }

    protected function tearDown(): void
    {
        if (is_file($this->csv)) {
            unlink($this->csv);
        }
        LohnMaErmittlung::vergessen();
    }

    private function zeile(array $set): int
    {
        static $nr = 3000;
        $nr++;
        Capsule::table('rec_employees')->insert(array_merge([
            'id' => $nr, 'team_id' => self::TEAM, 'uuid' => 'u-' . $nr, 'first_name' => 'A', 'last_name' => 'B',
            'is_active' => 1, 'company' => 'RG', 'zas_changed_at' => null,
            'tax_class' => ($set['company'] ?? 'RG') === 'RG' ? '1' : null,
            'created_at' => '2026-10-01 00:00:00', 'updated_at' => '2026-10-01 00:00:00',
        ], $set));

        return $nr;
    }

    private function zasLohn(int $employeeId, float $grundlohn, float $zuschlag): void
    {
        Capsule::table('rec_employee_hr_data')->insert([
            'uuid' => 'h-' . $employeeId, 'team_id' => self::TEAM, 'rec_employee_id' => $employeeId,
            'zas_grundlohn' => $grundlohn, 'zas_zuschlag' => $zuschlag,
        ]);
    }

    public function test_zahlen_je_fall_und_csv(): void
    {
        // Fall 1 (angleich), Fall 2 (unbekannt), Fall 3 (manuell), Fall 4 (nur MA)
        $this->zeile(['company' => 'RG', 'rec_applicant_id' => 500, 'rec_person_id' => 1]);
        $ma1 = $this->zeile(['company' => 'MA', 'rec_person_id' => 1, 'tax_class' => '6', 'personnel_number' => 'MA1']);
        $this->zeile(['company' => 'RG', 'rec_person_id' => 2]);
        $ma2 = $this->zeile(['company' => 'MA', 'rec_person_id' => 2, 'personnel_number' => 'MA2']);
        $this->zeile(['company' => 'RG', 'rec_person_id' => 3, 'zuschlag_manuell' => 2.50, 'tax_class' => '6']);   // RG 6 + MA 1: nicht widerspruechlich
        $ma3 = $this->zeile(['company' => 'MA', 'rec_person_id' => 3, 'tax_class' => '1', 'personnel_number' => 'MA3']);
        $ma4 = $this->zeile(['company' => 'MA', 'rec_person_id' => 4, 'personnel_number' => 'MA4']);   // Person ohne RG = nur MA (R4)
        $this->zeile(['company' => 'MA', 'is_active' => 0, 'personnel_number' => 'MA5']);   // ausgeschieden: nicht im Lauf
        $this->zeile(['company' => 'RG', 'rec_person_id' => 5, 'zuschlag_manuell' => 2.50]);
        $this->zeile(['company' => 'MA', 'rec_person_id' => 5, 'personnel_number' => 'MA6']);   // Klasse an der MA leer
        $this->zeile(['company' => 'RG', 'rec_person_id' => 6, 'zuschlag_manuell' => 2.50, 'tax_class' => '6']);
        $this->zeile(['company' => 'MA', 'rec_person_id' => 6, 'tax_class' => '6', 'personnel_number' => 'MA7']);   // RG 6 + MA 6: kein Euro
        $this->zeile(['company' => 'MA', 'rec_person_id' => 8, 'tax_class' => '6', 'personnel_number' => 'MA8']);   // nur MA + 6: kein Euro

        $r = (new LohnMaExport())->lauf(false, null, '2026-10-19', $this->csv, fn ($t, $s) => null);

        $this->assertSame(['angleich' => 1, 'zas' => 0, 'unbekannt' => 1, 'manuell' => 2, 'nur_ma' => 2, 'steuerklasse_fehlt' => 1, 'zas_bestand' => 0], $r['zahlen']);
        // unbekannt (MA2) + steuerklasse_fehlt (MA6) liefern beide Zellen leer: wir_leer (I2).
        $this->assertSame(['zas_leer' => 5, 'wir_leer' => 2, 'zas_behaelt_gleich' => 0, 'zas_behaelt_abweichend' => 0], $r['vergleich']);
        $this->assertSame(7, $r['gesamt']);
        $this->assertSame(1, $r['sechs'], 'nur Zeilen mit +1 EUR: RG 1-5 + MA 6, Zuschlag bekannt');
        $this->assertSame(0, $r['markiert']);
        $this->assertSame(0, Capsule::table('rec_employees')->whereNotNull('zas_changed_at')->count(), 'ohne --markieren kein Marker');

        $zeilen = array_map('str_getcsv', file($this->csv, FILE_IGNORE_NEW_LINES));
        $this->assertSame(['employee_id', 'personnel_number', 'name', 'person_id', 'rg_employee_id', 'fall', 'grundlohn', 'zuschlag', 'steuerklasse', 'zas_grundlohn', 'zas_zuschlag', 'vergleich', 'begruendung'], $zeilen[0]);
        $this->assertCount(8, $zeilen);
        $ma2Zeile = array_values(array_filter(array_slice($zeilen, 1), fn ($z) => $z[1] === 'MA2'))[0];
        $this->assertSame('unbekannt', $ma2Zeile[5]);
        $this->assertSame('', $ma2Zeile[6], 'Pre-Merge-Review I2: Grundlohn leer, ZAS behaelt sein Paar');
        $this->assertSame('', $ma2Zeile[7]);
        $this->assertSame('wir_leer', $ma2Zeile[11]);
        $ma6Zeile = array_values(array_filter(array_slice($zeilen, 1), fn ($z) => $z[1] === 'MA6'))[0];
        $this->assertSame('steuerklasse_fehlt', $ma6Zeile[5]);
        $this->assertSame('', $ma6Zeile[6], 'Pre-Merge-Review I2: auch der Grundlohn leer');
        $this->assertSame('', $ma6Zeile[7], 'leere Zelle statt falschem Wert');
        $this->assertSame('wir_leer', $ma6Zeile[11]);
        $ma3Zeile = array_values(array_filter(array_slice($zeilen, 1), fn ($z) => $z[1] === 'MA3'))[0];
        $this->assertSame('15,33', $ma3Zeile[6], 'bekannter Zuschlag: Grundlohn wie bisher');
    }

    public function test_vergleich_mit_dem_zas_stand_der_ma_zeile(): void
    {
        $this->zeile(['company' => 'RG', 'rec_applicant_id' => 500, 'rec_person_id' => 1]);          // RG 15,00
        $gleich = $this->zeile(['company' => 'MA', 'rec_person_id' => 1, 'tax_class' => '6']);         // wir: 15,33 + 1,00
        $this->zasLohn($gleich, 15.33, 1.00);
        $this->zeile(['company' => 'RG', 'rec_applicant_id' => 500, 'rec_person_id' => 2]);
        $alt = $this->zeile(['company' => 'MA', 'rec_person_id' => 2, 'tax_class' => '6']);
        $this->zasLohn($alt, 14.96, 1.37);                                                             // veralteter Stand
        $this->zeile(['company' => 'MA', 'rec_person_id' => 3]);                                       // nur MA, nichts geliefert
        // Pre-Merge-Review I2: unbekannt bzw. Steuerklasse fehlt — wir liefern
        // beide Zellen leer, ZAS behaelt sein Paar; das ist nie "abweichend".
        $unbekannt = $this->zeile(['company' => 'MA']);                                                // ohne Person = unbekannt
        $this->zasLohn($unbekannt, 14.96, 1.37);
        $this->zeile(['company' => 'RG', 'rec_applicant_id' => 500, 'rec_person_id' => 4]);
        $ohneKlasse = $this->zeile(['company' => 'MA', 'rec_person_id' => 4]);                        // MA-Klasse leer
        $this->zasLohn($ohneKlasse, 14.53, 0.80);

        $r = (new LohnMaExport())->lauf(false, null, '2026-10-19', $this->csv, fn ($t, $s) => null);

        // Hotfix 21.10.2026: fuehrt ZAS einen MA-Lohn, senden wir nichts — gezaehlt
        // wird nur noch, ob ZAS mit der Regel uebereinstimmt (Fall 1568).
        $this->assertSame(['zas_leer' => 1, 'wir_leer' => 2, 'zas_behaelt_gleich' => 1, 'zas_behaelt_abweichend' => 1], $r['vergleich']);
        $this->assertSame(2, $r['zahlen']['zas_bestand']);
        $zeilen = array_map('str_getcsv', file($this->csv, FILE_IGNORE_NEW_LINES));
        $altZeile = array_values(array_filter(array_slice($zeilen, 1), fn ($z) => (int) $z[0] === $alt))[0];
        $this->assertSame(['zas_bestand', '', ''], [$altZeile[5], $altZeile[6], $altZeile[7]], 'veralteter ZAS-Stand wird nicht ueberschrieben');
    }

    public function test_markieren_setzt_genau_die_ma_zeilen_des_laufs(): void
    {
        $rg = $this->zeile(['company' => 'RG', 'rec_person_id' => 1]);
        $ma = $this->zeile(['company' => 'MA', 'rec_person_id' => 1]);
        $inaktiv = $this->zeile(['company' => 'MA', 'is_active' => 0]);
        $fremd = $this->zeile(['company' => 'MA', 'team_id' => self::TEAM + 1]);

        $r = (new LohnMaExport())->lauf(true, self::TEAM, null, $this->csv, fn ($t, $s) => null);

        $this->assertSame(1, $r['markiert']);
        $this->assertNotNull(Capsule::table('rec_employees')->where('id', $ma)->value('zas_changed_at'));
        foreach ([$rg, $inaktiv, $fremd] as $id) {
            $this->assertNull(Capsule::table('rec_employees')->where('id', $id)->value('zas_changed_at'));
        }
    }

    public function test_test_ma_wird_nicht_gezaehlt_und_nicht_markiert(): void
    {
        $echt = $this->zeile(['company' => 'MA', 'rec_person_id' => 1]);
        $test = $this->zeile(['company' => 'MA', 'rec_person_id' => 2, 'is_test' => 1]);

        $r = (new LohnMaExport())->lauf(true, self::TEAM, null, $this->csv, fn ($t, $s) => null);

        $this->assertSame(1, $r['gesamt'], 'Test-MA zaehlt nicht mit');
        $this->assertSame(1, $r['markiert']);
        $this->assertNotNull(Capsule::table('rec_employees')->where('id', $echt)->value('zas_changed_at'));
        $this->assertNull(Capsule::table('rec_employees')->where('id', $test)->value('zas_changed_at'), 'Test-MA bleibt unmarkiert');
    }
}
