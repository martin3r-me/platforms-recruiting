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
            'database/migrations/2026_10_20_000001_add_stati_to_rec_dispo_job_waves.php',
            'database/migrations/2026_10_27_000001_add_wellen_to_rec_dispo_job_tables.php',
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

    /** Eine Anfrage mit EINER Schicht (VA $event, in 5 Tagen, 17:00, Zapfer), angeboten an $emps. @return array{0:\Platform\Recruiting\Models\RecDispoJobShift, 1:array<int,int>} */
    private function anfrageMitSchicht(string $modus, int $gesucht, int $event, array $emps): array
    {
        $request = RecDispoJobRequest::create(['team_id' => 3, 'filial_nr' => 200, 'zeitraum_von' => '2026-10-20', 'zeitraum_bis' => '2099-11-17',
            'modus' => $modus, 'status' => RecDispoJobRequest::STATUS_LAEUFT, 'created_by_user_id' => 1]);
        $shift = $request->shifts()->create(['rec_dispo_event_id' => $event, 'datum' => now()->addDays(5)->toDateString(), 'von' => '17:00', 'taetigkeit' => 'Zapfer', 'gesucht' => $gesucht]);
        $wave = $request->waves()->create(['nr' => 1, 'ma_bloecke' => [200], 'gruppen' => ['0'], 'nur_passende_taetigkeit' => true]);
        $os = [];
        foreach ($emps as $emp) {
            $offer = $wave->offers()->create(['request_id' => $request->id, 'rec_employee_id' => $emp, 'anchor_employee_id' => $emp]);
            $os[$emp] = (int) $offer->shifts()->create(['shift_id' => $shift->id])->id;
        }

        return [$shift, $os];
    }

    /**
     * I1: Zaehlung serialisiert ueber die Schicht-Sperre. SQLite kennt weder
     * REPEATABLE READ noch FOR UPDATE — der Wettlauf selbst ist hier nicht
     * nachstellbar. Gedeckt ist die Regel in Folge: gesucht=2 → zwei ok, die
     * zweite schliesst, die dritte ist voll.
     */
    public function test_zuerst_mit_zwei_gesucht_dritte_ist_voll(): void
    {
        [$shift, $os] = $this->anfrageMitSchicht(RecDispoJobRequest::MODUS_ZUERST, 2, 500, [5, 6, 7]);

        $this->assertSame('ok', JobOffersZusage::ausfuehren($os[5], [5]));
        $this->assertNull($shift->fresh()->closed_at);
        $this->assertSame('ok', JobOffersZusage::ausfuehren($os[6], [6]));
        $this->assertNotNull($shift->fresh()->closed_at);
        $this->assertSame('voll', JobOffersZusage::ausfuehren($os[7], [7]));
        $this->assertSame(2, RecDispoJobOfferShift::query()->where('shift_id', $shift->id)->whereNotNull('accepted_at')->count());
    }

    /** I2 / Spec §9: dieselbe Schicht aus zwei Anfragen — ein Klick sagt in beiden zu. */
    public function test_zusage_zaehlt_in_beiden_anfragen(): void
    {
        [$shiftA, $osA] = $this->anfrageMitSchicht(RecDispoJobRequest::MODUS_SAMMELN, 3, 501, [5]);
        [$shiftB, $osB] = $this->anfrageMitSchicht(RecDispoJobRequest::MODUS_ZUERST, 1, 501, [5, 6]);
        [, $osAndere] = $this->anfrageMitSchicht(RecDispoJobRequest::MODUS_SAMMELN, 3, 502, [5]); // andere VA

        $this->assertSame('ok', JobOffersZusage::ausfuehren($osA[5], [5]));

        $this->assertNotNull(RecDispoJobOfferShift::find($osA[5])->accepted_at);
        $this->assertNotNull(RecDispoJobOfferShift::find($osB[5])->accepted_at, 'Zusage zaehlt auch in der zweiten Anfrage');
        $this->assertNotNull($shiftB->fresh()->closed_at, 'Modus zuerst der Schwester: voll → geschlossen');
        $this->assertNull(RecDispoJobOfferShift::find($osB[6])->accepted_at, 'nur die eigene Person');
        $this->assertNull(RecDispoJobOfferShift::find($osAndere[5])->accepted_at, 'andere VA bleibt offen');
        $this->assertNull($shiftA->fresh()->closed_at);
    }

    public function test_volle_zuerst_schwester_bleibt_unberuehrt(): void
    {
        [, $osA] = $this->anfrageMitSchicht(RecDispoJobRequest::MODUS_SAMMELN, 3, 503, [5]);
        [$shiftB, $osB] = $this->anfrageMitSchicht(RecDispoJobRequest::MODUS_ZUERST, 1, 503, [5, 6]);
        RecDispoJobOfferShift::query()->whereKey($osB[6])->update(['accepted_at' => now()]); // voll, aber noch nicht geschlossen

        $this->assertSame('ok', JobOffersZusage::ausfuehren($osA[5], [5]));

        $this->assertNotNull(RecDispoJobOfferShift::find($osA[5])->accepted_at);
        $this->assertNull(RecDispoJobOfferShift::find($osB[5])->accepted_at, 'volle Schwester im Modus zuerst: keine Ueber-Zusage');
        $this->assertSame(1, RecDispoJobOfferShift::query()->where('shift_id', $shiftB->id)->whereNotNull('accepted_at')->count());
    }

    public function test_schwester_in_geschlossener_anfrage_bleibt_unberuehrt(): void
    {
        [, $osA] = $this->anfrageMitSchicht(RecDispoJobRequest::MODUS_SAMMELN, 3, 504, [5]);
        [$shiftB, $osB] = $this->anfrageMitSchicht(RecDispoJobRequest::MODUS_SAMMELN, 3, 504, [5]);
        $shiftB->request->update(['status' => RecDispoJobRequest::STATUS_ABGESCHLOSSEN, 'closed_at' => now()]);

        $this->assertSame('ok', JobOffersZusage::ausfuehren($osA[5], [5]));
        $this->assertNull(RecDispoJobOfferShift::find($osB[5])->accepted_at);
    }
}
