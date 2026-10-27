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
use Platform\Recruiting\Services\Zas\Dispo\Jobs\DispoJobGedeckt;

/** „Personalbedarf gedeckt“ von Hand (Spec 2026-10-27 §4) gegen die Datenbank. */
final class DispoJobGedecktDbTest extends TestCase
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

    private function anfrage(): RecDispoJobRequest
    {
        $r = RecDispoJobRequest::create(['team_id' => 3, 'filial_nr' => 200, 'zeitraum_von' => '2026-10-20', 'zeitraum_bis' => '2099-11-17',
            'modus' => RecDispoJobRequest::MODUS_SAMMELN, 'status' => RecDispoJobRequest::STATUS_LAEUFT, 'created_by_user_id' => 1]);
        $r->shifts()->create(['rec_dispo_event_id' => 1, 'datum' => now()->addDays(3)->toDateString(), 'von' => '17:00', 'taetigkeit' => 'Zapfer', 'gesucht' => 5]);
        $r->shifts()->create(['rec_dispo_event_id' => 1, 'datum' => now()->addDays(4)->toDateString(), 'von' => '17:00', 'taetigkeit' => 'Theke', 'gesucht' => 2]);

        return $r;
    }

    public function test_markieren_schliesst_auch_die_seite_und_oeffnen_hebt_beides_auf(): void
    {
        $r = $this->anfrage();
        $s = $r->shifts()->first();

        $this->assertCount(2, DispoJobGedeckt::offeneSchichten($r));
        $this->assertTrue(DispoJobGedeckt::markieren($r, (int) $s->id));
        $s->refresh();
        $this->assertNotNull($s->gedeckt_manuell_at);
        $this->assertNotNull($s->closed_at, 'die Seite zeigt „schon voll“');
        $this->assertCount(1, DispoJobGedeckt::offeneSchichten($r));
        $this->assertFalse(DispoJobGedeckt::markieren($r, (int) $s->id), 'zweites Markieren aendert nichts');

        $this->assertTrue(DispoJobGedeckt::oeffnen($r, (int) $s->id));
        $s->refresh();
        $this->assertNull($s->gedeckt_manuell_at);
        $this->assertNull($s->closed_at);
        $this->assertCount(2, DispoJobGedeckt::offeneSchichten($r));
    }

    public function test_fremde_anfrage_kann_nicht_markieren_und_alle_gedeckt(): void
    {
        $r = $this->anfrage();
        $fremd = $this->anfrage();
        $s = $r->shifts()->first();
        $this->assertFalse(DispoJobGedeckt::markieren($fremd, (int) $s->id));
        $this->assertFalse(DispoJobGedeckt::alleGedeckt($r));
        foreach ($r->shifts as $shift) {
            DispoJobGedeckt::markieren($r, (int) $shift->id);
        }
        $this->assertTrue(DispoJobGedeckt::alleGedeckt($r));
    }
}
