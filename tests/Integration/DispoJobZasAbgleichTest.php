<?php

namespace Platform\Recruiting\Tests\Integration;

use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Models\RecDispoAssignment;
use Platform\Recruiting\Models\RecDispoJobOffer;
use Platform\Recruiting\Models\RecDispoJobOfferShift;
use Platform\Recruiting\Models\RecDispoJobRequest;
use Platform\Recruiting\Services\Zas\Dispo\Jobs\DispoJobZasAbgleich;

/** Zusagen werden mit angekommenen ZAS-Einbuchungen verknuepft. */
class DispoJobZasAbgleichTest extends TestCase
{
    private int $seq = 0;

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
        $container->instance('config', new ConfigRepository(['recruiting' => ['zas' => ['inbound_team_id' => 0]]]));
        $own = dirname(__DIR__, 2);
        foreach ([
            'database/migrations/2026_08_12_000001_create_rec_dispo_events_table.php',
            'database/migrations/2026_08_12_000002_create_rec_dispo_assignments_table.php',
            'database/migrations/2026_08_14_000001_add_confirmation_fields_to_rec_dispo_assignments.php',
            'database/migrations/2026_08_20_000001_add_filiale_to_rec_dispo_events.php',
            'database/migrations/2026_09_04_000001_add_decline_fields_to_rec_dispo_assignments.php',
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

    private function assignment(int $event, string $datum, int $emp, array $extra = []): RecDispoAssignment
    {
        $n = ++$this->seq;
        return RecDispoAssignment::create(array_merge([
            'ds_ref' => 'DS' . $n, 'rec_dispo_event_id' => $event, 'pnr_raw' => 'RG' . $emp,
            'rec_employee_id' => $emp, 'datum' => $datum, 'status_id' => RecDispoAssignment::STATUS_AUFTRAG,
        ], $extra));
    }

    private function offerShift(RecDispoJobRequest $req, int $event, string $datum, int $emp, bool $accepted): RecDispoJobOfferShift
    {
        $shift = $req->shifts()->create(['rec_dispo_event_id' => $event, 'datum' => $datum, 'von' => '17:00', 'taetigkeit' => 'Zapfer', 'gesucht' => 5]);
        $wave = $req->waves()->firstOrCreate(['nr' => 1], ['ma_bloecke' => [200], 'gruppen' => ['0'], 'nur_passende_taetigkeit' => true]);
        $offer = RecDispoJobOffer::firstOrCreate(
            ['wave_id' => $wave->id, 'request_id' => $req->id, 'rec_employee_id' => $emp],
            ['anchor_employee_id' => $emp]
        );
        return $offer->shifts()->create(['shift_id' => $shift->id, 'accepted_at' => $accepted ? now() : null]);
    }

    public function test_zusage_wird_mit_angekommener_einbuchung_verknuepft(): void
    {
        $req = RecDispoJobRequest::create(['team_id' => 3, 'filial_nr' => 200, 'zeitraum_von' => '2026-10-20', 'zeitraum_bis' => '2026-11-17',
            'modus' => RecDispoJobRequest::MODUS_SAMMELN, 'status' => RecDispoJobRequest::STATUS_ENTWURF]);

        $treffer = $this->offerShift($req, 1, '2026-10-24', 5, true);
        $andererTag = $this->offerShift($req, 1, '2026-10-25', 5, true);
        $andereVa = $this->offerShift($req, 2, '2026-10-24', 5, true);
        $storno = $this->offerShift($req, 3, '2026-10-24', 5, true);
        $fehlend = $this->offerShift($req, 4, '2026-10-24', 5, true);
        $markiert = $this->offerShift($req, 6, '2026-10-24', 5, true);
        $nichtZugesagt = $this->offerShift($req, 5, '2026-10-24', 5, false);

        $gut = $this->assignment(1, '2026-10-24', 5);
        $this->assignment(1, '2026-10-26', 5);          // anderer Tag als Zusage 2
        $this->assignment(2, '2026-10-30', 5);          // andere Tage
        $this->assignment(3, '2026-10-24', 5, ['status_id' => 3]);
        $this->assignment(4, '2026-10-24', 5, ['missing_since' => now()]);
        $this->assignment(6, '2026-10-24', 5, ['deletion_marked_at' => now()]);
        $this->assignment(5, '2026-10-24', 5);          // passt, aber Zusage fehlt
        $this->assignment(1, '2026-10-24', 99);         // andere Person

        $run = app(DispoJobZasAbgleich::class);
        $this->assertSame(1, $run->run());

        $this->assertSame((int) $gut->id, (int) $treffer->fresh()->zas_assignment_id);
        foreach ([$andererTag, $andereVa, $storno, $fehlend, $markiert, $nichtZugesagt] as $os) {
            $this->assertNull($os->fresh()->zas_assignment_id);
        }

        $this->assertSame(0, $run->run());
    }
}
