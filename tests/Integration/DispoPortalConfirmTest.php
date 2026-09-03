<?php

namespace Platform\Recruiting\Tests\Integration;

use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use Platform\Crm\Models\CrmContactLink;
use Platform\Recruiting\Livewire\Public\EmployeeAssignments;
use Platform\Recruiting\Models\RecDispoAssignment;
use Platform\Recruiting\Models\RecDispoEvent;
use Platform\Recruiting\Models\RecEmployee;

/**
 * Bestaetigung auf der oeffentlichen Einsatz-Seite (Runde 4, #2):
 * confirm() muss NEBEN confirmed_at auch den Zeiten-Schnappschuss
 * (confirmed_datum/von/bis) setzen und die Rebestaetigungs-Marker
 * (reconfirm_required_at/reconfirm_previous) loeschen — sonst zeigt die Seite
 * nach dem Bestaetigen weiter "Neue Zeiten" und die naechste Lieferung
 * vergleicht gegen einen veralteten Stand.
 *
 * Muster DispoPortalLockTest: echte Migrationen, kein Testbench. Die
 * Komponente wird direkt instanziiert und mit dem Portal-Token gemountet;
 * gepruefte Wirkung ist der Datenzustand (kein Livewire-Test-Harness in
 * dieser Suite, siehe reference_phpunit_runner).
 */
class DispoPortalConfirmTest extends TestCase
{
    private const TEAM = 611;

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

        $container->instance('config', new ConfigRepository([
            'recruiting' => ['zas' => ['inbound_team_id' => self::TEAM]],
        ]));

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

    private function employee(string $token): RecEmployee
    {
        return RecEmployee::create([
            'team_id' => self::TEAM, 'first_name' => 'Erika', 'last_name' => 'Muster',
            'personnel_number' => 'MA-' . $token, 'portal_token' => $token, 'is_active' => true,
        ]);
    }

    private function component(string $token): EmployeeAssignments
    {
        $component = new EmployeeAssignments();
        $component->mount($token);

        return $component;
    }

    public function test_confirm_snapshots_the_times_and_clears_the_reconfirm_markers(): void
    {
        $employee = $this->employee('tok-confirm-1');
        $event = RecDispoEvent::create(['einsatz_ref' => 'E-CONF-1']);

        $datum = now()->addDay()->toDateString();
        $assignment = RecDispoAssignment::create([
            'ds_ref' => 'DS-CONF-1', 'rec_dispo_event_id' => $event->id, 'pnr_raw' => 'MA-tok-confirm-1',
            'rec_employee_id' => $employee->id, 'datum' => $datum, 'von' => '08:00', 'bis' => '16:00',
            'status_id' => RecDispoAssignment::STATUS_AUFTRAG, 'reminder_sent_at' => now()->subHour(),
        ]);

        // Vorher: Rebestaetigung angefordert (Zeitaenderung aus dem Import).
        Capsule::table('rec_dispo_assignments')->where('id', $assignment->id)->update([
            'reconfirm_required_at' => now()->subHour(),
            'reconfirm_previous'    => json_encode(['von' => '07:00', 'bis' => '15:00']),
        ]);

        $this->component('tok-confirm-1')->confirm((int) $event->id);

        $row = Capsule::table('rec_dispo_assignments')->where('id', $assignment->id)->first();

        $this->assertNotNull($row->confirmed_at);
        $this->assertSame($datum, substr((string) $row->confirmed_datum, 0, 10), 'confirmed_datum = datum');
        $this->assertSame('08:00', (string) $row->confirmed_von);
        $this->assertSame('16:00', (string) $row->confirmed_bis);
        $this->assertNull($row->reconfirm_required_at, 'Marker "Zeit geaendert" muss verschwinden.');
        $this->assertNull($row->reconfirm_previous);
    }


    public function test_confirm_ignores_assignments_that_were_never_sent(): void
    {
        $employee = $this->employee('tok-confirm-3');
        $event = RecDispoEvent::create(['einsatz_ref' => 'E-CONF-3']);
        $assignment = RecDispoAssignment::create([
            'ds_ref' => 'DS-CONF-3', 'rec_dispo_event_id' => $event->id, 'pnr_raw' => 'MA-tok-confirm-3',
            'rec_employee_id' => $employee->id, 'datum' => now()->addDay()->toDateString(),
            'von' => '08:00', 'bis' => '16:00', 'status_id' => RecDispoAssignment::STATUS_AUFTRAG,
        ]);

        $component = $this->component('tok-confirm-3');
        $component->confirm((int) $event->id);

        $row = Capsule::table('rec_dispo_assignments')->where('id', $assignment->id)->first();
        $this->assertNull($row->confirmed_at, 'Nie angeschriebene Einbuchung darf nicht bestaetigbar sein (Kunde 01.09.).');
        $this->assertSame([], $component->eventGroups(), 'Nie Angeschriebenes erscheint nicht auf der Einsatz-Seite.');
    }

    public function test_confirm_is_blocked_while_the_portal_is_locked(): void
    {
        $employee = $this->employee('tok-confirm-2');
        $employee->portal_locked_at = now();
        $employee->portal_locked_reason = 'Dispo: nicht bestaetigt';
        $employee->save();

        $event = RecDispoEvent::create(['einsatz_ref' => 'E-CONF-2']);
        $assignment = RecDispoAssignment::create([
            'ds_ref' => 'DS-CONF-2', 'rec_dispo_event_id' => $event->id, 'pnr_raw' => 'MA-tok-confirm-2',
            'rec_employee_id' => $employee->id, 'datum' => now()->addDay()->toDateString(),
            'von' => '08:00', 'bis' => '16:00', 'status_id' => RecDispoAssignment::STATUS_AUFTRAG,
        ]);

        $this->component('tok-confirm-2')->confirm((int) $event->id);

        $row = Capsule::table('rec_dispo_assignments')->where('id', $assignment->id)->first();
        $this->assertNull($row->confirmed_at);
        $this->assertNull($row->confirmed_datum);
    }

    private static function runMigrations(): void
    {
        $own = dirname(__DIR__, 2);
        // mount() loest die Dispo-Identitaetsgruppe auf (CRM-Kontakt-Links).
        $crm = self::packageRootOf(CrmContactLink::class);

        $files = [
            [$crm, 'database/migrations/2024_01_01_000020_create_crm_contact_links_table.php'],
            [$own, 'database/migrations/2026_05_20_000001_create_rec_employees_table.php'],
            [$own, 'database/migrations/2026_05_22_000001_add_personnel_number_to_rec_employees.php'],
            [$own, 'database/migrations/2026_08_24_000004_add_portal_lock_to_rec_employees.php'],
            [$own, 'database/migrations/2026_08_12_000001_create_rec_dispo_events_table.php'],
            [$own, 'database/migrations/2026_08_12_000002_create_rec_dispo_assignments_table.php'],
            [$own, 'database/migrations/2026_08_14_000001_add_confirmation_fields_to_rec_dispo_assignments.php'],
            [$own, 'database/migrations/2026_08_20_000002_add_individual_note_to_rec_dispo_assignments.php'],
            [$own, 'database/migrations/2026_08_24_000002_add_escalation_fields_to_rec_dispo_assignments.php'],
            [$own, 'database/migrations/2026_08_27_000001_create_rec_dispo_attachments_table.php'],
            [$own, 'database/migrations/2026_08_28_000002_add_reconfirm_fields_to_rec_dispo_assignments.php'],
            [$own, 'database/migrations/2026_09_03_000002_add_note_timestamp_to_rec_dispo_assignments.php'],
            [$own, 'database/migrations/2026_09_03_000003_add_portal_last_seen_at_to_rec_employees.php'],
            [$own, 'database/migrations/2026_09_03_000001_allow_multiple_dispo_attachments.php'],
        ];

        foreach ($files as [$root, $relative]) {
            $path = $root . '/' . $relative;
            if (!file_exists($path)) {
                throw new \RuntimeException("Migration fehlt: {$path}");
            }
            (require $path)->up();
        }
    }

    private static function packageRootOf(string $class): string
    {
        $file = (new \ReflectionClass($class))->getFileName();
        $dir = dirname((string) $file);
        while ($dir !== '/' && !file_exists($dir . '/composer.json')) {
            $dir = dirname($dir);
        }

        return $dir;
    }
}
