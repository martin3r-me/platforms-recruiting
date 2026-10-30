<?php

namespace Platform\Recruiting\Tests\Integration;

use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Models\RecDispoEvent;
use Platform\Recruiting\Models\RecDispoJobOffer;
use Platform\Recruiting\Models\RecDispoJobOfferShift;
use Platform\Recruiting\Models\RecDispoJobRequest;
use Platform\Recruiting\Models\RecDispoJobShift;
use Platform\Recruiting\Services\Zas\Dispo\Jobs\DispoJobOffeneZusagen;
use Platform\Recruiting\Services\Zas\Dispo\ZasDispoBookingExport;
use Platform\Recruiting\Services\Zas\ZasCsvBuilder;

/**
 * Zusagen als Buchungen an ZAS (Spec 2026-10-30 §1–§3): Auswahlregeln je
 * Bedingung, Olafs Zeilenformat byteweise, Marker genau einmal.
 */
class ZasDispoBookingExportTest extends TestCase
{
    private static int $seq = 0;

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
            'database/migrations/2026_05_20_000001_create_rec_employees_table.php',
            'database/migrations/2026_05_22_000001_add_personnel_number_to_rec_employees.php',
            'database/migrations/2026_10_21_000001_add_is_test_to_rec_employees.php',
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

    protected function setUp(): void
    {
        foreach (['rec_dispo_job_offer_shifts', 'rec_dispo_job_offers', 'rec_dispo_job_waves', 'rec_dispo_job_shifts', 'rec_dispo_job_requests', 'rec_dispo_events', 'rec_employees'] as $t) {
            Capsule::table($t)->delete();
        }
    }

    private function ma(int $id, ?string $pnr, bool $test = false): int
    {
        Capsule::table('rec_employees')->insert([
            'id' => $id, 'uuid' => 'u-' . $id, 'team_id' => 3, 'first_name' => 'E' . $id, 'last_name' => 'M', 'is_active' => true,
            'personnel_number' => $pnr, 'is_test' => $test, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    }

    private function anfrage(string $status = RecDispoJobRequest::STATUS_LAEUFT): RecDispoJobRequest
    {
        $r = RecDispoJobRequest::create(['team_id' => 3, 'filial_nr' => 200, 'zeitraum_von' => '2026-10-20', 'zeitraum_bis' => '2099-11-17',
            'modus' => RecDispoJobRequest::MODUS_SAMMELN, 'status' => $status, 'created_by_user_id' => 1]);
        $r->waves()->create(['nr' => 1, 'ma_bloecke' => [200], 'gruppen' => ['0'], 'nur_passende_taetigkeit' => true]);

        return $r;
    }

    private function schicht(RecDispoJobRequest $r, RecDispoEvent $event, string $datum, ?string $posten = '121000'): RecDispoJobShift
    {
        return $r->shifts()->create(['rec_dispo_event_id' => $event->id, 'datum' => $datum, 'von' => '17:00', 'taetigkeit' => 'Zapfer', 'gesucht' => 5, 'dispoposten_id' => $posten]);
    }

    private function zusage(RecDispoJobShift $shift, int $emp, array $extra = []): RecDispoJobOfferShift
    {
        $r = $shift->request;
        $offer = RecDispoJobOffer::firstOrCreate(
            ['wave_id' => $r->waves()->first()->id, 'request_id' => $r->id, 'rec_employee_id' => $emp],
            ['anchor_employee_id' => $emp]
        );

        return $offer->shifts()->create($extra + ['shift_id' => $shift->id, 'accepted_at' => now()->subHour()]);
    }

    public function test_nur_zusagen_mit_posten_personalnummer_und_ohne_marker(): void
    {
        $va = RecDispoEvent::create(['einsatz_ref' => 'RG19801', 'name' => 'Borussia']);
        $ohneRef = RecDispoEvent::create(['einsatz_ref' => '', 'name' => 'ohne Nummer']);
        $r = $this->anfrage(RecDispoJobRequest::STATUS_ABGESCHLOSSEN); // Anfrage-Status ist egal
        $morgen = now()->addDay()->toDateString();
        $gut = $this->schicht($r, $va, $morgen);
        $ohnePosten = $this->schicht($r, $va, $morgen, null);
        $leerPosten = $this->schicht($r, $va, $morgen, '');
        $vorbei = $this->schicht($r, $va, now()->subDay()->toDateString());
        $ohneRefSchicht = $this->schicht($r, $ohneRef, $morgen);

        $this->ma(1, 'RG17502');
        $this->ma(2, '');
        $this->ma(3, null);
        $this->ma(4, 'RG99999', test: true);
        $this->ma(5, 'RG17533');
        $this->ma(6, 'RG17600');
        $this->ma(7, 'RG17700');
        $this->ma(8, 'RG17800');
        $this->ma(9, 'RG17900');

        $treffer = $this->zusage($gut, 1);
        $this->zusage($gut, 2);                                              // keine Personalnummer
        $this->zusage($gut, 3);                                              // Personalnummer null
        $this->zusage($gut, 4);                                              // Test-MA
        $this->zusage($gut, 5, ['zas_entered_at' => now()]);                 // von Hand eingetragen
        $this->zusage($gut, 6, ['zas_assignment_id' => 77]);                 // schon angekommen
        $this->zusage($gut, 7, ['zas_exported_at' => now()]);                // schon uebergeben
        $this->zusage($gut, 8, ['accepted_at' => null]);                     // nie zugesagt
        $this->zusage($gut, 9, ['declined_at' => now()]);                    // abgesagt
        $this->zusage($ohnePosten, 1);
        $this->zusage($leerPosten, 1);
        $this->zusage($vorbei, 1);
        $this->zusage($ohneRefSchicht, 1);

        $rows = (new ZasDispoBookingExport())->rows();

        $this->assertSame([[
            'id' => (int) $treffer->id, 'PNr' => 'RG17502', 'Projekt' => 'RG19801', 'Dispo' => '121000',
            'VDatum' => now()->addDay()->format('d.m.Y'),
        ]], $rows);
    }

    public function test_heute_zaehlt_noch_und_sortierung_projekt_datum_pnr(): void
    {
        $b = RecDispoEvent::create(['einsatz_ref' => 'RG19872']);
        $a = RecDispoEvent::create(['einsatz_ref' => 'RG19729']);
        $r = $this->anfrage();
        $this->ma(1, 'RG17970');
        $this->ma(2, 'RG16460');
        $heute = now()->toDateString();
        $uebermorgen = now()->addDays(2)->toDateString();
        $this->zusage($this->schicht($r, $b, $heute, '122087'), 1);
        $this->zusage($this->schicht($r, $a, $uebermorgen, '121001'), 1);
        $this->zusage($this->schicht($r, $a, $heute, '121000'), 1);
        $this->zusage($this->schicht($r, $a, $heute, '120999'), 2);

        $rows = (new ZasDispoBookingExport())->rows();

        $this->assertSame(
            ['RG19729|RG16460|120999', 'RG19729|RG17970|121000', 'RG19729|RG17970|121001', 'RG19872|RG17970|122087'],
            array_map(fn ($r) => $r['Projekt'] . '|' . $r['PNr'] . '|' . $r['Dispo'], $rows)
        );
        $this->assertSame(now()->format('d.m.Y'), $rows[0]['VDatum']);
    }

    public function test_einsatz_filter(): void
    {
        $a = RecDispoEvent::create(['einsatz_ref' => 'RG-A']);
        $b = RecDispoEvent::create(['einsatz_ref' => 'RG-B']);
        $r = $this->anfrage();
        $this->ma(1, 'RG1');
        $this->zusage($this->schicht($r, $a, now()->addDay()->toDateString()), 1);
        $this->zusage($this->schicht($r, $b, now()->addDay()->toDateString()), 1);

        $this->assertCount(2, (new ZasDispoBookingExport())->rows());
        $this->assertCount(2, (new ZasDispoBookingExport())->rows('  '));
        $rows = (new ZasDispoBookingExport())->rows('RG-B');
        $this->assertCount(1, $rows);
        $this->assertSame('RG-B', $rows[0]['Projekt']);
        $this->assertSame([], (new ZasDispoBookingExport())->rows('RG-C'));
    }

    public function test_csv_bytes_wie_olafs_beispiel(): void
    {
        $rows = [
            ['id' => 1, 'PNr' => 'RG17502', 'Projekt' => 'RG19801', 'Dispo' => '122910', 'VDatum' => '21.09.2026'],
            ['id' => 2, 'PNr' => 'RG17533', 'Projekt' => 'RG19801', 'Dispo' => '122910', 'VDatum' => '21.09.2026'],
        ];

        $csv = (new ZasDispoBookingExport())->csv($rows, new ZasCsvBuilder());

        $this->assertSame(
            "\xEF\xBB\xBF" . "PNr;Projekt;Dispo;VDatum;|;\n"
            . "RG17502;RG19801;122910;21.09.2026;;|;\n"
            . "RG17533;RG19801;122910;21.09.2026;;|;\n",
            $csv
        );
        $this->assertSame("\xEF\xBB\xBF" . "PNr;Projekt;Dispo;VDatum;|;\n", (new ZasDispoBookingExport())->csv([], new ZasCsvBuilder()));
    }

    public function test_marker_setzt_uebergeben_und_eingetragen_ohne_benutzer_und_nur_einmal(): void
    {
        $va = RecDispoEvent::create(['einsatz_ref' => 'RG19801']);
        $r = $this->anfrage();
        $this->ma(1, 'RG1');
        $this->ma(2, 'RG2');
        $s = $this->schicht($r, $va, now()->addDays(3)->toDateString());
        $z1 = $this->zusage($s, 1);
        $z2 = $this->zusage($s, 2);
        $this->assertSame(2, DispoJobOffeneZusagen::count(3), 'Vorher: beide noch in ZAS einzutragen.');

        $export = new ZasDispoBookingExport();
        $rows = $export->rows();
        $this->assertCount(2, $rows);

        // Zwischen Lesen und Markieren setzt Sheran bei z2 den Haken von Hand.
        RecDispoJobOfferShift::query()->whereKey($z2->id)->update(['zas_entered_at' => '2026-10-30 09:00:00', 'zas_entered_by_user_id' => 42]);

        $this->assertSame(2, $export->markieren(array_column($rows, 'id')));

        $z1->refresh();
        $z2->refresh();
        $this->assertNotNull($z1->zas_exported_at);
        $this->assertNotNull($z1->zas_entered_at, 'Uebergeben = in ZAS eingetragen (Spec §3).');
        $this->assertNull($z1->zas_entered_by_user_id, 'Automatik hat keinen Benutzer.');
        $this->assertNotNull($z2->zas_exported_at, 'Geliefert ist geliefert — auch wenn inzwischen der Haken von Hand kam.');
        $this->assertSame('2026-10-30 09:00:00', $z2->zas_entered_at->format('Y-m-d H:i:s'), 'Haken von Hand bleibt stehen.');
        $this->assertSame(42, (int) $z2->zas_entered_by_user_id);

        $this->assertSame([], $export->rows(), 'Zweiter Abruf liefert nichts mehr.');
        $this->assertSame(0, $export->markieren([$z1->id, $z2->id, 999999]), 'Nochmal markieren setzt nichts.');
        $this->assertSame(0, $export->markieren([]));
        $this->assertSame(0, DispoJobOffeneZusagen::count(3), 'Uebergebene zaehlen nicht mehr als offen (Sidebar-Badge).');
    }

    public function test_haken_von_hand_wegnehmen_fuehrt_nicht_zur_zweiten_uebergabe(): void
    {
        $va = RecDispoEvent::create(['einsatz_ref' => 'RG19801']);
        $r = $this->anfrage();
        $this->ma(1, 'RG1');
        $z = $this->zusage($this->schicht($r, $va, now()->addDays(3)->toDateString()), 1);
        $export = new ZasDispoBookingExport();
        $export->markieren(array_column($export->rows(), 'id'));

        RecDispoJobOfferShift::query()->whereKey($z->id)->update(['zas_entered_at' => null]);

        $this->assertSame([], $export->rows());
    }
}
