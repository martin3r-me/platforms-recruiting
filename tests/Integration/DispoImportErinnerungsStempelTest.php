<?php

namespace Platform\Recruiting\Tests\Integration;

use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Events\Dispatcher;
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
 * ET-18 — DER TOTE STEMPEL.
 *
 * Verschwindet eine Einbuchung aus der ZAS-Lieferung und taucht spaeter
 * wieder auf, setzt der Importer `missing_since` zurueck.
 * `aufgaben_erinnert_at` blieb dabei stehen — und die Wiederholungsbremse
 * des Einsatz-Triggers unterdrueckte damit eine voellig berechtigte
 * Erinnerung, dauerhaft und ohne Spur. Seit dieser Runde raeumt der
 * Importer den Stempel beim Wiederauftauchen mit.
 *
 * GEGEN DEN ECHTEN IMPORTER, nicht gegen eine Quelltext-Zusicherung: der
 * Lauf geht durch Splitter, Planner, Matcher und die echte Transaktion. Nur
 * die Datei selbst kommt aus einer Attrappe des Speichers, weil sie sonst
 * auf einer Platte liegen muesste.
 *
 * DIE WELT KOMMT AUS DEN MIGRATIONEN (Muster MassenzuweisungGeschlosseneWelt
 * und TriggerStateSchemaTest): ein handgebautes Schema koennte
 * `aufgaben_erinnert_at` vergessen, und SQLite macht aus einem fehlenden
 * Spaltennamen kein Fehler, sondern ein String-Literal — der Test waere
 * gruen und wertlos.
 */
final class DispoImportErinnerungsStempelTest extends TestCase
{
    private const GESTEMPELT = '2026-09-28 08:00:00';

    private const VERSCHWUNDEN = '2026-09-29 03:00:00';

    private Capsule $capsule;

    private string $dateiInhalt = '';

    protected function setUp(): void
    {
        parent::setUp();

        $container = Container::getInstance();
        Container::setInstance($container);
        $container->instance('config', new ConfigRepository([
            'recruiting' => ['zas' => ['company_prefix' => '']],
        ]));
        $container->instance('log', new class {
            public function __call($m, $a) {}
        });

        // Der Speicher als Attrappe: Storage::disk(...)->get(...) liefert
        // genau den Dateiinhalt, den der jeweilige Test gesetzt hat.
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
        Model::unsetConnectionResolver();
        Model::clearBootedModels();
        $container = Container::getInstance();
        foreach (['config', 'log', 'filesystem', 'db', 'db.schema'] as $name) {
            $container->forgetInstance($name);
        }
        Facade::clearResolvedInstances();
        parent::tearDown();
    }

    /** Von der Speicher-Attrappe aufgerufen. */
    public function dateiInhaltLesen(): string
    {
        return $this->dateiInhalt;
    }

    /**
     * Die Einbuchung war verschwunden und ist wieder da: der Stempel muss
     * weg, sonst bekommt dieser Mensch nie wieder eine Erinnerung zu diesem
     * Einsatz.
     */
    public function test_eine_wieder_aufgetauchte_einbuchung_verliert_ihren_stempel(): void
    {
        $this->einbuchungAnlegen('DS-1', [
            'missing_since'        => self::VERSCHWUNDEN,
            'aufgaben_erinnert_at' => self::GESTEMPELT,
        ]);

        $zusammenfassung = $this->importiere(['DS-1']);

        $zeile = DB::table('rec_dispo_assignments')->where('ds_ref', 'DS-1')->first();

        $this->assertNull($zeile->missing_since, 'Vorflug: der Importer hat die Zeile wirklich wieder aufgenommen.');
        $this->assertNull($zeile->aufgaben_erinnert_at, 'ET-18: der Stempel muss mit.');
        $this->assertSame(1, $zusammenfassung['erinnerung_entstempelt']);
    }

    /**
     * DIE GEGENRICHTUNG, und sie traegt die ganze Auflage: eine Einbuchung,
     * die NIE verschwunden war, behaelt ihren Stempel. Ohne diese Bedingung
     * raeumte JEDE Lieferung jeden Stempel ab — der Import laeuft stuendlich,
     * und aus der Wiederholungsbremse wuerde eine Spam-Maschine.
     */
    public function test_eine_durchgehend_gelieferte_einbuchung_behaelt_ihren_stempel(): void
    {
        $this->einbuchungAnlegen('DS-1', [
            'missing_since'        => null,
            'aufgaben_erinnert_at' => self::GESTEMPELT,
        ]);

        $zusammenfassung = $this->importiere(['DS-1']);

        $zeile = DB::table('rec_dispo_assignments')->where('ds_ref', 'DS-1')->first();

        $this->assertSame(self::GESTEMPELT, (string) $zeile->aufgaben_erinnert_at);
        $this->assertSame(0, $zusammenfassung['erinnerung_entstempelt']);
    }

    /**
     * Und eine Einbuchung, die in DIESER Lieferung gar nicht vorkommt, wird
     * nicht angefasst — auch dann nicht, wenn sie verschwunden ist und einen
     * Stempel traegt. Sie ist ja gerade NICHT wieder aufgetaucht.
     */
    public function test_eine_fremde_einbuchung_bleibt_unberuehrt(): void
    {
        $this->einbuchungAnlegen('DS-1', [
            'missing_since'        => self::VERSCHWUNDEN,
            'aufgaben_erinnert_at' => self::GESTEMPELT,
        ]);
        $this->einbuchungAnlegen('DS-2', [
            'missing_since'        => self::VERSCHWUNDEN,
            'aufgaben_erinnert_at' => self::GESTEMPELT,
        ]);

        $this->importiere(['DS-1']);

        $this->assertNull(DB::table('rec_dispo_assignments')->where('ds_ref', 'DS-1')->value('aufgaben_erinnert_at'));
        $this->assertSame(
            self::GESTEMPELT,
            (string) DB::table('rec_dispo_assignments')->where('ds_ref', 'DS-2')->value('aufgaben_erinnert_at'),
            'DS-2 kam in dieser Lieferung nicht vor und ist damit nicht wieder aufgetaucht.',
        );
    }

    /** Der Trockenlauf zaehlt, schreibt aber nicht. */
    public function test_der_trockenlauf_zaehlt_den_stempel_und_raeumt_ihn_nicht(): void
    {
        $this->einbuchungAnlegen('DS-1', [
            'missing_since'        => self::VERSCHWUNDEN,
            'aufgaben_erinnert_at' => self::GESTEMPELT,
        ]);

        $zusammenfassung = $this->importiere(['DS-1'], dryRun: true);

        $this->assertSame(1, $zusammenfassung['erinnerung_entstempelt']);
        $this->assertSame(
            self::GESTEMPELT,
            (string) DB::table('rec_dispo_assignments')->where('ds_ref', 'DS-1')->value('aufgaben_erinnert_at'),
        );
    }

    // -----------------------------------------------------------------

    /**
     * @param  list<string>  $dsRefs  Welche Einbuchungen diese Lieferung enthaelt
     * @return array<string, mixed>
     */
    private function importiere(array $dsRefs, bool $dryRun = false): array
    {
        $this->dateiInhalt = $this->lieferung($dsRefs);

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

    /**
     * Eine Webexport-Datei im echten Format (Spaltenfolge aus
     * ZasDispoBlockSplitter::COLUMNS, keine Kopfzeile, Semikolon).
     *
     * @param  list<string>  $dsRefs
     */
    private function lieferung(array $dsRefs): string
    {
        $zeilen = ['{Dispo2}'];
        // datum;text;einsatz_id;taetigkeit_von_bis;anzahl;dispoposten_id;
        // projektbezeichnung;filiale;filial_nr;taetigk_id;von;bis;ort;
        // einsatzfirma;mitarbeiter_info;status_id;taetigkeit;interne_bem;id_firma
        $zeilen[] = '31.12.2026;;E-1;;1;;Testprojekt;DUS;14;;18:00;23:00;Duesseldorf;RG;;1;Service;;1';

        $zeilen[] = '{Dispo}';
        foreach ($dsRefs as $dsRef) {
            // datum;einsatzfirma_kurz;pnr;einsatz_id;ze;tlp_nr;ds_id;von;bis;
            // status_id;essengeld;taetigkeit;tlp_nr2;verrechnungssatz
            $zeilen[] = '31.12.2026;RG;RG14;E-1;;;'.$dsRef.';18:00;23:00;1;;Service;;0';
        }

        return implode("\n", $zeilen)."\n";
    }

    private function einbuchungAnlegen(string $dsRef, array $attr): void
    {
        $eventId = DB::table('rec_dispo_events')->where('einsatz_ref', 'E-1')->value('id')
            ?? DB::table('rec_dispo_events')->insertGetId([
                'uuid'        => 'ev-E-1',
                'einsatz_ref' => 'E-1',
                'created_at'  => '2026-09-01 00:00:00',
                'updated_at'  => '2026-09-01 00:00:00',
            ]);

        DB::table('rec_dispo_assignments')->insert(array_merge([
            'uuid'               => 'as-'.$dsRef,
            'ds_ref'             => $dsRef,
            'rec_dispo_event_id' => $eventId,
            'pnr_raw'            => 'RG14',
            'datum'              => '2026-12-31',
            'status_id'          => 1,
            'created_at'         => '2026-09-01 00:00:00',
            'updated_at'         => '2026-09-01 00:00:00',
        ], $attr));
    }

    /**
     * Alle Migrationen des Moduls gegen die frische SQLite-Datenbank. Was
     * hier wirft, wird verschluckt — welche Migration in dieser Umgebung
     * nicht laufen kann, haelt MassenzuweisungGeschlosseneWeltTest fest.
     */
    private function migrationenFahren(): void
    {
        $dateien = glob(dirname(__DIR__, 2).'/database/migrations/*.php');
        sort($dateien);

        foreach ($dateien as $datei) {
            try {
                (require $datei)->up();
            } catch (\Throwable) {
                // siehe Docblock
            }
        }
    }
}
