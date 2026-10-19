<?php

namespace Platform\Recruiting\Tests\Integration;

use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Models\RecApplicant;
use Platform\Recruiting\Models\RecEmployee;
use Platform\Recruiting\Models\RecEmployeeHrData;
use Platform\Recruiting\Observers\RecEmployeeExportObserver;

/**
 * Zuschlag-Aenderungen (Bewerbung, HR, ZAS-Lieferung) muessen RG-Zeile und
 * aktive MA-Schwestern fuer den ZAS-Export markieren (Spec Lohn MA §8).
 */
class LohnMaReExportTest extends TestCase
{
    private const TEAM = 614;

    public static function setUpBeforeClass(): void
    {
        // \Log:: im Observer nutzt den globalen Alias, den es ausserhalb
        // einer gebooteten Laravel-App nicht gibt. Ohne ihn verdeckt ein
        // "Class Log not found" im catch-Zweig den eigentlichen Fehler.
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
            'database/migrations/2026_05_20_000001_create_rec_employees_table.php',
            'database/migrations/2026_05_21_000005_add_zas_export_markers_to_rec_employees.php',
            // Ohne diese Spalten scheitert das Lohn-Tracking des Observers
            // still (safelyRun) — der Test wuerde den Marker pruefen, ohne
            // den Produktionspfad gelaufen zu sein.
            'database/migrations/2026_06_05_000001_add_payroll_tracking_to_rec_employees.php',
            'database/migrations/2026_09_23_000002_add_employer_fields_to_rec_employees.php',
            'database/migrations/2026_08_26_000002_add_company_to_rec_employees.php',
            'database/migrations/2026_09_10_000001_add_person_key_to_rec_employees.php',
            'database/migrations/2026_09_28_000002_add_rec_person_id_to_rec_employees.php',
            'database/migrations/2026_10_19_000002_add_zuschlag_manuell_to_rec_employees.php',
            'database/migrations/2026_05_21_000002_create_rec_employee_hr_data_table.php',
            'database/migrations/2026_10_19_000001_add_zas_lohn_to_hr_data.php',
        ] as $relative) {
            (require $own . '/' . $relative)->up();
        }

        // Das Lohn-Tracking des Observers liest die Team-Einstellungen; ohne
        // die Tabelle liefe der Lauf in den stillen Fehlerzweig und der Test
        // pruefte den Marker, ohne den Produktionspfad gelaufen zu sein.
        Capsule::schema()->create('rec_applicant_settings', function ($t) {
            $t->increments('id');
            $t->integer('team_id')->unique();
            $t->text('settings')->nullable();
            $t->timestamps();
        });

        RecEmployeeExportObserver::register();
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
        Capsule::table('rec_employee_hr_data')->delete();
    }

    private function zeile(array $set): int
    {
        static $nr = 2000;
        $nr++;
        Capsule::table('rec_employees')->insert(array_merge([
            'id' => $nr, 'team_id' => self::TEAM, 'uuid' => 'u-' . $nr, 'first_name' => 'A', 'last_name' => 'B',
            'is_active' => 1, 'company' => 'RG', 'zas_changed_at' => null,
            'created_at' => '2026-10-01 00:00:00', 'updated_at' => '2026-10-01 00:00:00',
        ], $set));

        return $nr;
    }

    private function markiert(int $id): bool
    {
        return Capsule::table('rec_employees')->where('id', $id)->value('zas_changed_at') !== null;
    }

    /** Review Focus 4. */
    public function test_bewerbungs_zuschlag_markiert_rg_und_ma_nicht_fremde(): void
    {
        $rg = $this->zeile(['rec_applicant_id' => 500, 'rec_person_id' => 9]);
        $ma = $this->zeile(['company' => 'MA', 'rec_person_id' => 9]);
        $fremd = $this->zeile(['company' => 'MA', 'rec_person_id' => 10]);

        $a = new RecApplicant();
        $a->setRawAttributes(['id' => 500, 'zuschlag' => '1.10'], true);
        $a->zuschlag = '2.00';
        $a->syncChanges();
        Container::getInstance()->make('events')->dispatch('eloquent.saved: ' . RecApplicant::class, [$a]);

        $this->assertTrue($this->markiert($rg));
        $this->assertTrue($this->markiert($ma));
        $this->assertFalse($this->markiert($fremd));
    }

    public function test_bewerbung_ohne_zuschlag_aenderung_markiert_nichts(): void
    {
        $rg = $this->zeile(['rec_applicant_id' => 500, 'rec_person_id' => 9]);

        $a = new RecApplicant();
        $a->setRawAttributes(['id' => 500, 'zuschlag' => '1.10', 'first_name' => 'X'], true);
        $a->first_name = 'Y';
        $a->syncChanges();
        Container::getInstance()->make('events')->dispatch('eloquent.saved: ' . RecApplicant::class, [$a]);

        $this->assertFalse($this->markiert($rg));
    }

    public function test_zuschlag_manuell_markiert_rg_und_ma_ueber_person_key(): void
    {
        $rg = $this->zeile(['person_key' => 'k-7']);
        $ma = $this->zeile(['company' => 'MA', 'person_key' => 'k-7']);
        $maInaktiv = $this->zeile(['company' => 'MA', 'person_key' => 'k-7', 'is_active' => 0]);

        RecEmployee::query()->findOrFail($rg)->update(['zuschlag_manuell' => 1.10]);

        $this->assertTrue($this->markiert($rg));
        $this->assertTrue($this->markiert($ma));
        $this->assertFalse($this->markiert($maInaktiv), 'ausgeschiedene MA-Zeile geht nicht an ZAS');
    }

    /** ZAS liefert fuer die RG-Zeile einen neuen Zuschlag: nur die MA-Schwester geht neu raus, die RG-Zeile ist kein Echo. */
    public function test_zas_zuschlag_aus_dem_inbound_markiert_nur_die_ma_schwester(): void
    {
        $rg = $this->zeile(['rec_person_id' => 9]);
        $ma = $this->zeile(['company' => 'MA', 'rec_person_id' => 9]);

        RecEmployeeHrData::query()->create(['team_id' => self::TEAM, 'rec_employee_id' => $rg, 'zas_grundlohn' => 13.90, 'zas_zuschlag' => 1.10]);
        $this->assertTrue($this->markiert($ma), 'Erstlieferung ist eine Aenderung von null auf 1,10');

        Capsule::table('rec_employees')->update(['zas_changed_at' => null]);
        RecEmployeeHrData::query()->where('rec_employee_id', $rg)->first()->update(['zas_zuschlag' => 2.10]);

        $this->assertTrue($this->markiert($ma));
        $this->assertFalse($this->markiert($rg));
    }
    /** Final-Review F1: RG hat schon eine Person, die MA-Zeile nur den person_key — sie rechnet mit diesem RG-Zuschlag, also muss sie mit. */
    public function test_rg_mit_person_markiert_ma_mit_nur_person_key(): void
    {
        $rg = $this->zeile(['rec_person_id' => 9, 'person_key' => 'k-9']);
        $ma = $this->zeile(['company' => 'MA', 'person_key' => 'k-9']);
        $fremd = $this->zeile(['company' => 'MA', 'person_key' => 'k-10']);

        RecEmployee::query()->findOrFail($rg)->update(['zuschlag_manuell' => 1.10]);

        $this->assertTrue($this->markiert($ma));
        $this->assertFalse($this->markiert($fremd));
    }

    /** Final-Review F3a: RG-Anstellung endet — die MA-Schwester faellt auf "nur MA" und muss neu raus. */
    public function test_rg_deaktiviert_markiert_ma_schwester(): void
    {
        $rg = $this->zeile(['rec_person_id' => 9]);
        $ma = $this->zeile(['company' => 'MA', 'rec_person_id' => 9]);
        $fremd = $this->zeile(['company' => 'MA', 'rec_person_id' => 10]);

        RecEmployee::query()->findOrFail($rg)->update(['is_active' => false]);

        $this->assertTrue($this->markiert($ma));
        $this->assertFalse($this->markiert($fremd));
    }

    /** Final-Review F3a: Firmenwechsel RG -> MA — die bisherige RG-Schwester ist weg. */
    public function test_firmenwechsel_rg_nach_ma_markiert_ma_schwester(): void
    {
        $rg = $this->zeile(['rec_person_id' => 9]);
        $ma = $this->zeile(['company' => 'MA', 'rec_person_id' => 9]);

        RecEmployee::query()->findOrFail($rg)->update(['company' => 'MA']);

        $this->assertTrue($this->markiert($ma));
    }

    /** Gegenprobe: Aktiv-Wechsel einer MA-Zeile beruehrt die anderen MA-Zeilen nicht. */
    public function test_ma_deaktiviert_markiert_keine_ma_schwester(): void
    {
        $ma1 = $this->zeile(['company' => 'MA', 'rec_person_id' => 9]);
        $ma2 = $this->zeile(['company' => 'MA', 'rec_person_id' => 9]);

        RecEmployee::query()->findOrFail($ma1)->update(['is_active' => false]);

        $this->assertFalse($this->markiert($ma2));
    }
}
