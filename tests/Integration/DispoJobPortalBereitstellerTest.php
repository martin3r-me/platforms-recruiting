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
use Platform\Recruiting\Models\RecDispoJobOfferShift;
use Platform\Recruiting\Models\RecDispoJobRequest;
use Platform\Recruiting\Services\Zas\Dispo\Jobs\DispoJobPortalBereitsteller;

/** Portal-Weg (Markus 13.11.2026): Angebote samt Schichten anlegen, Kanal portal, nie doppelt, wirft nie. Muster DispoJobOfferSenderTest. */
class DispoJobPortalBereitstellerTest extends TestCase
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
            'database/migrations/2026_10_13_000001_create_rec_dispo_job_tables.php',
            'database/migrations/2026_10_20_000001_add_stati_to_rec_dispo_job_waves.php',
            'database/migrations/2026_10_27_000001_add_wellen_to_rec_dispo_job_tables.php',
            'database/migrations/2026_11_13_000001_add_versandweg_freigabe_to_rec_dispo_job_tables.php',
            'database/migrations/2026_10_13_000002_add_anlass_to_rec_dispo_job_requests.php',
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
        foreach (['rec_dispo_job_offer_shifts', 'rec_dispo_job_offers', 'rec_dispo_job_waves', 'rec_dispo_job_shifts', 'rec_dispo_job_requests'] as $t) {
            Capsule::table($t)->delete();
        }
    }

    private function anfrageMitWelle(): array
    {
        $request = RecDispoJobRequest::create(['team_id' => 3, 'filial_nr' => 200, 'zeitraum_von' => '2026-11-20', 'zeitraum_bis' => '2026-11-27',
            'modus' => RecDispoJobRequest::MODUS_SAMMELN, 'status' => RecDispoJobRequest::STATUS_LAEUFT, 'versandweg' => RecDispoJobRequest::WEG_PORTAL]);
        $shift = $request->shifts()->create(['rec_dispo_event_id' => 1, 'datum' => '2026-11-21', 'von' => '17:00', 'taetigkeit' => 'Zapfer', 'gesucht' => 5]);
        $wave = $request->waves()->create(['nr' => 1, 'ma_bloecke' => [200], 'gruppen' => ['alle']]);

        return [$request, $wave, $shift];
    }

    private function recipient(int $shiftId, int $anchor = 5): array
    {
        return ['anchor' => $anchor, 'employee_id' => $anchor, 'phone' => '+49176' . $anchor, 'first_name' => 'Anna', 'portal_token' => 'tok' . $anchor, 'shift_ids' => [$shiftId]];
    }

    public function test_legt_angebot_mit_schichten_kanal_portal_und_sent_at_an(): void
    {
        [$request, $wave, $shift] = $this->anfrageMitWelle();

        $r = (new DispoJobPortalBereitsteller())->bereitstellen($wave, [$this->recipient($shift->id), $this->recipient($shift->id, 6)]);

        $this->assertSame(['ok' => true, 'message' => null, 'sent' => 2, 'failed' => []], $r);
        $offers = RecDispoJobOffer::where('wave_id', $wave->id)->orderBy('anchor_employee_id')->get();
        $this->assertCount(2, $offers);
        $this->assertSame('portal', $offers[0]->kanal);
        $this->assertNotNull($offers[0]->sent_at, 'sent_at = bereitgestellt');
        $this->assertNull($offers[0]->message_id, 'keine Nachricht');
        $this->assertNull($offers[0]->send_error);
        $this->assertSame($request->id, (int) $offers[0]->request_id);
        $this->assertSame(5, (int) $offers[0]->rec_employee_id);
        $this->assertSame([$shift->id], RecDispoJobOfferShift::where('offer_id', $offers[0]->id)->pluck('shift_id')->map(fn ($v) => (int) $v)->all());
    }

    public function test_schon_angeschriebene_person_wird_nicht_noch_einmal_bereitgestellt(): void
    {
        [$request, $wave, $shift] = $this->anfrageMitWelle();
        RecDispoJobOffer::create(['wave_id' => $wave->id, 'request_id' => $request->id, 'rec_employee_id' => 5, 'anchor_employee_id' => 5, 'sent_at' => now()]);

        $r = (new DispoJobPortalBereitsteller())->bereitstellen($wave, [$this->recipient($shift->id)]);

        $this->assertTrue($r['ok']);
        $this->assertSame(0, $r['sent']);
        $this->assertSame([['anchor' => 5, 'error' => 'in dieser Anfrage schon angeschrieben']], $r['failed']);
        $this->assertSame(1, RecDispoJobOffer::count());
    }

    public function test_db_fehler_bei_einer_person_bricht_die_schleife_nicht_ab_und_stempelt_das_angebot(): void
    {
        [, $wave, $shift] = $this->anfrageMitWelle();
        $bad = $this->recipient($shift->id);
        $bad['shift_ids'] = [$shift->id, null]; // shift_id NOT NULL -> DB-Fehler

        $r = (new DispoJobPortalBereitsteller())->bereitstellen($wave, [$bad, $this->recipient($shift->id, 6)]);

        $this->assertTrue($r['ok']);
        $this->assertSame(1, $r['sent']);
        $this->assertCount(1, $r['failed']);
        $this->assertSame(5, $r['failed'][0]['anchor']);
        $kaputt = RecDispoJobOffer::where('anchor_employee_id', 5)->first();
        $this->assertNull($kaputt->sent_at, 'nicht bereitgestellt');
        $this->assertNotNull($kaputt->send_error);
        $this->assertNotNull(RecDispoJobOffer::where('anchor_employee_id', 6)->first()->sent_at);
    }
}
