<?php

namespace Platform\Recruiting\Tests\Integration;

use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Models\RecZasDispoInboundFile;
use Platform\Recruiting\Services\Zas\Dispo\DispoEmployeeDirectory;
use Platform\Recruiting\Services\Zas\Dispo\DispoReconfirmMarker;
use Platform\Recruiting\Services\Zas\Dispo\ZasDispoBlockSplitter;
use Platform\Recruiting\Services\Zas\Dispo\ZasDispoImportPlanner;
use Platform\Recruiting\Services\Zas\Dispo\ZasDispoWebexportImporter;

/**
 * Bedarfsliste (Spec 2026-10-29): der Importer legt je Dispoposten einen
 * Datensatz in rec_dispo_bedarfe an, aktualisiert ihn bei jeder Lieferung,
 * markiert verschwundene Posten und nimmt sie beim Wiederauftauchen zurueck.
 *
 * GEGEN DEN ECHTEN IMPORTER mit allen Migrationen (Muster
 * DispoImportErinnerungsStempelTest): ein handgebautes Schema koennte die
 * neue Tabelle anders bauen als die Migration.
 */
final class DispoImportBedarfeTest extends TestCase
{
    private Capsule $capsule;

    private string $dateiInhalt = '';

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-29 12:00:00');

        $container = Container::getInstance();
        Container::setInstance($container);
        $container->instance('config', new ConfigRepository([
            'recruiting' => ['zas' => ['company_prefix' => '']],
        ]));
        $container->instance('log', new class {
            public function __call($m, $a) {}
        });

        $test = $this;
        $container->instance('filesystem', new class($test) {
            public function __construct(private object $test) {}

            public function disk($name = null): object
            {
                return new class($this->test) {
                    public function __construct(private object $test) {}

                    public function get(string $pfad): string
                    {
                        return $this->test->dateiInhaltLesen();
                    }
                };
            }
        });

        $this->capsule = new Capsule($container);
        $this->capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $this->capsule->setEventDispatcher(new Dispatcher($container));
        $this->capsule->setAsGlobal();
        $this->capsule->bootEloquent();
        Model::clearBootedModels();

        $container->instance('db', $this->capsule->getDatabaseManager());
        $container->instance('db.schema', $this->capsule->getConnection()->getSchemaBuilder());
        Facade::setFacadeApplication($container);
        Facade::clearResolvedInstances();

        $this->migrationenFahren();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        Model::unsetConnectionResolver();
        Model::clearBootedModels();
        $container = Container::getInstance();
        foreach (['config', 'log', 'filesystem', 'db', 'db.schema'] as $name) {
            $container->forgetInstance($name);
        }
        Facade::clearResolvedInstances();
        parent::tearDown();
    }

    public function dateiInhaltLesen(): string
    {
        return $this->dateiInhalt;
    }

    /** Die VA aus dem Anlassfall: Posten ohne Person muessen bei uns ankommen. */
    public function test_bedarfe_werden_je_posten_angelegt_auch_ohne_person(): void
    {
        $z = $this->importiere([
            self::bedarf('126159', '03.11.2026', 'Servicekräfte', '14:00', '23:30', 6),
            self::bedarf('126158', '03.11.2026', 'Servicekräfte', '07:30', '14:00', 1),
            self::bedarf('120988', '14.09.2026', 'Broich Cateringhilfe', '08:45', '16:45', 2),
            self::bedarf('120988', '14.09.2026', 'Broich Cateringhilfe', '08:30', '16:45', 2),
        ], personen: ['933770' => ['03.11.2026', '07:30', '14:00', 'Servicekräfte']]);

        $this->assertSame(3, $z['bedarfe_created']);
        $this->assertSame(0, $z['bedarfe_updated']);
        $this->assertSame(0, $z['bedarfe_missing_marked']);

        $rows = DB::table('rec_dispo_bedarfe')->orderBy('dispoposten_id')->get();
        $this->assertSame(['120988', '126158', '126159'], $rows->pluck('dispoposten_id')->map(fn ($v) => (string) $v)->all());

        $posten = $rows->firstWhere('dispoposten_id', '126159');
        $this->assertSame('2026-11-03', substr((string) $posten->datum, 0, 10));
        $this->assertSame('14:00', $posten->von);
        $this->assertSame('23:30', $posten->bis);
        $this->assertSame('Servicekräfte', $posten->taetigkeit);
        $this->assertSame('RG78', $posten->taetigkeit_id);
        $this->assertSame(6, (int) $posten->anzahl);
        $this->assertSame(1, (int) $posten->status_id);
        $this->assertNull($posten->missing_since);
        $this->assertNotNull($posten->last_seen_at);
        $this->assertSame(
            (int) DB::table('rec_dispo_events')->where('einsatz_ref', 'RG20407')->value('id'),
            (int) $posten->rec_dispo_event_id,
        );

        $varianten = $rows->firstWhere('dispoposten_id', '120988');
        $this->assertSame('08:30', $varianten->von, 'frueheste Zeitvariante');
        $this->assertSame([['von' => '08:30', 'bis' => '16:45'], ['von' => '08:45', 'bis' => '16:45']], json_decode((string) $varianten->zeiten, true));
        $this->assertSame(2, (int) $varianten->anzahl, 'Anzahl je Posten, nicht je Zeile');
    }

    public function test_zweite_lieferung_aktualisiert_statt_zu_verdoppeln(): void
    {
        $this->importiere([self::bedarf('126159', '03.11.2026', 'Servicekräfte', '14:00', '23:30', 6)]);
        $z = $this->importiere([self::bedarf('126159', '03.11.2026', 'Servicekräfte', '14:00', '23:30', 8)]);

        $this->assertSame(0, $z['bedarfe_created']);
        $this->assertSame(1, $z['bedarfe_updated']);
        $this->assertSame(1, DB::table('rec_dispo_bedarfe')->count());
        $this->assertSame(8, (int) DB::table('rec_dispo_bedarfe')->value('anzahl'));
    }

    public function test_verschwundener_posten_wird_markiert_und_kommt_zurueck(): void
    {
        $this->importiere([
            self::bedarf('126159', '03.11.2026', 'Servicekräfte', '14:00', '23:30', 6),
            self::bedarf('126158', '03.11.2026', 'Servicekräfte', '07:30', '14:00', 1),
            self::bedarf('100001', '01.10.2026', 'Vergangen', '07:30', '14:00', 1),
        ]);

        $z = $this->importiere([self::bedarf('126158', '03.11.2026', 'Servicekräfte', '07:30', '14:00', 1)]);

        $this->assertSame(1, $z['bedarfe_missing_marked'], 'nur der zukuenftige Posten, der fehlt');
        $this->assertNotNull(DB::table('rec_dispo_bedarfe')->where('dispoposten_id', '126159')->value('missing_since'));
        $this->assertNull(DB::table('rec_dispo_bedarfe')->where('dispoposten_id', '126158')->value('missing_since'));
        $this->assertNull(DB::table('rec_dispo_bedarfe')->where('dispoposten_id', '100001')->value('missing_since'), 'Vergangenheit wird nie als verschwunden markiert');

        $z = $this->importiere([
            self::bedarf('126159', '03.11.2026', 'Servicekräfte', '14:00', '23:30', 6),
            self::bedarf('126158', '03.11.2026', 'Servicekräfte', '07:30', '14:00', 1),
        ]);
        $this->assertSame(0, $z['bedarfe_missing_marked']);
        $this->assertNull(DB::table('rec_dispo_bedarfe')->where('dispoposten_id', '126159')->value('missing_since'), 'wieder da');
    }

    /** Eine Lieferung ohne Bedarfsliste darf den Bestand nicht als verschwunden markieren. */
    public function test_lieferung_ohne_dispo2_laesst_bedarfe_in_ruhe(): void
    {
        $this->importiere([self::bedarf('126159', '03.11.2026', 'Servicekräfte', '14:00', '23:30', 6)]);

        $z = $this->importiere([], personen: ['933770' => ['03.11.2026', '07:30', '14:00', 'Servicekräfte']], mitDispo2: false);

        $this->assertSame(0, $z['bedarfe_missing_marked']);
        $this->assertNull(DB::table('rec_dispo_bedarfe')->where('dispoposten_id', '126159')->value('missing_since'));
    }

    public function test_trockenlauf_zaehlt_und_schreibt_nicht(): void
    {
        $this->importiere([self::bedarf('126159', '03.11.2026', 'Servicekräfte', '14:00', '23:30', 6)]);

        $z = $this->importiere([self::bedarf('126158', '03.11.2026', 'Servicekräfte', '07:30', '14:00', 1)], dryRun: true);

        $this->assertSame(1, $z['bedarfe_created']);
        $this->assertSame(1, $z['bedarfe_missing_marked']);
        $this->assertSame(1, DB::table('rec_dispo_bedarfe')->count(), 'nichts geschrieben');
        $this->assertNull(DB::table('rec_dispo_bedarfe')->where('dispoposten_id', '126159')->value('missing_since'));
    }

    /** Die Posten-Nummer muss als Text in die Abfragen (ds_ref-Hotfix 22.10., gleiche Falle). */
    public function test_posten_nummern_gehen_als_text_in_die_abfrage(): void
    {
        $this->importiere([self::bedarf('126159', '03.11.2026', 'Servicekräfte', '14:00', '23:30', 6)]);
        $bindungen = [];
        DB::listen(function ($q) use (&$bindungen) {
            if (str_contains($q->sql, 'rec_dispo_bedarfe')) {
                $bindungen = array_merge($bindungen, array_filter($q->bindings, 'is_int'));
            }
        });

        $this->importiere([self::bedarf('126158', '03.11.2026', 'Servicekräfte', '07:30', '14:00', 1)]);

        // anzahl/status_id sind legitime ints; die Posten-Nummern 126158/126159 duerfen nie als int gebunden sein.
        $this->assertSame([], array_values(array_filter($bindungen, fn ($b) => $b >= 100000)), 'dispoposten_id muss als Text gebunden werden');
    }

    // -----------------------------------------------------------------

    /** @return array{0:string,1:string,2:string,3:string,4:string,5:int} */
    private static function bedarf(string $posten, string $datum, string $taet, string $von, string $bis, int $anzahl): array
    {
        return [$posten, $datum, $taet, $von, $bis, $anzahl];
    }

    /**
     * @param list<array{0:string,1:string,2:string,3:string,4:string,5:int}> $bedarfe
     * @param array<string, array{0:string,1:string,2:string,3:string}> $personen ds_id => [datum, von, bis, taetigkeit]
     */
    private function importiere(array $bedarfe, array $personen = [], bool $dryRun = false, bool $mitDispo2 = true): array
    {
        $zeilen = [];
        if ($mitDispo2) {
            $zeilen[] = '{Dispo2}';
            // datum;text;einsatz_id;taetigkeit_von_bis;anzahl;dispoposten_id;projektbezeichnung;filiale;filial_nr;
            // taetigk_id;von;bis;ort;einsatzfirma;mitarbeiter_info;status_id;taetigkeit;interne_bem;id_firma
            foreach ($bedarfe as [$posten, $datum, $taet, $von, $bis, $anzahl]) {
                $zeilen[] = "{$datum};Halle;RG20407;{$taet} {$von}-{$bis};{$anzahl};{$posten};Broich Connected;DUS;100;RG78;{$von};{$bis};;BHG;;1;{$taet};;1";
            }
        }
        $zeilen[] = '{Dispo}';
        foreach ($personen as $dsId => [$datum, $von, $bis, $taet]) {
            // datum;einsatzfirma_kurz;pnr;einsatz_id;ze;tlp_nr;ds_id;von;bis;status_id;essengeld;taetigkeit;tlp_nr2;verrechnungssatz
            $zeilen[] = "{$datum};BHG;RG18424;RG20407;1;;{$dsId};{$von};{$bis};1;0;{$taet};;27,49";
        }
        $this->dateiInhalt = implode("\n", $zeilen) . "\n";

        $datei = RecZasDispoInboundFile::create([
            'source'       => 'test',
            'disk'         => 'local',
            'stored_path'  => 'zas/dispo/test.csv',
            'parse_status' => 'viewable',
        ]);

        return (new ZasDispoWebexportImporter(
            new ZasDispoBlockSplitter(),
            new ZasDispoImportPlanner(),
            new DispoEmployeeDirectory(),
            new DispoReconfirmMarker(),
        ))->import($datei, $dryRun);
    }

    private function migrationenFahren(): void
    {
        $dateien = glob(dirname(__DIR__, 2) . '/database/migrations/*.php');
        sort($dateien);
        foreach ($dateien as $datei) {
            try {
                (require $datei)->up();
            } catch (\Throwable) {
                // welche Migration hier nicht laufen kann, haelt MassenzuweisungGeschlosseneWeltTest fest
            }
        }
    }
}
