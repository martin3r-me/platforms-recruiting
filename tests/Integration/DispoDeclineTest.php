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
use Platform\Recruiting\Services\Zas\Dispo\DispoDecline;

/**
 * Absage tagesgenau (Kunde 18.09.): eine Absage darf nur die gewaehlten Tage
 * treffen — vorher nahm die Absage des Aufbautags die Person auch fuer die
 * Veranstaltungstage raus. Dazu die Ruecknahme inkl. Portalsperre-Logik.
 */
class DispoDeclineTest extends TestCase
{
    private const TEAM = 902;

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

    public function test_only_the_selected_days_are_declined(): void
    {
        $event = RecDispoEvent::create(['einsatz_ref' => 'E-DEC-1']);
        $e = $this->employee('RG1');
        $aufbau = $this->row($event->id, $e->id, 'DS-AUFBAU', now()->addDay()->toDateString());
        $vaTag1 = $this->row($event->id, $e->id, 'DS-VA1', now()->addDays(2)->toDateString());
        $vaTag2 = $this->row($event->id, $e->id, 'DS-VA2', now()->addDays(3)->toDateString());

        $n = (new DispoDecline(new DispoEmployeeGateway()))->apply(
            (int) $event->id, [(int) $aufbau->id], [$e->id], 'abgesagt', 'nur Aufbau', false, false, 42
        );

        $this->assertSame(1, $n);
        $this->assertNotNull($aufbau->refresh()->declined_at);
        $this->assertSame('nur Aufbau', $aufbau->declined_note);
        $this->assertNull($vaTag1->refresh()->declined_at, 'Veranstaltungstage bleiben unberuehrt (Befund Marco 18.09.).');
        $this->assertNull($vaTag2->refresh()->declined_at);
    }

    public function test_apply_locks_the_portal_group_wide_only_when_asked(): void
    {
        $event = RecDispoEvent::create(['einsatz_ref' => 'E-DEC-2']);
        $booked = $this->employee('RG2');
        $twin = $this->employee('MA2');
        $row = $this->row($event->id, $booked->id, 'DS-LOCK', now()->addDay()->toDateString());

        (new DispoDecline(new DispoEmployeeGateway()))->apply(
            (int) $event->id, [(int) $row->id], [$booked->id, $twin->id], 'krank', null, true, false, 42
        );

        $this->assertNotNull($booked->refresh()->portal_locked_at);
        $this->assertNotNull($twin->refresh()->portal_locked_at, 'Sperre gilt der Person, nicht dem Datensatz.');
        $this->assertTrue((bool) $row->refresh()->declined_portal_locked);
    }

    public function test_undo_reopens_the_day_and_keeps_the_send_history(): void
    {
        $event = RecDispoEvent::create(['einsatz_ref' => 'E-DEC-3']);
        $e = $this->employee('RG3');
        $row = $this->row($event->id, $e->id, 'DS-UNDO', now()->addDay()->toDateString(), [
            'reminder_sent_at' => now()->subDay(),
        ]);
        $service = new DispoDecline(new DispoEmployeeGateway());
        $service->apply((int) $event->id, [(int) $row->id], [$e->id], 'abgesagt', 'Kommentar', true, true, 42);

        $this->assertTrue($service->undo($row->refresh(), [$e->id]));

        $row->refresh();
        $this->assertNull($row->declined_at);
        $this->assertNull($row->declined_reason);
        $this->assertNull($row->declined_note);
        $this->assertNull($row->declined_hr_at, 'HR-Desk-Eintrag wird zurueckgezogen.');
        $this->assertNotNull($row->reminder_sent_at, 'Versand-Historie bleibt stehen (User-Entscheid 21.09.).');
        $this->assertNull($e->refresh()->portal_locked_at, 'Portalsperre faellt mit der Absage.');
    }

    public function test_undo_keeps_the_lock_while_another_decline_still_holds_it(): void
    {
        $event = RecDispoEvent::create(['einsatz_ref' => 'E-DEC-4']);
        $e = $this->employee('RG4');
        $tag1 = $this->row($event->id, $e->id, 'DS-L1', now()->addDay()->toDateString());
        $tag2 = $this->row($event->id, $e->id, 'DS-L2', now()->addDays(2)->toDateString());
        $service = new DispoDecline(new DispoEmployeeGateway());
        $service->apply((int) $event->id, [(int) $tag1->id, (int) $tag2->id], [$e->id], 'krank', null, true, false, 42);

        $service->undo($tag1->refresh(), [$e->id]);

        $this->assertNotNull($e->refresh()->portal_locked_at, 'Die zweite Absage haelt die Sperre.');
        $service->undo($tag2->refresh(), [$e->id]);
        $this->assertNull($e->refresh()->portal_locked_at);
    }

    public function test_undo_on_a_row_without_decline_does_nothing(): void
    {
        $event = RecDispoEvent::create(['einsatz_ref' => 'E-DEC-5']);
        $e = $this->employee('RG5');
        $row = $this->row($event->id, $e->id, 'DS-NOOP', now()->addDay()->toDateString());

        $this->assertFalse((new DispoDecline(new DispoEmployeeGateway()))->undo($row, [$e->id]));
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
