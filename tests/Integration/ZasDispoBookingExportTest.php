<?php

namespace Platform\Recruiting\Tests\Integration;

use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Console\Commands\DispoBuchungenErneutCommand;
use Platform\Recruiting\Models\RecDispoEvent;
use Platform\Recruiting\Models\RecDispoJobOffer;
use Platform\Recruiting\Models\RecDispoJobOfferShift;
use Platform\Recruiting\Models\RecDispoJobRequest;
use Platform\Recruiting\Models\RecDispoJobShift;
use Platform\Recruiting\Services\Zas\Dispo\Jobs\DispoJobOffeneZusagen;
use Platform\Recruiting\Services\Zas\Dispo\ZasDispoBookingExport;
use Platform\Recruiting\Services\Zas\ZasCsvBuilder;

/** Log-Attrappe: der Abruf schreibt eine Logzeile je Uebergabe. */
class BookingExportLog
{
    /** @var list<array{0:string,1:array}> */
    public static array $zeilen = [];
    public function info(string $m, array $c = []): void { self::$zeilen[] = [$m, $c]; }
    public function warning(string $m, array $c = []): void { self::$zeilen[] = [$m, $c]; }
    public function error(string $m, array $c = []): void { self::$zeilen[] = [$m, $c]; }
}

/**
 * Zusagen als Buchungen an ZAS (Spec 2026-10-30 §1–§3): Auswahlregeln je
 * Bedingung, Olafs Zeilenformat byteweise, Beanspruchen vor dem Liefern,
 * Schwester-Zusagen als eine Buchung, Freigabe fuer den erneuten Abruf.
 */
class ZasDispoBookingExportTest extends TestCase
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
        Container::getInstance()->forgetInstance('log');
        Facade::clearResolvedInstances();
    }

    protected function setUp(): void
    {
        foreach (['rec_dispo_job_offer_shifts', 'rec_dispo_job_offers', 'rec_dispo_job_waves', 'rec_dispo_job_shifts', 'rec_dispo_job_requests', 'rec_dispo_events', 'rec_employees'] as $t) {
            Capsule::table($t)->delete();
        }
        BookingExportLog::$zeilen = [];
        Container::getInstance()->instance('log', new BookingExportLog());
        Facade::clearResolvedInstance('log');
        Container::getInstance()->instance('config', new ConfigRepository(['recruiting' => ['zas' => []]]));
        Facade::clearResolvedInstance('config');
    }

    private function ma(int $id, ?string $pnr, bool $test = false): int
    {
        Capsule::table('rec_employees')->insert([
            'id' => $id, 'uuid' => 'u-' . $id, 'team_id' => 3, 'first_name' => 'E' . $id, 'last_name' => 'M', 'is_active' => true,
            'personnel_number' => $pnr, 'is_test' => $test, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    }

    private function anfrage(string $status = RecDispoJobRequest::STATUS_LAEUFT, int $team = 3): RecDispoJobRequest
    {
        $r = RecDispoJobRequest::create(['team_id' => $team, 'filial_nr' => 200, 'zeitraum_von' => '2026-10-20', 'zeitraum_bis' => '2099-11-17',
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
        $nurLeerzeichen = RecDispoEvent::create(['einsatz_ref' => '  ', 'name' => 'Leerzeichen']);
        $r = $this->anfrage(RecDispoJobRequest::STATUS_ABGESCHLOSSEN); // Anfrage-Status ist egal
        $morgen = now()->addDay()->toDateString();
        $gut = $this->schicht($r, $va, $morgen);
        $ohnePosten = $this->schicht($r, $va, $morgen, null);
        $leerPosten = $this->schicht($r, $va, $morgen, '');
        $leerzeichenPosten = $this->schicht($r, $va, $morgen, '   ');
        $vorbei = $this->schicht($r, $va, now()->subDay()->toDateString());
        $ohneRefSchicht = $this->schicht($r, $ohneRef, $morgen, '121001');
        $leerzeichenRefSchicht = $this->schicht($r, $nurLeerzeichen, $morgen, '121002');

        $this->ma(1, 'RG17502');
        $this->ma(2, '');
        $this->ma(3, null);
        $this->ma(4, 'RG99999', test: true);
        $this->ma(5, 'RG17533');
        $this->ma(6, 'RG17600');
        $this->ma(7, 'RG17700');
        $this->ma(8, 'RG17800');
        $this->ma(9, 'RG17900');
        $this->ma(10, '   ');
        $this->ma(11, '17502');          // ohne Firmenkennung: RG oder MA? bleibt Handarbeit
        $this->ma(12, ' RG17512 ');      // Leerzeichen drumherum sind ok

        $treffer = $this->zusage($gut, 1);
        $this->zusage($gut, 2);                                              // keine Personalnummer
        $this->zusage($gut, 3);                                              // Personalnummer null
        $this->zusage($gut, 4);                                              // Test-MA
        $this->zusage($gut, 5, ['zas_entered_at' => now()]);                 // von Hand eingetragen
        $this->zusage($gut, 6, ['zas_assignment_id' => 77]);                 // schon angekommen
        $this->zusage($gut, 7, ['zas_exported_at' => now()]);                // schon uebergeben
        $this->zusage($gut, 8, ['accepted_at' => null]);                     // nie zugesagt
        $this->zusage($gut, 9, ['declined_at' => now()]);                    // abgesagt
        $this->zusage($gut, 10);                                             // nur Leerzeichen
        $this->zusage($gut, 11);                                             // ohne Praefix
        $getrimmt = $this->zusage($gut, 12);
        $this->zusage($ohnePosten, 1);
        $this->zusage($leerPosten, 1);
        $this->zusage($leerzeichenPosten, 1);
        $this->zusage($vorbei, 1);
        $this->zusage($ohneRefSchicht, 1);
        $this->zusage($leerzeichenRefSchicht, 1);
        // Angebot auf einen Mitarbeiter, den es nicht (mehr) gibt.
        $this->zusage($gut, 999);

        $rows = (new ZasDispoBookingExport())->rows();

        $this->assertSame([
            ['id' => (int) $treffer->id, 'PNr' => 'RG17502', 'Projekt' => 'RG19801', 'Dispo' => '121000', 'VDatum' => now()->addDay()->format('d.m.Y'), 'ids' => [(int) $treffer->id]],
            ['id' => (int) $getrimmt->id, 'PNr' => 'RG17512', 'Projekt' => 'RG19801', 'Dispo' => '121000', 'VDatum' => now()->addDay()->format('d.m.Y'), 'ids' => [(int) $getrimmt->id]],
        ], $rows);
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

    public function test_einsatz_filter_und_team_grenze(): void
    {
        $a = RecDispoEvent::create(['einsatz_ref' => 'RG-A']);
        $b = RecDispoEvent::create(['einsatz_ref' => 'RG-B']);
        $r = $this->anfrage();
        $fremd = $this->anfrage(team: 9);
        $this->ma(1, 'RG1');
        // Posten-Nummern sind in ZAS eindeutig — je VA eine andere, sonst gaelte es als dieselbe Buchung.
        $this->zusage($this->schicht($r, $a, now()->addDay()->toDateString(), '100'), 1);
        $this->zusage($this->schicht($r, $b, now()->addDay()->toDateString(), '200'), 1);
        $this->zusage($this->schicht($fremd, $b, now()->addDays(2)->toDateString(), '555'), 1);

        $this->assertCount(3, (new ZasDispoBookingExport())->rows(), 'Ohne inbound_team_id: alle Teams.');
        $this->assertCount(3, (new ZasDispoBookingExport())->rows('  '));
        $rows = (new ZasDispoBookingExport())->rows('RG-B');
        $this->assertCount(2, $rows);
        $this->assertSame('RG-B', $rows[0]['Projekt']);
        $this->assertSame([], (new ZasDispoBookingExport())->rows('RG-C'));

        Container::getInstance()->instance('config', new ConfigRepository(['recruiting' => ['zas' => ['inbound_team_id' => 3]]]));
        Facade::clearResolvedInstance('config');
        $this->assertCount(2, (new ZasDispoBookingExport())->rows(), 'Mit inbound_team_id: nur das Dispo-Team.');
    }

    public function test_csv_bytes_wie_olafs_beispiel(): void
    {
        $rows = [
            ['id' => 1, 'ids' => [1], 'PNr' => 'RG17502', 'Projekt' => 'RG19801', 'Dispo' => '122910', 'VDatum' => '21.09.2026'],
            ['id' => 2, 'ids' => [2, 3], 'PNr' => 'RG17533', 'Projekt' => 'RG19801', 'Dispo' => '122910', 'VDatum' => '21.09.2026'],
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

    public function test_beanspruchen_setzt_uebergeben_und_eingetragen_ohne_benutzer_und_nur_einmal(): void
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
        $ermittelt = $export->ermitteln();
        $this->assertCount(2, $ermittelt['rows']);
        $this->assertSame([], $ermittelt['abgedeckt']);

        // Zwischen Lesen und Beanspruchen setzt Sheran bei z2 den Haken von Hand.
        RecDispoJobOfferShift::query()->whereKey($z2->id)->update(['zas_entered_at' => '2026-10-30 09:00:00', 'zas_entered_by_user_id' => 42]);

        $geliefert = $export->beanspruchen($ermittelt);
        $this->assertSame([(int) $z1->id, (int) $z2->id], array_column($geliefert, 'id'));

        $z1->refresh();
        $z2->refresh();
        $this->assertNotNull($z1->zas_exported_at);
        $this->assertNotNull($z1->zas_entered_at, 'Uebergeben = in ZAS eingetragen (Spec §3).');
        $this->assertNull($z1->zas_entered_by_user_id, 'Automatik hat keinen Benutzer.');
        $this->assertNotNull($z2->zas_exported_at, 'Geliefert ist geliefert — auch wenn inzwischen der Haken von Hand kam.');
        $this->assertSame('2026-10-30 09:00:00', $z2->zas_entered_at->format('Y-m-d H:i:s'), 'Haken von Hand bleibt stehen.');
        $this->assertSame(42, (int) $z2->zas_entered_by_user_id);

        $this->assertSame([], $export->rows(), 'Zweiter Abruf liefert nichts mehr.');
        $this->assertSame([], $export->beanspruchen($ermittelt), 'Dieselbe Ermittlung nochmal beanspruchen liefert nichts.');
        $this->assertSame([], $export->beanspruchen(['rows' => [], 'abgedeckt' => []]));
        $this->assertSame(0, DispoJobOffeneZusagen::count(3), 'Uebergebene zaehlen nicht mehr als offen (Sidebar-Badge).');

        $this->assertCount(1, BookingExportLog::$zeilen, 'Eine Logzeile je Uebergabe, keine fuer leere Laeufe.');
        $this->assertSame([(int) $z1->id, (int) $z2->id], BookingExportLog::$zeilen[0][1]['ids']);
    }

    public function test_zwei_parallele_abrufe_liefern_jede_zeile_nur_einmal(): void
    {
        $va = RecDispoEvent::create(['einsatz_ref' => 'RG19801']);
        $r = $this->anfrage();
        $this->ma(1, 'RG1');
        $this->ma(2, 'RG2');
        $s = $this->schicht($r, $va, now()->addDays(3)->toDateString());
        $this->zusage($s, 1);
        $this->zusage($s, 2);
        $export = new ZasDispoBookingExport();

        // Beide Abrufe lesen denselben Stand, bevor einer markiert hat.
        $abrufA = $export->ermitteln();
        $abrufB = $export->ermitteln();
        $this->assertCount(2, $abrufA['rows']);
        $this->assertCount(2, $abrufB['rows']);

        $geliefertA = $export->beanspruchen($abrufA);
        $geliefertB = $export->beanspruchen($abrufB);

        $this->assertCount(2, $geliefertA);
        $this->assertSame([], $geliefertB, 'Der zweite Abruf bekommt nichts, was der erste schon beansprucht hat.');
    }

    public function test_schwester_zusagen_sind_eine_buchung_und_abgedeckte_werden_nicht_geliefert(): void
    {
        $va = RecDispoEvent::create(['einsatz_ref' => 'RG19801']);
        $tag = now()->addDays(3)->toDateString();
        $r1 = $this->anfrage();
        $r2 = $this->anfrage();
        $this->ma(1, 'RG1');
        $this->ma(2, 'RG2');
        // Dieselbe Schicht (VA, Tag, Posten) in zwei Anfragen; JobOffersZusage sagt beiden zu.
        $a = $this->zusage($this->schicht($r1, $va, $tag), 1);
        $b = $this->zusage($this->schicht($r2, $va, $tag), 1);
        // Person 2: in Anfrage 1 von Hand eingetragen, in Anfrage 2 noch ohne Haken -> nicht liefern (Dublette)!
        $this->zusage($this->schicht($r1, $va, $tag, '121001'), 2, ['zas_entered_at' => now()]);
        $c = $this->zusage($this->schicht($r2, $va, $tag, '121001'), 2);
        // Person 1 an einem anderen Tag: eigene Buchung.
        $d = $this->zusage($this->schicht($r2, $va, now()->addDays(4)->toDateString()), 1);

        $export = new ZasDispoBookingExport();
        $ermittelt = $export->ermitteln();

        $this->assertSame([(int) $a->id, (int) $d->id], array_column($ermittelt['rows'], 'id'));
        $this->assertSame([(int) $a->id, (int) $b->id], $ermittelt['rows'][0]['ids'], 'Schwester haengt an der fuehrenden Zeile.');
        $this->assertSame([(int) $c->id], $ermittelt['abgedeckt']);

        $geliefert = $export->beanspruchen($ermittelt);
        $this->assertCount(2, $geliefert);
        foreach ([$a, $b] as $z) {
            $z->refresh();
            $this->assertNotNull($z->zas_exported_at, 'Beide Schwestern sind uebergeben.');
            $this->assertNotNull($z->zas_entered_at);
        }
        $c->refresh();
        $this->assertNull($c->zas_exported_at, 'Abgedeckte Zeile wurde nicht geliefert.');
        $this->assertNotNull($c->zas_entered_at, 'Aber sie gilt als eingetragen (ist ueber die Schwester in ZAS).');
        $this->assertSame([], $export->rows());
    }

    public function test_haken_von_hand_wegnehmen_fuehrt_nicht_zur_zweiten_uebergabe(): void
    {
        $va = RecDispoEvent::create(['einsatz_ref' => 'RG19801']);
        $r = $this->anfrage();
        $this->ma(1, 'RG1');
        $z = $this->zusage($this->schicht($r, $va, now()->addDays(3)->toDateString()), 1);
        $export = new ZasDispoBookingExport();
        $export->beanspruchen($export->ermitteln());

        RecDispoJobOfferShift::query()->whereKey($z->id)->update(['zas_entered_at' => null]);

        $this->assertSame([], $export->rows());
    }

    public function test_dry_run_erkennung(): void
    {
        $this->assertFalse(ZasDispoBookingExport::dryRun([]));
        $this->assertFalse(ZasDispoBookingExport::dryRun(['einsatz' => 'RG1']));
        $this->assertTrue(ZasDispoBookingExport::dryRun(['dry_run' => '']), '?dry_run ohne Wert ist ein Trockenlauf.');
        $this->assertTrue(ZasDispoBookingExport::dryRun(['dry_run' => null]));
        $this->assertTrue(ZasDispoBookingExport::dryRun(['dry_run' => '1']));
        $this->assertTrue(ZasDispoBookingExport::dryRun(['dry_run' => 'true']));
        $this->assertTrue(ZasDispoBookingExport::dryRun(['dry_run' => 'ja']));
        $this->assertTrue(ZasDispoBookingExport::dryRun(['dry_run' => ['1']]));
        foreach (['0', 'false', 'no', 'off', 'nein', ' FALSE '] as $nein) {
            $this->assertFalse(ZasDispoBookingExport::dryRun(['dry_run' => $nein]), $nein);
        }
    }

    public function test_freigeben_fuer_erneuten_abruf(): void
    {
        $va = RecDispoEvent::create(['einsatz_ref' => 'RG19801']);
        $r = $this->anfrage();
        $this->ma(1, 'RG1');
        $this->ma(2, 'RG2');
        $this->ma(3, 'RG3');
        $s = $this->schicht($r, $va, now()->addDays(3)->toDateString());
        $auto = $this->zusage($s, 1);
        $hand = $this->zusage($s, 2);
        $angekommen = $this->zusage($s, 3);
        $export = new ZasDispoBookingExport();
        $export->beanspruchen($export->ermitteln());
        RecDispoJobOfferShift::query()->whereKey($hand->id)->update(['zas_entered_by_user_id' => 42]);
        RecDispoJobOfferShift::query()->whereKey($angekommen->id)->update(['zas_assignment_id' => 7]);
        $this->assertSame([], $export->rows());

        $this->assertSame(['rows' => [], 'freigegeben' => 0], DispoBuchungenErneutCommand::freigeben([], null, false), 'Ohne Eingrenzung passiert nichts.');

        $probe = DispoBuchungenErneutCommand::freigeben([], now()->subMinute()->format('Y-m-d H:i'), true);
        $this->assertSame([(int) $auto->id, (int) $hand->id], array_column($probe['rows'], 'id'), 'Angekommene werden nie freigegeben.');
        $this->assertSame([false, true], array_column($probe['rows'], 'haken_von_hand'));
        $this->assertSame(0, $probe['freigegeben']);
        $this->assertSame([], $export->rows(), 'Probelauf schreibt nichts.');

        $echt = DispoBuchungenErneutCommand::freigeben([(int) $auto->id, (int) $hand->id, (int) $angekommen->id], null, false);
        $this->assertSame(2, $echt['freigegeben']);
        $auto->refresh();
        $hand->refresh();
        $angekommen->refresh();
        $this->assertNull($auto->zas_exported_at);
        $this->assertNull($auto->zas_entered_at, 'Automatik-Haken geht mit weg.');
        $this->assertNull($hand->zas_exported_at);
        $this->assertNotNull($hand->zas_entered_at, 'Haken von Hand bleibt — die Zeile geht deshalb auch nicht erneut raus.');
        $this->assertNotNull($angekommen->zas_exported_at);

        $this->assertSame([(int) $auto->id], array_column($export->rows(), 'id'), 'Nur die freigegebene Automatik-Zeile kommt wieder.');
    }
}
