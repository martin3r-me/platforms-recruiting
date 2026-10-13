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
use Platform\Recruiting\Models\RecDispoJobOfferShift;
use Platform\Recruiting\Models\RecDispoJobRequest;
use Platform\Recruiting\Models\RecDispoJobShift;
use Platform\Recruiting\Services\Zas\Dispo\Jobs\DispoJobFortschritt;
use Platform\Recruiting\Services\Zas\Dispo\Jobs\DispoJobTestdaten;

/** Test-Anfrage „Jobs anbieten“ zum Durchklicken: anlegen und restlos aufraeumen. */
class DispoJobTestdatenTest extends TestCase
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
        $container->instance('config', new ConfigRepository(['recruiting' => ['zas' => []]]));
        $own = dirname(__DIR__, 2);
        foreach ([
            'database/migrations/2026_08_12_000001_create_rec_dispo_events_table.php',
            'database/migrations/2026_08_12_000002_create_rec_dispo_assignments_table.php',
            'database/migrations/2026_08_21_000001_add_filial_nr_to_rec_dispo_events.php',
            'database/migrations/2026_10_13_000001_create_rec_dispo_job_tables.php',
            'database/migrations/2026_10_13_000002_add_anlass_to_rec_dispo_job_requests.php',
            'database/migrations/2026_10_14_000001_add_bis_to_rec_dispo_job_shifts.php',
        ] as $rel) {
            (require $own . '/' . $rel)->up();
        }
    }

    public static function tearDownAfterClass(): void
    {
        Container::getInstance()->forgetInstance('config');
        Facade::clearResolvedInstances();
    }

    protected function setUp(): void
    {
        foreach (['rec_dispo_job_offer_shifts', 'rec_dispo_job_offers', 'rec_dispo_job_waves', 'rec_dispo_job_shifts', 'rec_dispo_job_requests', 'rec_dispo_assignments', 'rec_dispo_events'] as $t) {
            Capsule::table($t)->delete();
        }
    }

    public function test_legt_laufende_anfrage_mit_drei_schichten_und_zusagen_an(): void
    {
        $event = DispoJobTestdaten::testVeranstaltung(200);
        $r = DispoJobTestdaten::anlegen(6, $event, [11, 12, 13, 14], 3);

        $request = RecDispoJobRequest::find($r['request_id']);
        $this->assertTrue($request->laeuft(), 'Die Mitarbeiter-Seite zeigt nur laufende Anfragen.');
        $this->assertSame(6, (int) $request->team_id);
        $this->assertSame(200, $request->filial_nr);
        $this->assertSame(['schichten' => 3, 'angebote' => 4, 'zusagen' => 3], array_diff_key($r, ['request_id' => 1]));

        $shifts = RecDispoJobShift::where('request_id', $request->id)->orderBy('id')->get();
        $this->assertSame(['16:00', '18:00', '10:00'], $shifts->pluck('von')->all());
        $this->assertSame('02:00', $shifts[1]->bis, 'Nachtschicht zum Ansehen');
        $this->assertTrue($shifts->every(fn ($s) => $s->datum->toDateString() > now()->toDateString()), 'nur kuenftige Tage — sonst zeigt die Seite nichts');

        $angenommen = RecDispoJobOfferShift::whereNotNull('accepted_at')->count();
        $this->assertSame(4, $angenommen, 'Person 1: zwei Schichten, Person 2 und 3: je eine');
        $this->assertSame(1, RecDispoJobOfferShift::whereNotNull('zas_entered_at')->count(), 'Person 2 ist schon in ZAS eingetragen');

        $f = DispoJobFortschritt::berechnen(RecDispoJobShift::where('request_id', $request->id)
            ->withCount(['offerShifts as zugesagt' => fn ($q) => $q->whereNotNull('accepted_at'), 'offerShifts as eingetragen' => fn ($q) => $q->whereNotNull('zas_entered_at'), 'offerShifts as angekommen' => fn ($q) => $q->whereNotNull('zas_assignment_id')])->get());
        $this->assertSame(['gesucht' => 6, 'besetzt' => 4], ['gesucht' => $f['gesucht'], 'besetzt' => $f['besetzt']]);
    }

    public function test_aufraeumen_entfernt_nur_test_anfragen_und_die_test_va(): void
    {
        $event = DispoJobTestdaten::testVeranstaltung(200);
        DispoJobTestdaten::anlegen(6, $event, [11, 12], 2);
        DispoJobTestdaten::anlegen(6, $event, [13], 1);

        $echteVa = RecDispoEvent::create(['einsatz_ref' => 'ECHT-1', 'name' => 'Echt', 'filial_nr' => 200]);
        $echt = RecDispoJobRequest::create(['team_id' => 6, 'filial_nr' => 200, 'zeitraum_von' => '2099-01-01', 'zeitraum_bis' => '2099-01-02', 'modus' => 'sammeln', 'status' => 'laeuft']);
        $echt->shifts()->create(['rec_dispo_event_id' => $echteVa->id, 'datum' => '2099-01-01', 'von' => '', 'taetigkeit' => 'X', 'gesucht' => 1]);
        $echt->waves()->create(['nr' => 1, 'ma_bloecke' => [200], 'gruppen' => ['0'], 'nur_passende_taetigkeit' => true, 'summary' => ['total' => 1, 'sent' => 1]]);

        $r = DispoJobTestdaten::aufraeumen();

        $this->assertSame(['anfragen' => 2, 'veranstaltung' => true], $r);
        $this->assertSame([$echt->id], RecDispoJobRequest::pluck('id')->all(), 'echte Anfrage bleibt');
        $this->assertSame(1, RecDispoJobShift::count());
        $this->assertSame(0, Capsule::table('rec_dispo_job_offer_shifts')->count());
        $this->assertNull(RecDispoEvent::where('einsatz_ref', DispoJobTestdaten::TEST_REF)->first());
        $this->assertNotNull(RecDispoEvent::where('einsatz_ref', 'ECHT-1')->first());
    }

    public function test_test_va_mit_einbuchungen_bleibt_stehen(): void
    {
        $event = DispoJobTestdaten::testVeranstaltung(200);
        RecDispoAssignment::create(['ds_ref' => 'd1', 'rec_dispo_event_id' => $event->id, 'pnr_raw' => 'x', 'datum' => now()->addDays(2)->toDateString(), 'status_id' => 1]);

        $this->assertFalse(DispoJobTestdaten::aufraeumen()['veranstaltung']);
        $this->assertNotNull(RecDispoEvent::find($event->id));
    }

    public function test_ohne_mitarbeiter_wird_nichts_angelegt(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        DispoJobTestdaten::anlegen(6, DispoJobTestdaten::testVeranstaltung(200), []);
    }
}
