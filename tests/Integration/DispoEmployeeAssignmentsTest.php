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
use Platform\Recruiting\Services\Zas\Dispo\DispoEmployeeAssignments;

/**
 * Einsatz-Liste der Mitarbeiter-Akte (Kunde 26.09.): kommend/vergangen getrennt,
 * vergangene gedeckelt und juengste zuerst, Status wie auf der VA-Seite.
 */
class DispoEmployeeAssignmentsTest extends TestCase
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
            'database/migrations/2026_08_20_000001_add_filiale_to_rec_dispo_events.php',
            'database/migrations/2026_09_04_000001_add_decline_fields_to_rec_dispo_assignments.php',
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

    private function rows()
    {
        return RecDispoAssignment::query()->with('event')->orderBy('datum')->orderBy('von')->get();
    }

    public function test_trennt_kommende_von_vergangenen_und_deckelt_die_vergangenen(): void
    {
        $event = RecDispoEvent::create(['einsatz_ref' => 'MA19836', 'name' => 'Do&Co', 'filiale' => 'DUS & ES']);
        foreach (['2026-09-20', '2026-09-21', '2026-09-22', '2026-09-27', '2026-09-30'] as $i => $datum) {
            RecDispoAssignment::create([
                'ds_ref' => 'E-' . $i, 'rec_dispo_event_id' => $event->id, 'pnr_raw' => 'MA1',
                'rec_employee_id' => 1, 'datum' => $datum, 'von' => '15:00', 'bis' => '23:00',
            ]);
        }

        $out = DispoEmployeeAssignments::split($this->rows(), '2026-09-25', 2);

        $this->assertSame(5, $out['total']);
        $this->assertCount(2, $out['upcoming']);
        $this->assertSame(3, $out['past_total'], 'Der Zaehler nennt ALLE vergangenen.');
        $this->assertCount(2, $out['past'], 'Angezeigt wird nur der Deckel.');
        // Juengste zuerst.
        $this->assertSame('Di 22.09.2026', $out['past'][0]['datum']);
        $this->assertSame('Mo 21.09.2026', $out['past'][1]['datum']);
        // Kommende chronologisch.
        $this->assertSame('So 27.09.2026', $out['upcoming'][0]['datum']);
        $this->assertSame('Mi 30.09.2026', $out['upcoming'][1]['datum']);
    }

    public function test_status_folgt_der_va_seite(): void
    {
        $event = RecDispoEvent::create(['einsatz_ref' => 'MA1', 'name' => 'Test-VA']);
        $base = ['rec_dispo_event_id' => $event->id, 'pnr_raw' => 'MA1', 'rec_employee_id' => 1, 'datum' => '2026-09-30'];

        RecDispoAssignment::create($base + ['ds_ref' => 'S-1', 'von' => '01:00']);
        RecDispoAssignment::create($base + ['ds_ref' => 'S-2', 'von' => '02:00', 'reminder_sent_at' => now()]);
        RecDispoAssignment::create($base + ['ds_ref' => 'S-3', 'von' => '03:00', 'reminder_sent_at' => now(), 'confirmed_at' => now()]);
        RecDispoAssignment::create($base + ['ds_ref' => 'S-4', 'von' => '04:00', 'declined_at' => now()]);
        RecDispoAssignment::create($base + ['ds_ref' => 'S-5', 'von' => '05:00', 'confirmed_at' => now(), 'missing_since' => now()]);
        RecDispoAssignment::create($base + ['ds_ref' => 'S-6', 'von' => '06:00', 'deletion_marked_at' => now()]);

        $labels = array_column(DispoEmployeeAssignments::split($this->rows(), '2026-09-25')['upcoming'], 'status_label');

        $this->assertSame(
            ['offen', 'angeschrieben', 'bestätigt', 'abgesagt', 'verschwunden', 'zur Löschung gemeldet'],
            $labels
        );
    }

    /** Die Veranstaltung steht dran, damit man von der Akte aus hinspringen kann. */
    public function test_zeile_traegt_veranstaltung_und_zeit(): void
    {
        $event = RecDispoEvent::create(['einsatz_ref' => 'MA19836', 'name' => 'Do&Co – 150 Jahre Henkel', 'filiale' => 'DUS & ES']);
        RecDispoAssignment::create([
            'ds_ref' => 'Z-1', 'rec_dispo_event_id' => $event->id, 'pnr_raw' => 'MA1', 'rec_employee_id' => 1,
            'datum' => '2026-09-26', 'von' => '15:00', 'bis' => '00:00', 'taetigkeit' => 'Zapfer',
        ]);

        $row = DispoEmployeeAssignments::split($this->rows(), '2026-09-25')['upcoming'][0];

        $this->assertSame('Sa 26.09.2026', $row['datum']);
        $this->assertSame('15:00–00:00', $row['zeit']);
        $this->assertSame('Zapfer', $row['taetigkeit']);
        $this->assertSame('Do&Co – 150 Jahre Henkel', $row['event_name']);
        $this->assertSame('DUS & ES', $row['filiale']);
        $this->assertSame((int) $event->id, $row['event_id']);
    }

    public function test_ohne_einsaetze_leere_listen(): void
    {
        $out = DispoEmployeeAssignments::split($this->rows(), '2026-09-25');

        $this->assertSame(['upcoming' => [], 'past' => [], 'past_total' => 0, 'total' => 0], $out);
    }
}
