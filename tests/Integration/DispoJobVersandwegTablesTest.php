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
use Platform\Recruiting\Models\RecPushAbo;

/** Versandweg, Freigabe und Push-Abos (Markus 13.11.2026). Muster DispoJobTablesTest. */
class DispoJobVersandwegTablesTest extends TestCase
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
            'database/migrations/2026_11_13_000001_add_versandweg_freigabe_to_rec_dispo_job_tables.php',
            'database/migrations/2026_11_13_000002_create_rec_push_abos_table.php',
        ] as $rel) {
            (require $own . '/' . $rel)->up();
        }
    }

    public static function tearDownAfterClass(): void
    {
        Container::getInstance()->forgetInstance('config');
        Facade::clearResolvedInstances();
    }

    private function anfrage(array $extra = []): RecDispoJobRequest
    {
        return RecDispoJobRequest::create(array_merge(['team_id' => 3, 'filial_nr' => 200, 'zeitraum_von' => '2026-11-20', 'zeitraum_bis' => '2026-12-17',
            'modus' => RecDispoJobRequest::MODUS_SAMMELN, 'status' => RecDispoJobRequest::STATUS_LAEUFT], $extra))->fresh();
    }

    public function test_altzeile_bleibt_whatsapp_und_darf_senden(): void
    {
        $r = $this->anfrage();
        $this->assertSame('whatsapp', $r->versandweg);
        $this->assertFalse($r->istPortal());
        $this->assertFalse($r->freigabe_pflicht);
        $this->assertTrue($r->darfSenden());
        $this->assertFalse($r->wartetAufFreigabe());
        $this->assertFalse($r->freigabeAbgelehnt());
    }

    public function test_portalweg(): void
    {
        $this->assertTrue($this->anfrage(['versandweg' => RecDispoJobRequest::WEG_PORTAL])->istPortal());
    }

    public function test_freigabe_zustaende(): void
    {
        $wartet = $this->anfrage(['freigabe_pflicht' => true]);
        $this->assertFalse($wartet->darfSenden());
        $this->assertTrue($wartet->wartetAufFreigabe());
        $this->assertFalse($wartet->freigabeAbgelehnt());

        $frei = $this->anfrage(['freigabe_pflicht' => true, 'freigegeben_at' => '2026-11-13 10:00:00', 'freigegeben_by_user_id' => 9]);
        $this->assertTrue($frei->darfSenden());
        $this->assertFalse($frei->wartetAufFreigabe());
        $this->assertFalse($frei->freigabeAbgelehnt());

        $abgelehnt = $this->anfrage(['freigabe_pflicht' => true, 'freigabe_abgelehnt_at' => '2026-11-13 10:00:00', 'freigabe_ablehnungsgrund' => 'zu frueh']);
        $this->assertFalse($abgelehnt->darfSenden());
        $this->assertFalse($abgelehnt->wartetAufFreigabe());
        $this->assertTrue($abgelehnt->freigabeAbgelehnt());

        $zu = $this->anfrage(['freigabe_pflicht' => true, 'closed_at' => '2026-11-13 10:00:00']);
        $this->assertFalse($zu->wartetAufFreigabe());
    }

    public function test_angebot_kanal_default_whatsapp(): void
    {
        $r = $this->anfrage();
        $wave = $r->waves()->create(['nr' => 1, 'ma_bloecke' => [200], 'gruppen' => ['0'], 'nur_passende_taetigkeit' => true]);
        $o = $wave->offers()->create(['request_id' => $r->id, 'rec_employee_id' => 5, 'anchor_employee_id' => 5])->fresh();
        $this->assertSame('whatsapp', $o->kanal);
    }

    public function test_push_abo_endpoint_hash_ist_eindeutig(): void
    {
        $row = ['rec_employee_id' => 5, 'endpoint' => 'https://push.example/abc', 'endpoint_hash' => RecPushAbo::hash('https://push.example/abc'), 'p256dh' => 'k', 'auth' => 'a'];
        $this->assertSame(hash('sha256', 'https://push.example/abc'), $row['endpoint_hash']);
        RecPushAbo::create($row);
        $this->expectException(\Illuminate\Database\QueryException::class);
        RecPushAbo::create($row);
    }
}
