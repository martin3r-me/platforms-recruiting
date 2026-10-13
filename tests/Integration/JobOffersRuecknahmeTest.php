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
use Platform\Recruiting\Models\RecDispoJobShift;
use Platform\Recruiting\Services\Zas\Dispo\Jobs\DispoJobOffeneZusagen;
use Platform\Recruiting\Services\Zas\Dispo\Jobs\DispoJobSchonZugesagt;
use Platform\Recruiting\Services\Zas\Dispo\Jobs\JobOffersRuecknahme;
use Platform\Recruiting\Services\Zas\Dispo\Jobs\JobOffersZusage;

/**
 * „Doch nicht“ (Runde 2, Spec §9): Ruecknahme der eigenen Zusage (Capsule/SQLite).
 * Frische Datenbank je Test — die Zaehler-Tests fragen ganze Teams ab.
 */
class JobOffersRuecknahmeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
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
        Facade::clearResolvedInstances();
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

    protected function tearDown(): void
    {
        Container::getInstance()->forgetInstance('config');
        Facade::clearResolvedInstances();
        parent::tearDown();
    }

    /** @return array{0: RecDispoJobRequest, 1: RecDispoJobShift, 2: int, 3: int} */
    private function aufbau(string $modus, int $gesucht): array
    {
        static $event = 100;
        $event++;
        $request = RecDispoJobRequest::create(['team_id' => 3, 'filial_nr' => 200, 'zeitraum_von' => '2026-10-20', 'zeitraum_bis' => '2099-11-17',
            'modus' => $modus, 'status' => RecDispoJobRequest::STATUS_LAEUFT, 'created_by_user_id' => 1]);
        $shift = $request->shifts()->create(['rec_dispo_event_id' => $event, 'datum' => now()->addDays(5)->toDateString(), 'von' => '17:00', 'taetigkeit' => 'Zapfer', 'gesucht' => $gesucht]);
        $wave = $request->waves()->create(['nr' => 1, 'ma_bloecke' => [200], 'gruppen' => ['0'], 'nur_passende_taetigkeit' => true]);
        $osIds = [];
        foreach ([5, 6] as $emp) {
            $offer = $wave->offers()->create(['request_id' => $request->id, 'rec_employee_id' => $emp, 'anchor_employee_id' => $emp]);
            $osIds[$emp] = (int) $offer->shifts()->create(['shift_id' => $shift->id])->id;
        }

        return [$request, $shift, $osIds[5], $osIds[6]];
    }

    /** Dieselbe Schicht (VA, Tag, Beginn, Taetigkeit) aus einer zweiten laufenden Anfrage, angeboten an $emp. */
    private function zweiteAnfrageMitGleicherSchicht(RecDispoJobShift $shift, int $emp): int
    {
        $request = RecDispoJobRequest::create(['team_id' => 3, 'filial_nr' => 200, 'zeitraum_von' => '2026-10-20', 'zeitraum_bis' => '2099-11-17',
            'modus' => RecDispoJobRequest::MODUS_SAMMELN, 'status' => RecDispoJobRequest::STATUS_LAEUFT, 'created_by_user_id' => 1]);
        $zwilling = $request->shifts()->create(['rec_dispo_event_id' => $shift->rec_dispo_event_id, 'datum' => $shift->datum->toDateString(),
            'von' => $shift->von, 'taetigkeit' => $shift->taetigkeit, 'gesucht' => 3]);
        $wave = $request->waves()->create(['nr' => 1, 'ma_bloecke' => [200], 'gruppen' => ['0'], 'nur_passende_taetigkeit' => true]);
        $offer = $wave->offers()->create(['request_id' => $request->id, 'rec_employee_id' => $emp, 'anchor_employee_id' => $emp]);

        return (int) $offer->shifts()->create(['shift_id' => $zwilling->id])->id;
    }

    public function test_ruecknahme_leert_die_zusage_und_stempelt(): void
    {
        [, , $os5] = $this->aufbau(RecDispoJobRequest::MODUS_SAMMELN, 3);
        $this->assertSame('ok', JobOffersZusage::ausfuehren($os5, [5]));

        $this->assertSame('ok', JobOffersRuecknahme::ausfuehren($os5, [5]));
        $z = RecDispoJobOfferShift::find($os5);
        $this->assertNull($z->accepted_at);
        $this->assertNotNull($z->withdrawn_at);
        $this->assertSame('ok', JobOffersRuecknahme::ausfuehren($os5, [5]), 'idempotent');
    }

    public function test_erneute_zusage_loescht_den_ruecknahme_stempel(): void
    {
        [, , $os5] = $this->aufbau(RecDispoJobRequest::MODUS_SAMMELN, 3);
        JobOffersZusage::ausfuehren($os5, [5]);
        JobOffersRuecknahme::ausfuehren($os5, [5]);

        $this->assertSame('ok', JobOffersZusage::ausfuehren($os5, [5]));
        $z = RecDispoJobOfferShift::find($os5);
        $this->assertNotNull($z->accepted_at);
        $this->assertNull($z->withdrawn_at);
    }

    public function test_ruecknahme_bei_wer_zuerst_kommt_oeffnet_die_volle_schicht_wieder(): void
    {
        [, $shift, $os5, $os6] = $this->aufbau(RecDispoJobRequest::MODUS_ZUERST, 1);
        JobOffersZusage::ausfuehren($os5, [5]);
        $this->assertNotNull($shift->fresh()->closed_at, 'Testannahme: voll');

        $this->assertSame('ok', JobOffersRuecknahme::ausfuehren($os5, [5]));
        $this->assertNull($shift->fresh()->closed_at, 'Platz wieder frei');
        $this->assertSame('ok', JobOffersZusage::ausfuehren($os6, [6]), 'die naechste Person kann zusagen');
    }

    public function test_eingetragen_oder_angekommen_blockt(): void
    {
        [, , $os5, $os6] = $this->aufbau(RecDispoJobRequest::MODUS_SAMMELN, 3);
        JobOffersZusage::ausfuehren($os5, [5]);
        JobOffersZusage::ausfuehren($os6, [6]);
        RecDispoJobOfferShift::whereKey($os5)->update(['zas_entered_at' => now()]);
        RecDispoJobOfferShift::whereKey($os6)->update(['zas_assignment_id' => 77]);

        $this->assertSame('eingetragen', JobOffersRuecknahme::ausfuehren($os5, [5]));
        $this->assertSame('eingetragen', JobOffersRuecknahme::ausfuehren($os6, [6]));
        $this->assertNotNull(RecDispoJobOfferShift::find($os5)->accepted_at);
    }

    public function test_weg_bei_fremder_person_nie_zugesagt_abgeschlossen_oder_vorbei(): void
    {
        [$request, $shift, $os5] = $this->aufbau(RecDispoJobRequest::MODUS_SAMMELN, 3);
        $this->assertSame('weg', JobOffersRuecknahme::ausfuehren($os5, [5]), 'nie zugesagt');
        JobOffersZusage::ausfuehren($os5, [5]);
        $this->assertSame('weg', JobOffersRuecknahme::ausfuehren($os5, [6]), 'fremde Person');
        $this->assertSame('weg', JobOffersRuecknahme::ausfuehren(999999, [5]));
        $shift->update(['datum' => now()->subDay()->toDateString()]);
        $this->assertSame('weg', JobOffersRuecknahme::ausfuehren($os5, [5]), 'vorbei');
        $shift->update(['datum' => now()->addDays(5)->toDateString()]);
        $request->update(['status' => RecDispoJobRequest::STATUS_ABGESCHLOSSEN, 'closed_at' => now()]);
        $this->assertSame('weg', JobOffersRuecknahme::ausfuehren($os5, [5]), 'abgeschlossen');
        $this->assertNotNull(RecDispoJobOfferShift::find($os5)->accepted_at, 'nichts angefasst');
    }

    public function test_schwester_in_anderer_anfrage_wird_mit_zurueckgenommen_oder_blockt(): void
    {
        [, $shift, $os5] = $this->aufbau(RecDispoJobRequest::MODUS_SAMMELN, 3);
        $schwester = $this->zweiteAnfrageMitGleicherSchicht($shift, 5);
        JobOffersZusage::ausfuehren($os5, [5]);
        $this->assertNotNull(RecDispoJobOfferShift::find($schwester)->accepted_at, 'Testannahme: Zusage gilt fuer beide');

        $this->assertSame('ok', JobOffersRuecknahme::ausfuehren($os5, [5]));
        $this->assertNull(RecDispoJobOfferShift::find($schwester)->accepted_at);

        JobOffersZusage::ausfuehren($os5, [5]);
        RecDispoJobOfferShift::whereKey($schwester)->update(['zas_entered_at' => now()]);
        $this->assertSame('eingetragen', JobOffersRuecknahme::ausfuehren($os5, [5]));
        $this->assertNotNull(RecDispoJobOfferShift::find($os5)->accepted_at, 'bleibt stehen, weil die Schwester eingetragen ist');
    }

    public function test_zurueckgezogene_zaehlen_nirgends_mehr(): void
    {
        [$request, $shift, $os5] = $this->aufbau(RecDispoJobRequest::MODUS_SAMMELN, 3);
        JobOffersZusage::ausfuehren($os5, [5]);
        JobOffersRuecknahme::ausfuehren($os5, [5]);

        $this->assertSame([], DispoJobSchonZugesagt::laden(3));
        $this->assertSame(0, DispoJobOffeneZusagen::count(3));
        $this->assertSame(0, RecDispoJobOfferShift::where('shift_id', $shift->id)->whereNotNull('accepted_at')->count());
    }
}
