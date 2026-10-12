<?php

namespace Platform\Recruiting\Tests\Integration;

use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Models\RecDispoJobOffer;
use Platform\Recruiting\Models\RecDispoJobRequest;
use Platform\Recruiting\Models\RecDispoJobShift;
use Platform\Recruiting\Models\RecDispoJobWave;

/** Tabellen + Relationen der Jobangebote. Muster DispoEmployeeAssignmentsTest (Capsule/SQLite). */
class DispoJobTablesTest extends TestCase
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
        Container::getInstance()->forgetInstance('config');
        Facade::clearResolvedInstances();
    }

    public function test_anfrage_schicht_welle_angebot_haengen_zusammen(): void
    {
        $request = RecDispoJobRequest::create(['team_id' => 3, 'filial_nr' => 200, 'zeitraum_von' => '2026-10-20', 'zeitraum_bis' => '2026-11-17',
            'modus' => RecDispoJobRequest::MODUS_SAMMELN, 'status' => RecDispoJobRequest::STATUS_ENTWURF, 'created_by_user_id' => 1]);
        $this->assertNotEmpty($request->uuid);
        $shift = $request->shifts()->create(['rec_dispo_event_id' => 1, 'datum' => '2026-10-24', 'von' => '17:00', 'taetigkeit' => 'Zapfer', 'gesucht' => 20]);
        $wave = $request->waves()->create(['nr' => 1, 'ma_bloecke' => [200], 'gruppen' => ['0'], 'nur_passende_taetigkeit' => true]);
        $offer = $wave->offers()->create(['request_id' => $request->id, 'rec_employee_id' => 5, 'anchor_employee_id' => 5]);
        $offer->shifts()->create(['shift_id' => $shift->id]);

        $this->assertSame([200], RecDispoJobWave::find($wave->id)->ma_bloecke);
        $this->assertSame(1, RecDispoJobShift::find($shift->id)->offerShifts()->count());
        $this->assertSame($request->id, RecDispoJobOffer::find($offer->id)->request->id);
        $this->assertSame(1, $request->offers()->count());
    }
}
