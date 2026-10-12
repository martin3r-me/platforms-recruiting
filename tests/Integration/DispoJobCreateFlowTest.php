<?php

namespace Platform\Recruiting\Tests\Integration;

use Carbon\Carbon;
use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Models\RecDispoJobRequest;
use Platform\Recruiting\Models\RecDispoJobShift;
use Platform\Recruiting\Models\RecDispoJobWave;
use Platform\Recruiting\Services\Zas\Dispo\Jobs\DispoJobAnfrageAnlegen;
use Platform\Recruiting\Services\Zas\Dispo\Jobs\DispoJobAnfragen;

/** Anlegen einer Anfrage (Schritt 5) und das Auto-Abschliessen der Uebersicht. Muster DispoJobTablesTest. */
class DispoJobCreateFlowTest extends TestCase
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
            'database/migrations/2026_10_13_000001_create_rec_dispo_job_tables.php',
        ] as $rel) {
            (require $own . '/' . $rel)->up();
        }
    }

    public static function tearDownAfterClass(): void
    {
        Carbon::setTestNow();
        Container::getInstance()->forgetInstance('config');
        Facade::clearResolvedInstances();
    }

    protected function setUp(): void
    {
        Carbon::setTestNow('2026-10-12 10:00:00');
        foreach (['rec_dispo_job_offer_shifts', 'rec_dispo_job_offers', 'rec_dispo_job_waves', 'rec_dispo_job_shifts', 'rec_dispo_job_requests'] as $t) {
            Capsule::table($t)->delete();
        }
    }

    private static function daten(array $o = []): array
    {
        return $o + [
            'filial_nr' => 200, 'von' => '2026-10-13', 'bis' => '2026-11-09', 'modus' => RecDispoJobRequest::MODUS_SAMMELN,
            'template_id' => 7, 'bloecke' => [200, null], 'gruppen' => ['0', '1-4'], 'nur_passend' => true, 'minuten' => 0,
            'schichten' => [
                ['event' => 5, 'datum' => '2026-10-24', 'von' => '17:00', 'taetigkeit' => 'Zapfer', 'gesucht' => 20],
                ['event' => 5, 'datum' => '2026-10-25', 'von' => '', 'taetigkeit' => 'Service', 'gesucht' => 3],
            ],
        ];
    }

    public function test_legt_anfrage_schichten_und_welle_1_an(): void
    {
        $request = DispoJobAnfrageAnlegen::anlegen(self::daten(), 3, 42);

        $fresh = RecDispoJobRequest::find($request->id);
        $this->assertSame(3, (int) $fresh->team_id);
        $this->assertSame(200, $fresh->filial_nr);
        $this->assertSame(RecDispoJobRequest::STATUS_ENTWURF, $fresh->status);
        $this->assertSame(42, (int) $fresh->created_by_user_id);
        $this->assertSame(7, $fresh->whatsapp_template_id);
        $this->assertSame('2026-10-13', $fresh->zeitraum_von->format('Y-m-d'));
        $this->assertSame('2026-11-09', $fresh->zeitraum_bis->format('Y-m-d'));

        $shifts = RecDispoJobShift::where('request_id', $request->id)->orderBy('datum')->get();
        $this->assertCount(2, $shifts);
        $this->assertSame(['17:00', 20, 'Zapfer'], [$shifts[0]->von, $shifts[0]->gesucht, $shifts[0]->taetigkeit]);
        $this->assertSame('', $shifts[1]->von, 'leerer Beginn als Leerstring, nie null');

        $waves = RecDispoJobWave::where('request_id', $request->id)->get();
        $this->assertCount(1, $waves);
        $this->assertSame(1, $waves[0]->nr);
        $this->assertSame([200, null], $waves[0]->ma_bloecke);
        $this->assertSame(['0', '1-4'], $waves[0]->gruppen);
        $this->assertTrue($waves[0]->nur_passende_taetigkeit);
        $this->assertNull($waves[0]->scheduled_for, 'jetzt senden');
        $this->assertNull($waves[0]->started_at);
        $this->assertNotEmpty($waves[0]->job_uuid, 'Fortschritts-Schluessel steht vor dem Start fest');
    }

    public function test_spaeter_senden_setzt_scheduled_for(): void
    {
        $request = DispoJobAnfrageAnlegen::anlegen(self::daten(['minuten' => 30]), 3, null);

        $wave = RecDispoJobWave::where('request_id', $request->id)->first();
        $this->assertSame('2026-10-12 10:30:00', $wave->scheduled_for->format('Y-m-d H:i:s'));
    }

    public function test_gesucht_0_wird_nicht_gespeichert_und_doppelte_schicht_zusammengefasst(): void
    {
        $daten = self::daten(['schichten' => [
            ['event' => 5, 'datum' => '2026-10-24', 'von' => '17:00', 'taetigkeit' => 'Zapfer', 'gesucht' => 2],
            ['event' => 5, 'datum' => '2026-10-24', 'von' => '18:00', 'taetigkeit' => 'Zapfer', 'gesucht' => 0],
            ['event' => 5, 'datum' => '2026-10-24', 'von' => '17:00', 'taetigkeit' => ' Zapfer ', 'gesucht' => 9],
            ['event' => 5, 'datum' => '2026-10-24', 'von' => null, 'taetigkeit' => 'Zapfer', 'gesucht' => 1],
        ]]);
        $request = DispoJobAnfrageAnlegen::anlegen($daten, 3, null);

        $rows = RecDispoJobShift::where('request_id', $request->id)->orderBy('von')->get()
            ->map(fn ($s) => [$s->von, $s->taetigkeit, $s->gesucht])->all();
        $this->assertSame([['', 'Zapfer', 1], ['17:00', 'Zapfer', 9]], $rows, 'letztes gesucht gewinnt');
    }

    public function test_ohne_gesuchte_schicht_wird_nichts_angelegt(): void
    {
        try {
            DispoJobAnfrageAnlegen::anlegen(self::daten(['schichten' => [
                ['event' => 5, 'datum' => '2026-10-24', 'von' => '17:00', 'taetigkeit' => 'Zapfer', 'gesucht' => 0],
            ]]), 3, null);
            $this->fail('erwartet: InvalidArgumentException');
        } catch (\InvalidArgumentException) {
        }
        $this->assertSame(0, RecDispoJobRequest::count());
    }

    public function test_abgelaufene_laufende_anfragen_werden_abgeschlossen(): void
    {
        $alt = DispoJobAnfrageAnlegen::anlegen(self::daten(['schichten' => [
            ['event' => 5, 'datum' => '2026-10-11', 'von' => '17:00', 'taetigkeit' => 'Zapfer', 'gesucht' => 1],
        ]]), 3, null);
        $alt->update(['status' => RecDispoJobRequest::STATUS_LAEUFT]);
        $heute = DispoJobAnfrageAnlegen::anlegen(self::daten(['schichten' => [
            ['event' => 5, 'datum' => '2026-10-10', 'von' => '17:00', 'taetigkeit' => 'Zapfer', 'gesucht' => 1],
            ['event' => 5, 'datum' => '2026-10-12', 'von' => '17:00', 'taetigkeit' => 'Zapfer', 'gesucht' => 1],
        ]]), 3, null);
        $heute->update(['status' => RecDispoJobRequest::STATUS_LAEUFT]);
        $fremd = DispoJobAnfrageAnlegen::anlegen(self::daten(['schichten' => [
            ['event' => 5, 'datum' => '2026-10-01', 'von' => '17:00', 'taetigkeit' => 'Zapfer', 'gesucht' => 1],
        ]]), 4, null);
        $fremd->update(['status' => RecDispoJobRequest::STATUS_LAEUFT]);
        $entwurf = DispoJobAnfrageAnlegen::anlegen(self::daten(['schichten' => [
            ['event' => 5, 'datum' => '2026-10-01', 'von' => '17:00', 'taetigkeit' => 'Zapfer', 'gesucht' => 1],
        ]]), 3, null);

        $this->assertSame(2, DispoJobAnfragen::abgelaufeneSchliessen(3));

        $alt->refresh();
        $this->assertSame(RecDispoJobRequest::STATUS_ABGESCHLOSSEN, $alt->status);
        $this->assertSame('2026-10-12 10:00:00', $alt->closed_at->format('Y-m-d H:i:s'));
        $this->assertSame(RecDispoJobRequest::STATUS_ABGESCHLOSSEN, $entwurf->refresh()->status, 'geplante Welle einer abgelaufenen Anfrage startet nie mehr');
        $this->assertSame(RecDispoJobRequest::STATUS_LAEUFT, $heute->refresh()->status, 'letzter Tag heute = noch offen');
        $this->assertNull($fremd->refresh()->closed_at, 'anderes Team bleibt unberuehrt');
        $this->assertSame(0, DispoJobAnfragen::abgelaufeneSchliessen(3), 'zweiter Lauf aendert nichts');
    }
}
