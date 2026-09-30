<?php

namespace Platform\Recruiting\Tests\Integration;

use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Models\RecDispoAssignment;
use Platform\Recruiting\Models\RecDispoEvent;
use Platform\Recruiting\Services\Zas\Dispo\DispoNoteCleanup;

/**
 * Hinweise aufraeumen (Kunde 25.09., Fall VA 1352): gruppiert nach exaktem
 * Wortlaut, damit eine Sammelaktion niemanden trifft, der etwas Eigenes
 * stehen hat. Echte Modelle auf SQLite via Capsule (kein Testbench).
 */
class DispoNoteCleanupTest extends TestCase
{
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

        $own = dirname(__DIR__, 2);
        foreach ([
            'database/migrations/2026_08_12_000001_create_rec_dispo_events_table.php',
            'database/migrations/2026_08_12_000002_create_rec_dispo_assignments_table.php',
            'database/migrations/2026_08_14_000001_add_confirmation_fields_to_rec_dispo_assignments.php',
            'database/migrations/2026_08_20_000002_add_individual_note_to_rec_dispo_assignments.php',
            'database/migrations/2026_09_03_000002_add_note_timestamp_to_rec_dispo_assignments.php',
        ] as $relative) {
            $path = $own . '/' . $relative;
            if (!file_exists($path)) {
                throw new \RuntimeException("Migration fehlt: {$path}");
            }
            (require $path)->up();
        }
    }

    public static function tearDownAfterClass(): void
    {
        Facade::clearResolvedInstances();
    }

    protected function setUp(): void
    {
        Capsule::table('rec_dispo_assignments')->delete();
        Capsule::table('rec_dispo_events')->delete();
    }

    /**
     * Nachbau des echten Falls: 2 Personen mit dem Sammeltext (eine davon an
     * zwei Tagen), 1 Person mit dem kuerzeren Vorlaeufer, 1 Person mit einem
     * eigenen Hinweis, 1 Person ohne Hinweis.
     *
     * @return array{0:int, 1:int}
     */
    private function seed(): array
    {
        $event = RecDispoEvent::create(['einsatz_ref' => 'RG-NOTE-1', 'name' => 'Aufraeum-VA']);
        $other = RecDispoEvent::create(['einsatz_ref' => 'RG-NOTE-2', 'name' => 'Andere-VA']);

        $lang = "Eigene Jacken mitnehmen\nRollingPin Kleiderordnung";
        $kurz = 'Eigene Jacken mitnehmen';

        RecDispoAssignment::create(['ds_ref' => 'N-A', 'rec_dispo_event_id' => $event->id, 'pnr_raw' => 'RG5', 'rec_employee_id' => 5, 'datum' => '2026-09-01', 'individual_note' => $lang, 'individual_note_updated_at' => '2026-09-25 15:24:39']);
        RecDispoAssignment::create(['ds_ref' => 'N-B', 'rec_dispo_event_id' => $event->id, 'pnr_raw' => 'RG5', 'rec_employee_id' => 5, 'datum' => '2026-09-02', 'individual_note' => $lang, 'individual_note_updated_at' => '2026-09-25 15:24:39']);
        RecDispoAssignment::create(['ds_ref' => 'N-C', 'rec_dispo_event_id' => $event->id, 'pnr_raw' => 'RG6', 'rec_employee_id' => 6, 'datum' => '2026-09-01', 'individual_note' => $lang, 'individual_note_updated_at' => '2026-09-25 15:24:39']);
        RecDispoAssignment::create(['ds_ref' => 'N-D', 'rec_dispo_event_id' => $event->id, 'pnr_raw' => 'RG7', 'rec_employee_id' => 7, 'datum' => '2026-09-01', 'individual_note' => $kurz, 'individual_note_updated_at' => '2026-09-25 13:05:26']);
        RecDispoAssignment::create(['ds_ref' => 'N-E', 'rec_dispo_event_id' => $event->id, 'pnr_raw' => 'RG8', 'rec_employee_id' => 8, 'datum' => '2026-09-01', 'individual_note' => 'Bitte 15 Min frueher', 'individual_note_updated_at' => '2026-09-24 09:12:00']);
        RecDispoAssignment::create(['ds_ref' => 'N-F', 'rec_dispo_event_id' => $event->id, 'pnr_raw' => 'RG9', 'rec_employee_id' => 9, 'datum' => '2026-09-01']);
        RecDispoAssignment::create(['ds_ref' => 'N-X', 'rec_dispo_event_id' => $other->id, 'pnr_raw' => 'RG5', 'rec_employee_id' => 5, 'datum' => '2026-09-01', 'individual_note' => $lang, 'individual_note_updated_at' => '2026-09-25 15:24:39']);

        return [$event->id, $other->id];
    }

    private function assignmentsOf(int $eventId)
    {
        return RecDispoAssignment::query()->where('rec_dispo_event_id', $eventId)->orderBy('id')->get();
    }

    public function test_gruppiert_nach_wortlaut_und_zaehlt_personen_nicht_zeilen(): void
    {
        [$eventId] = $this->seed();

        $variants = DispoNoteCleanup::variants($this->assignmentsOf($eventId));

        $this->assertCount(3, $variants, 'Drei Fassungen; die Zeile ohne Hinweis zaehlt nicht mit.');
        // Groesste zuerst: der Sammeltext haengt an ZWEI Personen (drei Zeilen).
        $this->assertSame(2, $variants[0]['count'], 'Mehrtages-Person zaehlt einmal.');
        $this->assertCount(3, $variants[0]['assignment_ids']);
        $this->assertSame('25.09.2026 15:24', $variants[0]['updated_at']);
        // Einzelfaelle unten.
        $this->assertSame(1, $variants[1]['count']);
        $this->assertSame(1, $variants[2]['count']);
    }

    public function test_gepaarte_person_zaehlt_einmal(): void
    {
        [$eventId] = $this->seed();
        // MA 6 ist derselbe Mensch wie MA 5 (person_key-Paarung RG/MA).
        $variants = DispoNoteCleanup::variants($this->assignmentsOf($eventId), [5 => 5, 6 => 5]);

        $this->assertSame(1, $variants[0]['count'], 'Zwei Datensaetze derselben Person sind eine Person.');
        $this->assertCount(3, $variants[0]['assignment_ids'], 'Geschrieben wird trotzdem auf alle drei Zeilen.');
    }

    public function test_bearbeiten_trifft_nur_die_fassung_und_erneuert_den_stempel(): void
    {
        [$eventId] = $this->seed();
        $variants = DispoNoteCleanup::variants($this->assignmentsOf($eventId));

        $changed = (new DispoNoteCleanup())->apply($eventId, $variants[0]['assignment_ids'], 'Nur die Jacken', true);

        $this->assertSame(3, $changed);
        $this->assertSame('Nur die Jacken', RecDispoAssignment::where('ds_ref', 'N-A')->value('individual_note'));
        $this->assertSame('Nur die Jacken', RecDispoAssignment::where('ds_ref', 'N-C')->value('individual_note'));
        $this->assertNotSame('2026-09-25 15:24:39', (string) RecDispoAssignment::where('ds_ref', 'N-A')->value('individual_note_updated_at'));
        // Andere Fassungen und der Einzelfall bleiben stehen.
        $this->assertSame('Eigene Jacken mitnehmen', RecDispoAssignment::where('ds_ref', 'N-D')->value('individual_note'));
        $this->assertSame('Bitte 15 Min frueher', RecDispoAssignment::where('ds_ref', 'N-E')->value('individual_note'));
    }

    public function test_stille_korrektur_laesst_den_stempel_stehen(): void
    {
        [$eventId] = $this->seed();
        $variants = DispoNoteCleanup::variants($this->assignmentsOf($eventId));

        (new DispoNoteCleanup())->apply($eventId, $variants[0]['assignment_ids'], 'Tippfehler weg', false);

        $this->assertSame('Tippfehler weg', RecDispoAssignment::where('ds_ref', 'N-A')->value('individual_note'));
        $this->assertStringStartsWith('2026-09-25 15:24:39', (string) RecDispoAssignment::where('ds_ref', 'N-A')->value('individual_note_updated_at'));
    }

    public function test_entfernen_leert_auch_den_stempel(): void
    {
        [$eventId] = $this->seed();
        $variants = DispoNoteCleanup::variants($this->assignmentsOf($eventId));

        $changed = (new DispoNoteCleanup())->apply($eventId, $variants[0]['assignment_ids'], null, true);

        $this->assertSame(3, $changed);
        $this->assertNull(RecDispoAssignment::where('ds_ref', 'N-A')->value('individual_note'));
        $this->assertNull(RecDispoAssignment::where('ds_ref', 'N-A')->value('individual_note_updated_at'),
            'Sonst leuchtet "neuer Hinweis" an einer leeren Stelle.');
    }

    public function test_fasst_keine_andere_veranstaltung_an(): void
    {
        [$eventId, $otherId] = $this->seed();
        $fremd = (int) RecDispoAssignment::where('ds_ref', 'N-X')->value('id');
        $variants = DispoNoteCleanup::variants($this->assignmentsOf($eventId));

        // Selbst wenn eine fremde id mitkaeme: die Event-Bedingung haelt.
        $changed = (new DispoNoteCleanup())->apply($eventId, array_merge($variants[0]['assignment_ids'], [$fremd]), null, true);

        $this->assertSame(3, $changed);
        $this->assertSame("Eigene Jacken mitnehmen\nRollingPin Kleiderordnung", RecDispoAssignment::where('ds_ref', 'N-X')->value('individual_note'));
    }

    public function test_zeigt_namen_der_geladenen_beziehung_sonst_die_pnr(): void
    {
        [$eventId] = $this->seed();
        $rows = $this->assignmentsOf($eventId);
        // Eine Zeile mit geladener Beziehung, der Rest ohne — wie im Betrieb,
        // wenn die PNr noch keinem Mitarbeiter zugeordnet ist.
        $rows->firstWhere('ds_ref', 'N-E')->setRelation('employee', (object) ['first_name' => 'Anton', 'last_name' => 'Höpfner']);

        $variants = DispoNoteCleanup::variants($rows);
        $einzel = collect($variants)->firstWhere('text', 'Bitte 15 Min frueher');

        $this->assertSame(['Anton Höpfner'], $einzel['persons']);
        $this->assertSame(['PNr RG7'], collect($variants)->firstWhere('text', 'Eigene Jacken mitnehmen')['persons']);
    }

    /**
     * Der Fall "an Tag 1 anderer Hinweis als an Tag 2": die Person steht in
     * BEIDEN Fassungen, jede Fassung nennt ihre Tage, und Aendern trifft nur
     * die Einbuchung des jeweiligen Tages.
     */
    public function test_verschiedene_hinweise_an_verschiedenen_tagen(): void
    {
        $event = RecDispoEvent::create(['einsatz_ref' => 'RG-NOTE-3', 'name' => 'Zweitage-VA']);
        RecDispoAssignment::create(['ds_ref' => 'T-1', 'rec_dispo_event_id' => $event->id, 'pnr_raw' => 'RG5', 'rec_employee_id' => 5, 'datum' => '2026-09-26', 'individual_note' => 'Tag 1: Aufbau, Werkzeug mitbringen']);
        RecDispoAssignment::create(['ds_ref' => 'T-2', 'rec_dispo_event_id' => $event->id, 'pnr_raw' => 'RG5', 'rec_employee_id' => 5, 'datum' => '2026-09-27', 'individual_note' => 'Tag 2: weisses Hemd']);
        RecDispoAssignment::create(['ds_ref' => 'T-3', 'rec_dispo_event_id' => $event->id, 'pnr_raw' => 'RG6', 'rec_employee_id' => 6, 'datum' => '2026-09-27', 'individual_note' => 'Tag 2: weisses Hemd']);

        $variants = DispoNoteCleanup::variants($this->assignmentsOf($event->id));

        $tag2 = collect($variants)->firstWhere('text', 'Tag 2: weisses Hemd');
        $tag1 = collect($variants)->firstWhere('text', 'Tag 1: Aufbau, Werkzeug mitbringen');

        $this->assertSame(['27.09.'], $tag2['days']);
        $this->assertSame(['26.09.'], $tag1['days']);
        $this->assertSame(2, $tag2['count']);
        $this->assertSame(1, $tag1['count']);
        // Beide Fassungen wissen, dass eine Person auch in der anderen steckt.
        $this->assertSame(1, $tag1['shared_persons']);
        $this->assertSame(1, $tag2['shared_persons']);
        // Insgesamt sind es ZWEI Personen, nicht drei (1 + 2 der Fassungen).
        $this->assertSame(2, DispoNoteCleanup::personTotal($this->assignmentsOf($event->id)));

        // Tag 2 aendern laesst Tag 1 derselben Person in Ruhe.
        (new DispoNoteCleanup())->apply($event->id, $tag2['assignment_ids'], 'Tag 2: schwarzes Hemd', true);
        $this->assertSame('Tag 1: Aufbau, Werkzeug mitbringen', RecDispoAssignment::where('ds_ref', 'T-1')->value('individual_note'));
        $this->assertSame('Tag 2: schwarzes Hemd', RecDispoAssignment::where('ds_ref', 'T-2')->value('individual_note'));
        $this->assertSame('Tag 2: schwarzes Hemd', RecDispoAssignment::where('ds_ref', 'T-3')->value('individual_note'));
    }

    /**
     * Fall VA 1352: ZAS nahm drei Zeilen zwischen den beiden Info-Laeufen raus.
     * Sie bleiben in der Liste — ihr Hinweis kaeme zurueck, sobald ZAS sie
     * wieder liefert (der Import raeumt missing_since dann ab) — stehen aber
     * ganz unten und sind als Altlast markiert.
     */
    public function test_nicht_mehr_eingebuchte_werden_markiert_und_nach_unten_sortiert(): void
    {
        $event = RecDispoEvent::create(['einsatz_ref' => 'RG-NOTE-4', 'name' => 'Raus-VA']);
        RecDispoAssignment::create(['ds_ref' => 'R-1', 'rec_dispo_event_id' => $event->id, 'pnr_raw' => 'RG5', 'rec_employee_id' => 5, 'datum' => '2026-09-28', 'individual_note' => 'Aktueller Sammeltext']);
        RecDispoAssignment::create(['ds_ref' => 'R-2', 'rec_dispo_event_id' => $event->id, 'pnr_raw' => 'RG6', 'rec_employee_id' => 6, 'datum' => '2026-09-28', 'individual_note' => 'Aktueller Sammeltext']);
        RecDispoAssignment::create(['ds_ref' => 'R-3', 'rec_dispo_event_id' => $event->id, 'pnr_raw' => 'RG7', 'rec_employee_id' => 7, 'datum' => '2026-09-28', 'individual_note' => 'Alte Fassung', 'missing_since' => '2026-09-25 14:50:19']);
        RecDispoAssignment::create(['ds_ref' => 'R-4', 'rec_dispo_event_id' => $event->id, 'pnr_raw' => 'RG8', 'rec_employee_id' => 8, 'datum' => '2026-09-29', 'individual_note' => 'Alte Fassung', 'deletion_marked_at' => '2026-09-25 16:00:00']);
        // Mischfall: eine Person ist an einem Tag noch drin, am anderen raus.
        RecDispoAssignment::create(['ds_ref' => 'R-5', 'rec_dispo_event_id' => $event->id, 'pnr_raw' => 'RG9', 'rec_employee_id' => 9, 'datum' => '2026-09-28', 'individual_note' => 'Aktueller Sammeltext', 'missing_since' => '2026-09-25 14:50:19']);

        $variants = DispoNoteCleanup::variants($this->assignmentsOf($event->id));

        $this->assertSame('Aktueller Sammeltext', $variants[0]['text'], 'Die lebende Fassung steht oben.');
        $this->assertSame(3, $variants[0]['count']);
        $this->assertSame(1, $variants[0]['inactive'], 'Eine der drei Personen ist raus.');

        $this->assertSame('Alte Fassung', $variants[1]['text'], 'Altlast ganz unten.');
        $this->assertSame(2, $variants[1]['count']);
        $this->assertSame(2, $variants[1]['inactive'], 'Verschwunden UND zur Loeschung gemeldet zaehlen beide.');
    }

    /** Der Schluessel haengt am Wortlaut, nicht an der Listenposition. */
    public function test_schluessel_ist_stabil_und_je_fassung_verschieden(): void
    {
        [$eventId] = $this->seed();
        $variants = DispoNoteCleanup::variants($this->assignmentsOf($eventId));
        $keys = array_column($variants, 'key');

        $this->assertCount(3, array_unique($keys));
        $this->assertSame($keys, array_column(DispoNoteCleanup::variants($this->assignmentsOf($eventId)), 'key'));
    }

    public function test_leere_auswahl_aendert_nichts(): void
    {
        [$eventId] = $this->seed();

        $this->assertSame(0, (new DispoNoteCleanup())->apply($eventId, [], null, true));
        $this->assertSame('Bitte 15 Min frueher', RecDispoAssignment::where('ds_ref', 'N-E')->value('individual_note'));
    }
}
