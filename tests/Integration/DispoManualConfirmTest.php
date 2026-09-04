<?php

namespace Platform\Recruiting\Tests\Integration;

use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Models\RecDispoAssignment;
use Platform\Recruiting\Models\RecDispoEvent;
use Platform\Recruiting\Models\RecEmployee;
use Platform\Recruiting\Services\Zas\Dispo\DispoEmployeeGateway;
use Platform\Recruiting\Services\Zas\Dispo\DispoManualConfirm;

/**
 * Manuell bestaetigen (Kunde 04.09., Esra-Fall): bestaetigt alle kommenden
 * Auftrags-Tage der Person in der VA (inkl. Rueckholung aus "zur Loeschung
 * gemeldet"), stempelt WER bestaetigt hat, und entsperrt das Portal
 * GRUPPENWEIT — aber nur Dispo-Sperren.
 */
class DispoManualConfirmTest extends TestCase
{
    private const TEAM = 901;

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
        $container->instance('config', new ConfigRepository([]));

        self::runMigrations();
    }

    public static function tearDownAfterClass(): void
    {
        Container::getInstance()->forgetInstance('config');
        Facade::clearResolvedInstances();
    }

    protected function setUp(): void
    {
        Capsule::table('rec_dispo_assignments')->delete();
        Capsule::table('rec_dispo_events')->delete();
        Capsule::table('rec_employees')->delete();
    }

    private function employee(string $pnr, ?string $lockReason = null): RecEmployee
    {
        return RecEmployee::create([
            'team_id' => self::TEAM, 'first_name' => 'E', 'last_name' => $pnr,
            'personnel_number' => $pnr, 'portal_token' => 'tok-' . $pnr, 'is_active' => true,
            'portal_locked_at' => $lockReason !== null ? now() : null,
            'portal_locked_reason' => $lockReason,
        ]);
    }

    private function row(int $eventId, int $employeeId, string $dsRef, string $datum, array $extra = []): RecDispoAssignment
    {
        return RecDispoAssignment::create(array_merge([
            'ds_ref' => $dsRef, 'rec_dispo_event_id' => $eventId, 'pnr_raw' => 'X',
            'rec_employee_id' => $employeeId, 'datum' => $datum, 'von' => '09:00', 'bis' => '17:00',
            'status_id' => RecDispoAssignment::STATUS_AUFTRAG,
            'reminder_sent_at' => now()->subDay(),
        ], $extra));
    }

    public function test_confirms_upcoming_rows_revives_deleted_and_unlocks_the_whole_group(): void
    {
        $event = RecDispoEvent::create(['einsatz_ref' => 'E-MC-1']);
        $booked = $this->employee('RG1', 'Dispo: Einsatz E-MC-1 am 05.09.2026 nicht bestaetigt');
        $twin = $this->employee('MA1', 'Dispo: Einsatz E-MC-1 am 05.09.2026 nicht bestaetigt');

        $open = $this->row($event->id, $booked->id, 'DS-1', now()->addDay()->toDateString());
        $deleted = $this->row($event->id, $booked->id, 'DS-2', now()->addDays(2)->toDateString(), [
            'deletion_marked_at' => now()->subHour(),
            'reconfirm_required_at' => now()->subHour(), 'reconfirm_previous' => ['von' => '08:00'],
        ]);
        $past = $this->row($event->id, $booked->id, 'DS-3', now()->subDay()->toDateString());

        $n = (new DispoManualConfirm(new DispoEmployeeGateway()))->confirm((int) $event->id, [$booked->id, $twin->id], 42);

        $this->assertSame(2, $n, 'Offene + zur Loeschung gemeldete kommende Zeilen.');
        $open->refresh();
        $this->assertNotNull($open->confirmed_at);
        $this->assertSame('09:00', (string) $open->confirmed_von, 'Zeiten-Schnappschuss wie bei Selbstbestaetigung.');
        $this->assertSame(42, (int) $open->manually_confirmed_by_user_id);
        $deleted->refresh();
        $this->assertNotNull($deleted->confirmed_at);
        $this->assertNull($deleted->deletion_marked_at, 'Rueckholung aus der Loeschung.');
        $this->assertNull($deleted->reconfirm_required_at);
        $this->assertNull($past->refresh()->confirmed_at, 'Vergangene Tage bleiben unberuehrt.');

        $this->assertNull($booked->refresh()->portal_locked_at, 'Portal des gebuchten Datensatzes entsperrt.');
        $this->assertNull($twin->refresh()->portal_locked_at, 'Zwilling ebenfalls entsperrt (gruppenweit).');
    }

    public function test_declined_and_missing_rows_are_not_confirmed(): void
    {
        $event = RecDispoEvent::create(['einsatz_ref' => 'E-MC-2']);
        $e = $this->employee('RG2');
        $declined = $this->row($event->id, $e->id, 'DS-D', now()->addDay()->toDateString(), ['declined_at' => now()]);
        $missing = $this->row($event->id, $e->id, 'DS-M', now()->addDay()->toDateString(), ['missing_since' => now()]);

        $n = (new DispoManualConfirm(new DispoEmployeeGateway()))->confirm((int) $event->id, [$e->id], 42);

        $this->assertSame(0, $n);
        $this->assertNull($declined->refresh()->confirmed_at, 'Absage ist eine bewusste Entscheidung — bleibt.');
        $this->assertNull($missing->refresh()->confirmed_at, 'Verschwundene sind nicht mehr im Rennen.');
    }

    public function test_foreign_portal_locks_survive_the_unlock(): void
    {
        $event = RecDispoEvent::create(['einsatz_ref' => 'E-MC-3']);
        $e = $this->employee('RG3', 'HR: Konto eingefroren');
        $this->row($event->id, $e->id, 'DS-F', now()->addDay()->toDateString());

        (new DispoManualConfirm(new DispoEmployeeGateway()))->confirm((int) $event->id, [$e->id], null);

        $this->assertNotNull($e->refresh()->portal_locked_at, 'Nicht-Dispo-Sperre bleibt stehen.');
    }

    private static function runMigrations(): void
    {
        $own = dirname(__DIR__, 2);

        $files = [
            'database/migrations/2026_05_20_000001_create_rec_employees_table.php',
            'database/migrations/2026_05_22_000001_add_personnel_number_to_rec_employees.php',
            'database/migrations/2026_08_24_000004_add_portal_lock_to_rec_employees.php',
            'database/migrations/2026_08_12_000001_create_rec_dispo_events_table.php',
            'database/migrations/2026_08_12_000002_create_rec_dispo_assignments_table.php',
            'database/migrations/2026_08_14_000001_add_confirmation_fields_to_rec_dispo_assignments.php',
            'database/migrations/2026_08_20_000002_add_individual_note_to_rec_dispo_assignments.php',
            'database/migrations/2026_08_24_000002_add_escalation_fields_to_rec_dispo_assignments.php',
            'database/migrations/2026_08_28_000002_add_reconfirm_fields_to_rec_dispo_assignments.php',
            'database/migrations/2026_09_03_000002_add_note_timestamp_to_rec_dispo_assignments.php',
            'database/migrations/2026_09_04_000001_add_decline_fields_to_rec_dispo_assignments.php',
            'database/migrations/2026_09_04_000002_add_escalation_plan_to_rec_dispo_assignments.php',
            'database/migrations/2026_09_04_000003_add_reminder_sent_to_to_rec_dispo_assignments.php',
            'database/migrations/2026_09_04_000004_add_manual_confirm_to_rec_dispo_assignments.php',
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
