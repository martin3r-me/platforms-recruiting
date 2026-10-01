<?php

namespace Platform\Recruiting\Tests\Integration;

use Illuminate\Database\Capsule\Manager as Capsule;
use Platform\Recruiting\Livewire\Public\EmployeeAssignments;
use Platform\Recruiting\Models\RecDispoEventDress;
use Platform\Recruiting\Models\RecEmployee;
use Platform\Recruiting\Services\Zas\Dispo\DispoDressResolver;
use Platform\Recruiting\Support\DressPanels;

/**
 * Zusammenspiel Resolver + Panel-Regel, so wie eventGroups() es verdrahtet:
 * mit Paket verschwindet der ZAS-Text, ohne Paket bleibt die Seite wie heute.
 *
 * Fix-Runde 1 (Review-Befund): die ersten drei Tests bauen den Ablauf von
 * eventGroups() nur von Hand nach — ein echter Verdrahtungsfehler dort wuerde
 * unbemerkt bleiben. Die *_on_the_real_component-Tests unten schliessen die
 * Luecke: sie instanziieren EmployeeAssignments, mounten sie mit einem
 * echten Portal-Token (Muster DispoPortalConfirmTest) und pruefen das
 * Ergebnis von eventGroups() selbst.
 */
class DispoDressOnAssignmentPageTest extends DressTestCase
{
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        // Zusaetzlich zu den Waeschepaket-Migrationen der Basisklasse: alles,
        // was EmployeeAssignments::mount()/eventGroups() fuer eine echte
        // Einsatz-Seite braucht (Mitarbeiter, Anhaenge, Notiz-/Reconfirm-Felder).
        // Ohne CRM-Kontakt-Migration: config('recruiting.zas.inbound_team_id')
        // ist hier leer, DispoIdentityResolver bleibt dadurch fail-closed
        // (Gruppe = [employeeId]) und fasst nie crm_contact_links an.
        //
        // Fix-Runde 1 (Task 6): die beiden rec_dispo_attachments-Migrationen
        // laufen jetzt bereits in DressTestCase::setUpBeforeClass() (dort
        // noetig, weil die #[Computed]-Property event() die attachments-
        // Relation eager laedt) — hier NICHT mehr doppelt auffuehren, sonst
        // "table already exists".
        $own = dirname(__DIR__, 2);
        foreach ([
            'database/migrations/2026_05_20_000001_create_rec_employees_table.php',
            'database/migrations/2026_05_22_000001_add_personnel_number_to_rec_employees.php',
            'database/migrations/2026_08_24_000004_add_portal_lock_to_rec_employees.php',
            'database/migrations/2026_08_20_000002_add_individual_note_to_rec_dispo_assignments.php',
            'database/migrations/2026_08_24_000002_add_escalation_fields_to_rec_dispo_assignments.php',
            'database/migrations/2026_08_28_000002_add_reconfirm_fields_to_rec_dispo_assignments.php',
            'database/migrations/2026_09_03_000002_add_note_timestamp_to_rec_dispo_assignments.php',
            'database/migrations/2026_09_03_000003_add_portal_last_seen_at_to_rec_employees.php',
        ] as $relative) {
            $path = $own . '/' . $relative;
            if (!file_exists($path)) {
                throw new \RuntimeException("Migration fehlt: {$path}");
            }
            (require $path)->up();
        }
    }

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['rec_employees', 'rec_dispo_attachments'] as $t) {
            Capsule::table($t)->delete();
        }
    }

    public function test_package_replaces_the_zas_text_for_the_whole_event(): void
    {
        $event = $this->event(['dresscode' => 'Bitte folgende Kleidung: weisses Hemd', 'hinweis' => 'Ausweis mitnehmen']);
        $paket = $this->package('Logistik', 'Hoodie; Sicherheitsschuhe');
        RecDispoEventDress::create([
            'rec_dispo_event_id' => $event->id, 'taetigkeit' => RecDispoEventDress::ALL,
            'rec_dispo_dress_package_id' => $paket->id,
        ]);
        $tag1 = $this->assignment($event, ['datum' => '2026-10-01']);
        $tag2 = $this->assignment($event, ['datum' => '2026-10-02']);

        $panels = $this->panelsFor([$tag1, $tag2], $event->dresscode, $event->hinweis);

        $this->assertSame(
            ['heading' => DressPanels::HEADING_PACKAGE, 'text' => 'Hoodie; Sicherheitsschuhe'],
            $panels['group']
        );
        $this->assertSame('Ausweis mitnehmen', $panels['hinweis']);
    }

    public function test_service_and_logistics_on_different_days_split_the_panel(): void
    {
        $event = $this->event(['dresscode' => 'Bitte folgende Kleidung: weisses Hemd']);
        $service  = $this->package('Standard', 'weisses Hemd; schwarze Hose');
        $logistik = $this->package('Logistik', 'Hoodie; Sicherheitsschuhe');
        RecDispoEventDress::create([
            'rec_dispo_event_id' => $event->id, 'taetigkeit' => 'Service',
            'rec_dispo_dress_package_id' => $service->id,
        ]);
        RecDispoEventDress::create([
            'rec_dispo_event_id' => $event->id, 'taetigkeit' => 'Logistik',
            'rec_dispo_dress_package_id' => $logistik->id,
        ]);
        $tag1 = $this->assignment($event, ['datum' => '2026-10-01', 'taetigkeit' => 'Service']);
        $tag2 = $this->assignment($event, ['datum' => '2026-10-02', 'taetigkeit' => 'Logistik']);

        $panels = $this->panelsFor([$tag1, $tag2], $event->dresscode, $event->hinweis);

        $this->assertNull($panels['group']);
        $this->assertSame('weisses Hemd; schwarze Hose', $panels['perDay'][$tag1->id]['text']);
        $this->assertSame('Hoodie; Sicherheitsschuhe', $panels['perDay'][$tag2->id]['text']);
    }

    public function test_without_any_package_nothing_changes(): void
    {
        $event = $this->event(['dresscode' => 'Bitte folgende Kleidung: weisses Hemd', 'hinweis' => 'wird nicht gezeigt']);
        $tag = $this->assignment($event);

        $panels = $this->panelsFor([$tag], $event->dresscode, $event->hinweis);

        $this->assertSame(
            ['heading' => DressPanels::HEADING_ZAS, 'text' => 'Bitte folgende Kleidung: weisses Hemd'],
            $panels['group']
        );
        $this->assertNull($panels['hinweis']);
    }

    public function test_package_shows_up_in_eventgroups_of_the_real_component(): void
    {
        $employee = $this->employeeWithToken('tok-dress-1');
        $event = $this->event(['dresscode' => 'Bitte folgende Kleidung: weisses Hemd', 'hinweis' => 'Ausweis mitnehmen']);
        $paket = $this->package('Logistik', 'Hoodie; Sicherheitsschuhe');
        RecDispoEventDress::create([
            'rec_dispo_event_id' => $event->id, 'taetigkeit' => RecDispoEventDress::ALL,
            'rec_dispo_dress_package_id' => $paket->id,
        ]);
        $this->assignment($event, [
            'rec_employee_id'  => $employee->id,
            'datum'            => now()->addDay()->toDateString(),
            'reminder_sent_at' => now()->subHour(),
        ]);

        $component = new EmployeeAssignments();
        $component->mount('tok-dress-1');
        $groups = $component->eventGroups();

        $this->assertCount(1, $groups);
        $this->assertSame(
            ['heading' => DressPanels::HEADING_PACKAGE, 'text' => 'Hoodie; Sicherheitsschuhe'],
            $groups[0]['dress_group'],
            'Das Paket muss den ZAS-Text in eventGroups() ersetzen.'
        );
        $this->assertSame('Ausweis mitnehmen', $groups[0]['dress_hinweis']);
    }

    public function test_without_package_eventgroups_keeps_showing_the_zas_text(): void
    {
        $employee = $this->employeeWithToken('tok-dress-2');
        $event = $this->event(['dresscode' => 'Bitte folgende Kleidung: weisses Hemd', 'hinweis' => 'wird nicht gezeigt']);
        $this->assignment($event, [
            'rec_employee_id'  => $employee->id,
            'datum'            => now()->addDay()->toDateString(),
            'reminder_sent_at' => now()->subHour(),
        ]);

        $component = new EmployeeAssignments();
        $component->mount('tok-dress-2');
        $groups = $component->eventGroups();

        $this->assertCount(1, $groups);
        $this->assertSame(
            ['heading' => DressPanels::HEADING_ZAS, 'text' => 'Bitte folgende Kleidung: weisses Hemd'],
            $groups[0]['dress_group'],
            'Ohne Paket bleibt der ZAS-Text unveraendert (Rollout-Eigenschaft VA fuer VA).'
        );
        $this->assertNull($groups[0]['dress_hinweis']);
    }

    /**
     * Fix-Runde 3, Befund 3: das Einfrieren haelt den TEXT fest, nicht nur die
     * Referenz. Die Pflegemaske schreibt items_text in den bestehenden
     * Paket-Datensatz — ohne Kopie aenderte sich rueckwirkend, was der
     * Mitarbeiter bereits bestaetigt hat.
     */
    public function test_frozen_text_survives_a_later_edit_of_the_package(): void
    {
        $employee = $this->employeeWithToken('tok-dress-3');
        $event = $this->event(['dresscode' => 'Bitte folgende Kleidung: weisses Hemd']);
        $paket = $this->package('Logistik', 'Hoodie; Sicherheitsschuhe');
        RecDispoEventDress::create([
            'rec_dispo_event_id' => $event->id, 'taetigkeit' => RecDispoEventDress::ALL,
            'rec_dispo_dress_package_id' => $paket->id,
        ]);
        $assignment = $this->assignment($event, [
            'rec_employee_id'  => $employee->id,
            'datum'            => now()->addDay()->toDateString(),
            'reminder_sent_at' => now()->subHour(),
        ]);

        // Versandzeitpunkt: Paket festschreiben.
        (new DispoDressResolver())->freeze([$assignment->id]);
        $this->assertSame('Hoodie; Sicherheitsschuhe', $assignment->fresh()->dress_items_text,
            'freeze() muss die Textkopie mitstempeln, nicht nur die Referenz.');

        // Drei Wochen spaeter aendert jemand den Inhalt von "Logistik".
        $paket->update(['items_text' => 'Komplett andere Kleidung']);

        $component = new EmployeeAssignments();
        $component->mount('tok-dress-3');
        $groups = $component->eventGroups();

        $this->assertSame(
            ['heading' => DressPanels::HEADING_PACKAGE, 'text' => 'Hoodie; Sicherheitsschuhe'],
            $groups[0]['dress_group'],
            'Die festgeschriebene Einbuchung muss weiter den Text vom Versandzeitpunkt zeigen.'
        );
    }

    private function employeeWithToken(string $token): RecEmployee
    {
        return RecEmployee::create([
            'team_id' => self::TEAM, 'first_name' => 'Erika', 'last_name' => 'Muster',
            'personnel_number' => 'MA-' . $token, 'portal_token' => $token, 'is_active' => true,
        ]);
    }

    /** @param list<\Platform\Recruiting\Models\RecDispoAssignment> $assignments */
    private function panelsFor(array $assignments, ?string $zas, ?string $hinweis): array
    {
        $packages = (new DispoDressResolver())->forAssignments($assignments);
        $days = [];
        foreach ($assignments as $a) {
            $days[$a->id] = $packages[$a->id]?->items_text;
        }

        return DressPanels::build($days, $zas, $hinweis);
    }
}
