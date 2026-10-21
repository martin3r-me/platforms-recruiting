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
use Platform\Recruiting\Services\Zas\Dispo\Jobs\DispoJobOffeneZusagen;

/** Zaehler „Zusagen warten auf ZAS“ (Sidebar-Badge, Capsule/SQLite). */
class DispoJobOffeneZusagenTest extends TestCase
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

    private function anfrage(string $status, string $datum, int $team = 3): RecDispoJobRequest
    {
        static $event = 900;
        $event++;
        $request = RecDispoJobRequest::create(['team_id' => $team, 'filial_nr' => 200, 'zeitraum_von' => '2026-10-20', 'zeitraum_bis' => '2099-11-17',
            'modus' => RecDispoJobRequest::MODUS_SAMMELN, 'status' => $status, 'created_by_user_id' => 1]);
        $request->shifts()->create(['rec_dispo_event_id' => $event, 'datum' => $datum, 'von' => '17:00', 'taetigkeit' => 'Zapfer', 'gesucht' => 5]);
        $request->waves()->create(['nr' => 1, 'ma_bloecke' => [200], 'gruppen' => ['0'], 'nur_passende_taetigkeit' => true]);

        return $request;
    }

    private function zusage(RecDispoJobRequest $request, bool $zas, bool $angekommen = false, bool $angenommen = true): void
    {
        static $emp = 0;
        $emp++;
        $offer = $request->waves()->first()->offers()->create(['request_id' => $request->id, 'rec_employee_id' => $emp, 'anchor_employee_id' => $emp]);
        $offer->shifts()->create([
            'shift_id' => $request->shifts()->first()->id,
            'accepted_at' => $angenommen ? now() : null,
            'zas_entered_at' => $zas ? now() : null,
            'zas_assignment_id' => $angekommen ? 5 : null,
        ]);
    }

    public function test_zaehlt_nur_zusagen_ohne_haken_in_offenen_anfragen_mit_zukuenftigen_schichten(): void
    {
        $offen = $this->anfrage(RecDispoJobRequest::STATUS_LAEUFT, now()->addDays(3)->toDateString());
        $this->zusage($offen, zas: false);                      // zaehlt
        $this->zusage($offen, zas: true);                       // Haken gesetzt
        $this->zusage($offen, zas: false, angekommen: true);    // in ZAS angekommen
        $this->zusage($offen, zas: false, angenommen: false);   // nie zugesagt
        $zu = $this->anfrage(RecDispoJobRequest::STATUS_ABGESCHLOSSEN, now()->addDays(3)->toDateString());
        $this->zusage($zu, zas: false);
        $vorbei = $this->anfrage(RecDispoJobRequest::STATUS_LAEUFT, now()->subDay()->toDateString());
        $this->zusage($vorbei, zas: false);
        $fremd = $this->anfrage(RecDispoJobRequest::STATUS_LAEUFT, now()->addDays(3)->toDateString(), team: 9);
        $this->zusage($fremd, zas: false);

        $this->assertSame(1, DispoJobOffeneZusagen::count(3));
        $this->assertSame(1, DispoJobOffeneZusagen::count(9));
    }

    public function test_ohne_team_oder_bei_db_fehler_null(): void
    {
        $this->assertSame(0, DispoJobOffeneZusagen::count(0));
        Capsule::schema()->rename('rec_dispo_job_offer_shifts', 'weg');
        try {
            $this->assertSame(0, DispoJobOffeneZusagen::count(3), 'wirft nie — die Sidebar rendert auf jeder Seite');
        } finally {
            Capsule::schema()->rename('weg', 'rec_dispo_job_offer_shifts');
        }
    }
}
