<?php

namespace Platform\Recruiting\Tests\Integration;

use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Models\RecDispoJobOfferShift;
use Platform\Recruiting\Models\RecDispoJobRequest;
use Platform\Recruiting\Services\Zas\Dispo\Jobs\JobOffersZusage;

/** Zusage-Logik der Mitarbeiter-Seite /jobs/{token} (Capsule/SQLite). */
class JobOffersPageTest extends TestCase
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

    /** @return array{0: RecDispoJobRequest, 1: \Platform\Recruiting\Models\RecDispoJobShift, 2: int, 3: int} */
    private function aufbau(string $modus, int $gesucht, string $status = RecDispoJobRequest::STATUS_LAEUFT): array
    {
        static $event = 100;
        $event++;
        $request = RecDispoJobRequest::create(['team_id' => 3, 'filial_nr' => 200, 'zeitraum_von' => '2026-10-20', 'zeitraum_bis' => '2099-11-17',
            'modus' => $modus, 'status' => $status, 'created_by_user_id' => 1]);
        $shift = $request->shifts()->create(['rec_dispo_event_id' => $event, 'datum' => now()->addDays(5)->toDateString(), 'von' => '17:00', 'taetigkeit' => 'Zapfer', 'gesucht' => $gesucht]);
        $wave = $request->waves()->create(['nr' => 1, 'ma_bloecke' => [200], 'gruppen' => ['0'], 'nur_passende_taetigkeit' => true]);
        $osIds = [];
        foreach ([5, 6] as $emp) {
            $offer = $wave->offers()->create(['request_id' => $request->id, 'rec_employee_id' => $emp, 'anchor_employee_id' => $emp]);
            $osIds[$emp] = $offer->shifts()->create(['shift_id' => $shift->id])->id;
        }

        return [$request, $shift, $osIds[5], $osIds[6]];
    }

    public function test_zuerst_erste_zusage_schliesst_zweite_ist_voll(): void
    {
        [, $shift, $os5, $os6] = $this->aufbau(RecDispoJobRequest::MODUS_ZUERST, 1);

        $this->assertSame('ok', JobOffersZusage::ausfuehren($os5, [5]));
        $this->assertNotNull(RecDispoJobOfferShift::find($os5)->accepted_at);
        $this->assertNotNull($shift->fresh()->closed_at);

        $this->assertSame('voll', JobOffersZusage::ausfuehren($os6, [6]));
        $this->assertNull(RecDispoJobOfferShift::find($os6)->accepted_at);
    }

    public function test_eigene_zusage_ist_idempotent(): void
    {
        [, , $os5] = $this->aufbau(RecDispoJobRequest::MODUS_ZUERST, 1);

        $this->assertSame('ok', JobOffersZusage::ausfuehren($os5, [5]));
        $this->assertSame('ok', JobOffersZusage::ausfuehren($os5, [5]));
    }

    public function test_sammeln_schliesst_nicht(): void
    {
        [, $shift, $os5, $os6] = $this->aufbau(RecDispoJobRequest::MODUS_SAMMELN, 1);

        $this->assertSame('ok', JobOffersZusage::ausfuehren($os5, [5]));
        $this->assertSame('ok', JobOffersZusage::ausfuehren($os6, [6]));
        $this->assertNull($shift->fresh()->closed_at);
    }

    public function test_geschlossene_anfrage_ist_weg(): void
    {
        [, , $os5] = $this->aufbau(RecDispoJobRequest::MODUS_ZUERST, 3, RecDispoJobRequest::STATUS_ABGESCHLOSSEN);

        $this->assertSame('weg', JobOffersZusage::ausfuehren($os5, [5]));
        $this->assertNull(RecDispoJobOfferShift::find($os5)->accepted_at);
    }

    public function test_fremde_person_ist_weg(): void
    {
        [, , $os5] = $this->aufbau(RecDispoJobRequest::MODUS_ZUERST, 3);

        $this->assertSame('weg', JobOffersZusage::ausfuehren($os5, [6]));
        $this->assertSame('weg', JobOffersZusage::ausfuehren(999999, [5]));
        $this->assertNull(RecDispoJobOfferShift::find($os5)->accepted_at);
    }
}
