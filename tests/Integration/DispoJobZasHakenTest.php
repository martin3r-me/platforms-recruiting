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
use Platform\Recruiting\Services\Zas\Dispo\Jobs\DispoJobZasHaken;

/** Haken „in ZAS eingetragen“ (Capsule/SQLite). */
class DispoJobZasHakenTest extends TestCase
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
            'database/migrations/2026_10_29_000001_create_rec_dispo_bedarfe_table.php',
            'database/migrations/2026_10_29_000002_add_dispoposten_id_to_rec_dispo_job_shifts.php',
            'database/migrations/2026_10_30_000001_add_zas_exported_at_to_rec_dispo_job_offer_shifts.php',
        ] as $rel) {
            (require $own . '/' . $rel)->up();
        }
    }

    public static function tearDownAfterClass(): void
    {
        Container::getInstance()->forgetInstance('config');
        Facade::clearResolvedInstances();
    }

    /** @return array{0: RecDispoJobRequest, 1: \Platform\Recruiting\Models\RecDispoJobShift, 2: \Platform\Recruiting\Models\RecDispoJobShift, 3: \Platform\Recruiting\Models\RecDispoJobShift} */
    private function aufbau(): array
    {
        static $event = 700;
        $request = RecDispoJobRequest::create(['team_id' => 3, 'filial_nr' => 200, 'zeitraum_von' => '2026-10-20', 'zeitraum_bis' => '2099-11-17',
            'modus' => RecDispoJobRequest::MODUS_SAMMELN, 'status' => RecDispoJobRequest::STATUS_LAEUFT, 'created_by_user_id' => 1]);
        $fremdReq = RecDispoJobRequest::create(['team_id' => 3, 'filial_nr' => 200, 'zeitraum_von' => '2026-10-20', 'zeitraum_bis' => '2099-11-17',
            'modus' => RecDispoJobRequest::MODUS_SAMMELN, 'status' => RecDispoJobRequest::STATUS_LAEUFT, 'created_by_user_id' => 1]);
        // Ein Angebot je Person und Anfrage, darunter eine Zeile je Schicht.
        $mk = function (RecDispoJobRequest $r, array $emps, int $anzahlSchichten) use (&$event) {
            $wave = $r->waves()->create(['nr' => 1, 'ma_bloecke' => [200], 'gruppen' => ['0'], 'nur_passende_taetigkeit' => true]);
            $offers = [];
            foreach ($emps as $emp) {
                $offers[] = $wave->offers()->create(['request_id' => $r->id, 'rec_employee_id' => $emp, 'anchor_employee_id' => $emp]);
            }
            $shifts = [];
            for ($i = 0; $i < $anzahlSchichten; $i++) {
                $event++;
                $shift = $r->shifts()->create(['rec_dispo_event_id' => $event, 'datum' => now()->addDays(5)->toDateString(), 'von' => '17:00', 'taetigkeit' => 'Zapfer', 'gesucht' => 5]);
                foreach ($offers as $offer) {
                    $offer->shifts()->create(['shift_id' => $shift->id, 'accepted_at' => now()]);
                }
                $shifts[] = $shift;
            }

            return $shifts;
        };
        [$s1, $s2] = $mk($request, [5, 6], 2);
        [$fremd] = $mk($fremdReq, [5], 1);

        return [$request, $s1, $s2, $fremd];
    }

    public function test_alle_setzt_nur_unverhakte_zeilen_der_eigenen_schicht_und_anfrage(): void
    {
        [$request, $s1, $s2, $fremd] = $this->aufbau();
        $schonVerhakt = RecDispoJobOfferShift::where('shift_id', $s1->id)->orderBy('id')->first();
        $schonVerhakt->update(['zas_entered_at' => '2026-10-01 10:00:00', 'zas_entered_by_user_id' => 7]);

        $n = DispoJobZasHaken::alle($request, $s1->id, 42);

        $this->assertSame(1, $n);
        $zeilen = RecDispoJobOfferShift::where('shift_id', $s1->id)->orderBy('id')->get();
        $this->assertSame('2026-10-01 10:00:00', $zeilen[0]->zas_entered_at->toDateTimeString(), 'bestehender Haken bleibt');
        $this->assertSame(7, (int) $zeilen[0]->zas_entered_by_user_id);
        $this->assertNotNull($zeilen[1]->zas_entered_at);
        $this->assertSame(42, (int) $zeilen[1]->zas_entered_by_user_id);
        $this->assertSame(0, RecDispoJobOfferShift::where('shift_id', $s2->id)->whereNotNull('zas_entered_at')->count(), 'andere Schicht unberuehrt');
        $this->assertSame(0, DispoJobZasHaken::alle($request, $fremd->id, 42), 'Schicht einer fremden Anfrage: nichts');
    }

    public function test_alle_ueberspringt_nicht_angenommene(): void
    {
        [$request, $s1] = $this->aufbau();
        RecDispoJobOfferShift::where('shift_id', $s1->id)->update(['accepted_at' => null]);

        $this->assertSame(0, DispoJobZasHaken::alle($request, $s1->id, 42));
    }

    public function test_umschalten_setzt_und_loescht_den_haken(): void
    {
        [$request, $s1] = $this->aufbau();
        $zeile = RecDispoJobOfferShift::where('shift_id', $s1->id)->orderBy('id')->first();

        $this->assertTrue(DispoJobZasHaken::umschalten($request, $zeile->id, 42));
        $this->assertNotNull($zeile->fresh()->zas_entered_at);
        $this->assertTrue(DispoJobZasHaken::umschalten($request, $zeile->id, 42));
        $this->assertNull($zeile->fresh()->zas_entered_at);
        $this->assertFalse(DispoJobZasHaken::umschalten($request, 999999, 42));
    }

    public function test_umschalten_auf_nicht_zugesagter_zeile_setzt_nichts(): void
    {
        [$request, $s1] = $this->aufbau();
        $zeile = RecDispoJobOfferShift::where('shift_id', $s1->id)->orderBy('id')->first();
        $zeile->update(['accepted_at' => null]);

        $this->assertFalse(DispoJobZasHaken::umschalten($request, $zeile->id, 42));
        $this->assertNull($zeile->fresh()->zas_entered_at);
        $this->assertNull($zeile->fresh()->zas_entered_by_user_id);
    }
}
