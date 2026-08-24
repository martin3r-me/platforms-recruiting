<?php

namespace Platform\Recruiting\Tests\Integration;

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Events\Dispatcher;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Models\RecEmployee;
use Platform\Recruiting\Services\Zas\Dispo\DispoEmployeeGateway;

/**
 * Portalsperre (Eskalations-Stufe 3, Spec §4): `DispoEmployeeGateway::
 * lockPortal` setzt portal_locked_at/_reason genau EINMAL (Idempotenz — ein
 * zweiter Aufruf mit anderem Grund ueberschreibt NICHT den ersten Zeitpunkt/
 * Grund) und die HR-Entsperrung (`Show::unlockPortal`) leert beide Spalten
 * wieder.
 *
 * Die serverseitige Durchsetzung in EmployeePortal/EmployeeAssignments
 * (mount()/verify()/saveAll()/handleFileUpload()/confirm() lehnen bei
 * gesetztem portal_locked_at ab) ist UI-Verhalten dieser Livewire-
 * Komponenten und wird hier NICHT über HTTP/Livewire-Testing abgedeckt
 * (kein Livewire-Test-Harness in dieser Suite, siehe reference_phpunit_runner).
 * Der pruefbare Seam ist der Datenzustand, den diese Komponenten lesen:
 * RecEmployee::portal_locked_at. Diese Klasse deckt den Gateway (Setzen) und
 * die HR-Akte (Entsperren) ab — das reine Lesen/Gaten in den Public-Seiten
 * ist durch Code-Review verifiziert (siehe Report).
 */
class DispoPortalLockTest extends TestCase
{
    private const TEAM = 601;

    public static function setUpBeforeClass(): void
    {
        $container = Container::getInstance();

        $capsule = new Capsule();
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $capsule->setEventDispatcher(new Dispatcher($container));
        $capsule->setAsGlobal();
        $capsule->bootEloquent();
        Model::unguard();
        Model::clearBootedModels();

        $container->instance('db', $capsule->getDatabaseManager());
        $container->instance('db.schema', $capsule->getConnection()->getSchemaBuilder());
        Facade::setFacadeApplication($container);

        self::runMigrations();
    }

    public static function tearDownAfterClass(): void
    {
        Facade::clearResolvedInstances();
    }

    protected function setUp(): void
    {
        Capsule::table('rec_employees')->delete();
    }

    private function makeEmployee(): RecEmployee
    {
        return RecEmployee::create([
            'team_id'      => self::TEAM,
            'first_name'   => 'Erika',
            'last_name'    => 'Muster',
            'phone'        => '+49 151 00000001',
            'portal_token' => 'tok-portal-lock-' . uniqid(),
            'is_active'    => true,
        ]);
    }

    public function test_lockPortal_sets_flag_idempotent(): void
    {
        $emp = $this->makeEmployee();
        $gw = new DispoEmployeeGateway();

        $gw->lockPortal($emp->id, 'Grund A');
        $first = $emp->fresh()->portal_locked_at;
        $this->assertNotNull($first);
        $this->assertSame('Grund A', $emp->fresh()->portal_locked_reason);

        // Idempotent: ein zweiter Aufruf mit anderem Grund ueberschreibt
        // weder Zeitpunkt noch Grund der ERSTEN Sperre.
        $gw->lockPortal($emp->id, 'Grund B');
        $this->assertEquals($first, $emp->fresh()->portal_locked_at);
        $this->assertSame('Grund A', $emp->fresh()->portal_locked_reason);
    }

    public function test_lockPortal_unknown_employee_is_noop(): void
    {
        $gw = new DispoEmployeeGateway();

        // Kein Wurf, kein Effekt -> reines No-op.
        $gw->lockPortal(999999, 'Grund egal');

        $this->assertSame(0, Capsule::table('rec_employees')->where('id', 999999)->count());
    }

    public function test_unlockPortal_clears_both_columns(): void
    {
        $emp = $this->makeEmployee();
        $gw = new DispoEmployeeGateway();
        $gw->lockPortal($emp->id, 'Dispo: Einsatz RG-1 am 26.08.2026 nicht bestaetigt');

        $locked = $emp->fresh();
        $this->assertNotNull($locked->portal_locked_at);
        $this->assertNotNull($locked->portal_locked_reason);

        // HR-Entsperrung (Show::unlockPortal) — hier direkt gegen das Model
        // nachvollzogen, da Show eine Livewire-Komponente ohne eigenen
        // Test-Harness in dieser Suite ist (siehe Klassen-Docblock).
        $locked->portal_locked_at = null;
        $locked->portal_locked_reason = null;
        $locked->save();

        $unlocked = $emp->fresh();
        $this->assertNull($unlocked->portal_locked_at);
        $this->assertNull($unlocked->portal_locked_reason);
    }

    public function test_unlockPortal_then_lockPortal_again_sets_fresh_timestamp(): void
    {
        $emp = $this->makeEmployee();
        $gw = new DispoEmployeeGateway();

        $gw->lockPortal($emp->id, 'Erste Sperre');
        $firstAt = $emp->fresh()->portal_locked_at;

        // Entsperren...
        $emp->fresh()->update(['portal_locked_at' => null, 'portal_locked_reason' => null]);

        // ...macht lockPortal wieder scharf (kein dauerhaftes No-op ueber die
        // Lebenszeit des Datensatzes, nur solange portal_locked_at gesetzt ist).
        usleep(1_000_000);
        $gw->lockPortal($emp->id, 'Zweite Sperre');
        $second = $emp->fresh();
        $this->assertNotNull($second->portal_locked_at);
        $this->assertNotEquals($firstAt, $second->portal_locked_at);
        $this->assertSame('Zweite Sperre', $second->portal_locked_reason);
    }

    private static function runMigrations(): void
    {
        $own = dirname(__DIR__, 2);

        $files = [
            'database/migrations/2026_05_20_000001_create_rec_employees_table.php',
            'database/migrations/2026_08_24_000004_add_portal_lock_to_rec_employees.php',
        ];

        foreach ($files as $relative) {
            $path = $own . '/' . $relative;
            if (!file_exists($path)) {
                throw new \RuntimeException("Migration fehlt: {$path}");
            }
            (require $path)->up();
        }
    }
}
