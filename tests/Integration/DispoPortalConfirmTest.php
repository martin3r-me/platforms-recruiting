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
use Platform\Recruiting\Models\RecDispoAttachment;
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
        Capsule::table('rec_dispo_attachments')->delete();
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


    public function test_declined_assignments_are_hidden_and_not_confirmable(): void
    {
        // Absage-Flow (Kunde 04.09.): die Dispo hat abgemeldet — der MA sieht den
        // Einsatz nicht mehr und kann ihn auch aus einem alten Tab nicht bestaetigen.
        $employee = $this->employee('tok-declined-1');
        $event = RecDispoEvent::create(['einsatz_ref' => 'E-DECL-1']);
        $assignment = RecDispoAssignment::create([
            'ds_ref' => 'DS-DECL-1', 'rec_dispo_event_id' => $event->id, 'pnr_raw' => 'MA-tok-declined-1',
            'rec_employee_id' => $employee->id, 'datum' => now()->addDay()->toDateString(),
            'von' => '08:00', 'bis' => '16:00',
            'status_id' => RecDispoAssignment::STATUS_AUFTRAG, 'reminder_sent_at' => now()->subHour(),
            'declined_at' => now(), 'declined_reason' => 'krank',
        ]);

        $component = $this->component('tok-declined-1');
        $this->assertSame([], $component->eventGroups(), 'Abgemeldeter Einsatz ist unsichtbar.');

        $component->confirm((int) $event->id);
        $row = Capsule::table('rec_dispo_assignments')->where('id', $assignment->id)->first();
        $this->assertNull($row->confirmed_at, 'Abgemeldetes ist nicht bestaetigbar (auch nicht aus altem Tab).');
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

    // --- Kunde 28.10.: Anhaenge waren um 00:00 Uhr weg, Check-out war danach ---

    protected function tearDown(): void
    {
        \Carbon\Carbon::setTestNow(null);
        Container::getInstance()->forgetInstance('url');
        parent::tearDown();
    }

    private function routeStub(): void
    {
        Container::getInstance()->instance('url', new class {
            public function route($name, $parameters = [], $absolute = true): string
            {
                return '/' . $name . '/' . implode('/', (array) $parameters);
            }
        });
    }

    private function attachment(RecDispoEvent $event, RecEmployee $employee, string $name): RecDispoAttachment
    {
        return RecDispoAttachment::create([
            'uuid' => 'att-' . $name, 'rec_dispo_event_id' => $event->id, 'rec_employee_id' => $employee->id,
            'disk' => 'local', 'stored_path' => 'zas-dispo-attachments/' . $event->id . '/' . $name,
            'original_filename' => $name, 'mime_type' => 'application/pdf', 'size_bytes' => 3,
        ]);
    }

    public function test_schicht_ueber_mitternacht_bleibt_nach_null_uhr_oben_mit_anhang_und_ist_bestaetigbar(): void
    {
        \Carbon\Carbon::setTestNow(\Carbon\Carbon::parse('2026-10-28 01:00:00'));
        $this->routeStub();

        $employee = $this->employee('tok-nacht-1');
        $event = RecDispoEvent::create(['einsatz_ref' => 'E-NACHT-1', 'name' => 'Gala']);
        $assignment = RecDispoAssignment::create([
            'ds_ref' => 'DS-NACHT-1', 'rec_dispo_event_id' => $event->id, 'pnr_raw' => 'MA-tok-nacht-1',
            'rec_employee_id' => $employee->id, 'datum' => '2026-10-27', 'von' => '18:00', 'bis' => '02:00',
            'status_id' => RecDispoAssignment::STATUS_AUFTRAG, 'reminder_sent_at' => now()->subDay(),
        ]);
        $this->attachment($event, $employee, 'einteilung.pdf');

        $component = $this->component('tok-nacht-1');
        $groups = $component->eventGroups();
        $this->assertCount(1, $groups, 'Die laufende Nachtschicht steht nach Mitternacht noch oben.');
        $this->assertSame(['einteilung.pdf'], array_column($groups[0]['attachments'], 'name'));
        $this->assertStringContainsString('att-einteilung.pdf', $groups[0]['attachments'][0]['url']);
        $this->assertSame([], $component->pastEventGroups(), 'und nicht gleichzeitig unter den vergangenen');

        $component->confirm((int) $event->id);
        $row = Capsule::table('rec_dispo_assignments')->where('id', $assignment->id)->first();
        $this->assertNotNull($row->confirmed_at, 'Was oben steht, ist bestaetigbar.');

        // Mutationsprobe: nach Ende + Nachlauf (02:00 + 6 Std) ist sie vergangen.
        \Carbon\Carbon::setTestNow(\Carbon\Carbon::parse('2026-10-28 08:00:00'));
        $component = $this->component('tok-nacht-1');
        $this->assertSame([], $component->eventGroups());
        $this->assertCount(1, $component->pastEventGroups());
    }

    public function test_vergangener_einsatz_zeigt_seine_anhaenge_weiter(): void
    {
        \Carbon\Carbon::setTestNow(\Carbon\Carbon::parse('2026-10-28 09:00:00'));
        $this->routeStub();

        $employee = $this->employee('tok-past-1');
        $event = RecDispoEvent::create(['einsatz_ref' => 'E-PAST-1', 'name' => 'Messe']);
        $assignment = RecDispoAssignment::create([
            'ds_ref' => 'DS-PAST-1', 'rec_dispo_event_id' => $event->id, 'pnr_raw' => 'MA-tok-past-1',
            'rec_employee_id' => $employee->id, 'datum' => '2026-10-25', 'von' => '10:00', 'bis' => '16:00',
            'status_id' => RecDispoAssignment::STATUS_AUFTRAG, 'reminder_sent_at' => now()->subDays(4),
        ]);
        $this->attachment($event, $employee, 'bericht.pdf');
        $this->attachment($event, $employee, 'zugang.png');

        $component = $this->component('tok-past-1');
        $this->assertSame([], $component->eventGroups());
        $past = $component->pastEventGroups();
        $this->assertCount(1, $past);
        $this->assertSame(['bericht.pdf', 'zugang.png'], array_column($past[0]['attachments'], 'name'), 'Anhaenge bleiben bei vergangenen Einsaetzen abrufbar.');
        $this->assertStringContainsString('recruiting.public.employee-assignments.attachment', $past[0]['attachments'][0]['url']);

        // Vergangenes bleibt nicht bestaetigbar.
        $component->confirm((int) $event->id);
        $this->assertNull(Capsule::table('rec_dispo_assignments')->where('id', $assignment->id)->value('confirmed_at'));
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
            [$own, 'database/migrations/2026_09_04_000001_add_decline_fields_to_rec_dispo_assignments.php'],
            // Kunde 28.10.: eventGroups() laeuft jetzt mit echten Zeilen durch → Waeschepakete.
            [$own, 'database/migrations/2026_09_30_000001_create_rec_dispo_dress_packages_table.php'],
            [$own, 'database/migrations/2026_09_30_000002_create_rec_dispo_event_dress_table.php'],
            [$own, 'database/migrations/2026_09_30_000003_add_dress_fields_to_dispo_tables.php'],
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
