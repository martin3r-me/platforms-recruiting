<?php

namespace Platform\Recruiting\Tests\Integration;

use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Models\RecEmployee;
use Platform\Recruiting\Observers\RecEmployeeExportObserver;

/**
 * Haupt-/Nebenarbeitgeber darf (noch) KEINEN ZAS-Update-Marker setzen.
 *
 * Die Felder gehen erst raus, wenn der Kunde die Rueckfrage beantwortet hat.
 * Bis dahin gilt: unsere Aktualisierungsdatei liefert VOLLE ZEILEN — ein
 * Marker auf einem Bestandsmitarbeiter wuerde also dessen komplette, in ZAS
 * gepflegte Akte ueberschreiben. Das ist der Vorfall vom 02.09.2026.
 *
 * WARUM DIESER TEST NEBEN DER FELDLISTE STEHT: PortalEmployerFieldsTest
 * prueft, dass die beiden Spalten nicht in RELEVANT_EMPLOYEE_FIELDS stehen.
 * Das ist die Absicht. Hier wird das ERGEBNIS gemessen — mit registriertem
 * Observer und einem echten Schreibvorgang. Ein Test auf der Liste allein
 * uebersaehe, wenn ein Schreibweg spaeter noch andere Spalten mitschreibt
 * (etwa ein erweitertes update() in ContractSigning).
 */
class EmployerFieldsNoExportMarkerTest extends TestCase
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
    }

    private function employee(): RecEmployee
    {
        return RecEmployee::create([
            'team_id'      => self::TEAM,
            'first_name'   => 'Erika',
            'last_name'    => 'Muster',
            'portal_token' => 'tok-marker-' . uniqid(),
            'is_active'    => true,
        ]);
    }

    public function test_arbeitgeber_angabe_setzt_keinen_update_marker(): void
    {
        $employee = $this->employee();
        Capsule::table('rec_employees')->where('id', $employee->id)->update(['zas_changed_at' => null]);

        $employee->update(['is_main_employer' => false, 'other_employer' => 'Musterkantine GmbH']);

        $this->assertNull(
            $employee->fresh()->zas_changed_at,
            'Sonst landet der Mitarbeiter mit einer VOLLEN Zeile in updates.csv — Vorfall 02.09.2026.',
        );
    }

    public function test_aenderung_der_angabe_setzt_ebenfalls_keinen_marker(): void
    {
        $employee = $this->employee();
        $employee->update(['is_main_employer' => true]);
        Capsule::table('rec_employees')->where('id', $employee->id)->update(['zas_changed_at' => null]);

        $employee->update(['is_main_employer' => false, 'other_employer' => 'Andere GmbH']);

        $this->assertNull($employee->fresh()->zas_changed_at);

        // Gegenprobe, dass der Observer hier wirklich gearbeitet hat und
        // nicht still in safelyRun gescheitert ist: die Angabe ist
        // lohnrelevant, ein Wechsel ja->nein muss in der Lohnliste stehen.
        $this->assertNotNull(
            $employee->fresh()->payroll_data_changed_at,
            'Der Lohn-Pfad muss gelaufen sein — sonst prueft dieser Test nichts.',
        );
        $this->assertStringContainsString('is_main_employer', json_encode($employee->fresh()->payroll_data_changed_fields));
    }

    /**
     * Gegenprobe: der Observer ist wirklich scharf. Ohne sie waere der Test
     * oben auch dann gruen, wenn die Registrierung stillschweigend fehlte.
     */
    public function test_ein_exportiertes_feld_setzt_den_marker_sehr_wohl(): void
    {
        $employee = $this->employee();
        Capsule::table('rec_employees')->where('id', $employee->id)->update(['zas_changed_at' => null]);

        $employee->update(['city' => 'Duesseldorf']);

        $this->assertNotNull($employee->fresh()->zas_changed_at);
    }
}
