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
use Platform\Recruiting\Services\Zas\Dispo\DispoRowFilters;

/**
 * Spaltenfilter der VA-Tabelle (Kunde 05.10., „wie der Tabellenfilter in Excel").
 * Gemessen wird, was still schiefgehen kann: Zaehlung, Sortierung, und dass ein
 * leeres Array „alles" heisst und nicht „nichts".
 */
class DispoRowFiltersTest extends TestCase
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
        ] as $relative) {
            (require $own . '/' . $relative)->up();
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

    /** Nachbau der VA aus dem Screenshot: mehrere Tage, Zeiten und Taetigkeiten. */
    private function seed(): int
    {
        $event = RecDispoEvent::create(['einsatz_ref' => 'RG-FILTER-1', 'name' => 'Grossveranstaltung']);
        $rows = [
            ['F-1', '2026-10-02', '12:00', '16:00', 'Servicekräfte', null],
            ['F-2', '2026-10-02', '12:00', '17:00', 'Teamleitung',   null],
            ['F-3', '2026-10-02', '12:00', '15:45', 'Servicekräfte', null],
            ['F-4', '2026-10-03', '11:00', '15:30', 'Servicekräfte', null],
            ['F-5', '2026-10-03', '11:00', '15:30', 'Supervisor',    null],
            ['F-6', '2026-10-04', '09:00', '19:30', 'Supervisor',    null],
            // Verschwunden: darf weder zaehlen noch als Auswahlwert erscheinen.
            ['F-7', '2026-10-05', '08:00', '12:00', 'Spüler',        '2026-10-01 10:00:00'],
        ];
        foreach ($rows as [$ds, $datum, $von, $bis, $taet, $missing]) {
            RecDispoAssignment::create([
                'ds_ref' => $ds, 'rec_dispo_event_id' => $event->id, 'pnr_raw' => 'RG1',
                'rec_employee_id' => 1, 'datum' => $datum, 'von' => $von, 'bis' => $bis,
                'taetigkeit' => $taet, 'missing_since' => $missing,
            ]);
        }

        return (int) $event->id;
    }

    private function rows(int $eventId)
    {
        return RecDispoAssignment::query()->where('rec_dispo_event_id', $eventId)->orderBy('id')->get();
    }

    public function test_auswahlwerte_mit_anzahl_wie_in_excel(): void
    {
        $out = DispoRowFilters::options($this->rows($this->seed()));

        $this->assertSame(
            [['value' => '2026-10-02', 'label' => '02.10.', 'count' => 3],
             ['value' => '2026-10-03', 'label' => '03.10.', 'count' => 2],
             ['value' => '2026-10-04', 'label' => '04.10.', 'count' => 1]],
            $out['days'],
            'Nur Tage dieser VA, chronologisch, verschwundene nicht.'
        );

        $this->assertSame(
            [['value' => '09:00', 'label' => '09:00', 'count' => 1],
             ['value' => '11:00', 'label' => '11:00', 'count' => 2],
             ['value' => '12:00', 'label' => '12:00', 'count' => 3]],
            $out['times'],
            'Anfangszeiten aufsteigend.'
        );

        $this->assertSame(
            ['Servicekräfte', 'Supervisor', 'Teamleitung'],
            array_column($out['taetigkeiten'], 'value'),
            'Taetigkeiten alphabetisch; "Spüler" ist verschwunden und faellt raus.'
        );
        $this->assertSame([3, 2, 1], array_column($out['taetigkeiten'], 'count'));
    }

    /** Leeres Array heisst „keine Einschraenkung" — nicht „nichts anzeigen". */
    public function test_ohne_auswahl_bleibt_alles_stehen(): void
    {
        $rows = $this->rows($this->seed());

        $this->assertCount(7, DispoRowFilters::apply($rows, [], [], []));
    }

    public function test_taetigkeit_filtert_wie_der_kunde_es_meint(): void
    {
        $rows = $this->rows($this->seed());

        $out = DispoRowFilters::apply($rows, [], [], ['Servicekräfte']);

        $this->assertSame(['F-1', 'F-3', 'F-4'], $out->pluck('ds_ref')->values()->all());
    }

    public function test_anfangszeit_filtert_nur_ueber_von(): void
    {
        $rows = $this->rows($this->seed());

        $out = DispoRowFilters::apply($rows, [], ['11:00'], []);

        $this->assertSame(['F-4', 'F-5'], $out->pluck('ds_ref')->values()->all());
    }

    /** Mehrfachauswahl innerhalb einer Spalte ist ein ODER. */
    public function test_mehrere_werte_einer_spalte_sind_oder(): void
    {
        $rows = $this->rows($this->seed());

        $out = DispoRowFilters::apply($rows, ['2026-10-03', '2026-10-04'], [], []);

        $this->assertSame(['F-4', 'F-5', 'F-6'], $out->pluck('ds_ref')->values()->all());
    }

    /** Verschiedene Spalten sind ein UND. */
    public function test_verschiedene_spalten_sind_und(): void
    {
        $rows = $this->rows($this->seed());

        $out = DispoRowFilters::apply($rows, ['2026-10-02'], ['12:00'], ['Teamleitung']);

        $this->assertSame(['F-2'], $out->pluck('ds_ref')->values()->all());
    }

    /** Eine Auswahl ohne Treffer liefert leer — und nicht versehentlich alles. */
    public function test_auswahl_ohne_treffer_liefert_leer(): void
    {
        $rows = $this->rows($this->seed());

        $this->assertCount(0, DispoRowFilters::apply($rows, ['2026-10-02'], ['09:00'], []));
    }

    public function test_ohne_einbuchungen_keine_auswahlwerte(): void
    {
        $out = DispoRowFilters::options(collect());

        $this->assertSame(['days' => [], 'times' => [], 'taetigkeiten' => []], $out);
    }
}
