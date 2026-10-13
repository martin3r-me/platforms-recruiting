<?php

namespace Platform\Recruiting\Tests\Integration;

use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Models\RecDispoJobRequest;
use Platform\Recruiting\Services\Zas\Dispo\Jobs\DispoJobUebersichtZahlen;

/** Zahlen der Jobs-anbieten-Uebersicht (Capsule/SQLite). */
class DispoJobUebersichtZahlenTest extends TestCase
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
            'database/migrations/2026_10_13_000002_add_anlass_to_rec_dispo_job_requests.php',
            'database/migrations/2026_10_14_000001_add_bis_and_withdrawn_at_to_rec_dispo_job_tables.php',
        ] as $rel) {
            (require $own . '/' . $rel)->up();
        }
    }

    public static function tearDownAfterClass(): void
    {
        Container::getInstance()->forgetInstance('config');
        Facade::clearResolvedInstances();
    }

    public function test_zugesagt_gesucht_und_noch_in_zas_je_anfrage(): void
    {
        $request = RecDispoJobRequest::create(['team_id' => 3, 'filial_nr' => 200, 'zeitraum_von' => '2026-10-20', 'zeitraum_bis' => '2099-11-17', 'modus' => 'sammeln', 'status' => 'laeuft', 'created_by_user_id' => 1]);
        $s1 = $request->shifts()->create(['rec_dispo_event_id' => 1, 'datum' => '2099-10-20', 'von' => '', 'taetigkeit' => 'A', 'gesucht' => 3]);
        $s2 = $request->shifts()->create(['rec_dispo_event_id' => 1, 'datum' => '2099-10-21', 'von' => '', 'taetigkeit' => 'B', 'gesucht' => 2]);
        $wave = $request->waves()->create(['nr' => 1, 'ma_bloecke' => [200], 'gruppen' => ['0'], 'nur_passende_taetigkeit' => true]);
        $offer = $wave->offers()->create(['request_id' => $request->id, 'rec_employee_id' => 5, 'anchor_employee_id' => 5]);
        $offer->shifts()->create(['shift_id' => $s1->id, 'accepted_at' => now()]);
        $offer->shifts()->create(['shift_id' => $s2->id, 'accepted_at' => now(), 'zas_entered_at' => now()]);
        // Unique (offer_id, shift_id): die zurueckgezogene Zeile braucht ein zweites Angebot.
        $offer2 = $wave->offers()->create(['request_id' => $request->id, 'rec_employee_id' => 6, 'anchor_employee_id' => 6]);
        $offer2->shifts()->create(['shift_id' => $s2->id, 'accepted_at' => null, 'withdrawn_at' => now()]);
        $leer = RecDispoJobRequest::create(['team_id' => 3, 'filial_nr' => 200, 'zeitraum_von' => '2026-10-20', 'zeitraum_bis' => '2099-11-17', 'modus' => 'sammeln', 'status' => 'laeuft', 'created_by_user_id' => 1]);

        $z = DispoJobUebersichtZahlen::laden([$request->id, $leer->id, 999999]);

        $this->assertSame(['zugesagt' => 2, 'gesucht' => 5, 'noch_zas' => 1, 'besetzt' => 2], $z[$request->id]);
        $this->assertSame(['zugesagt' => 0, 'gesucht' => 0, 'noch_zas' => 0, 'besetzt' => 0], $z[$leer->id]);
        $this->assertArrayNotHasKey(999999, $z);
        $this->assertSame([], DispoJobUebersichtZahlen::laden([]));
    }

    public function test_ueberfuellte_schicht_deckt_keine_leere_schicht(): void
    {
        // Modus „sammeln“: A gesucht 2 / zugesagt 3, B gesucht 2 / zugesagt 0 — besetzt 2, nicht 3.
        $request = RecDispoJobRequest::create(['team_id' => 3, 'filial_nr' => 200, 'zeitraum_von' => '2026-10-20', 'zeitraum_bis' => '2099-11-17', 'modus' => 'sammeln', 'status' => 'laeuft', 'created_by_user_id' => 1]);
        $a = $request->shifts()->create(['rec_dispo_event_id' => 1, 'datum' => '2099-10-20', 'von' => '', 'taetigkeit' => 'A', 'gesucht' => 2]);
        $request->shifts()->create(['rec_dispo_event_id' => 1, 'datum' => '2099-10-21', 'von' => '', 'taetigkeit' => 'B', 'gesucht' => 2]);
        $wave = $request->waves()->create(['nr' => 1, 'ma_bloecke' => [200], 'gruppen' => ['0'], 'nur_passende_taetigkeit' => true]);
        foreach ([11, 12, 13] as $ma) {
            $offer = $wave->offers()->create(['request_id' => $request->id, 'rec_employee_id' => $ma, 'anchor_employee_id' => $ma]);
            $offer->shifts()->create(['shift_id' => $a->id, 'accepted_at' => now()]);
        }

        $z = DispoJobUebersichtZahlen::laden([$request->id]);

        $this->assertSame(['zugesagt' => 3, 'gesucht' => 4, 'noch_zas' => 3, 'besetzt' => 2], $z[$request->id]);
    }
}
