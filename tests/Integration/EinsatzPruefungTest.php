<?php

namespace Platform\Recruiting\Tests\Integration;

use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Console\Command;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Console\Commands\EinsatzPruefung;
use Platform\Recruiting\Models\RecEmployee;
use Platform\Recruiting\Models\RecHrDeskCase;
use Platform\Recruiting\Observers\RecEmployeeExportObserver;
use Platform\Recruiting\Services\Comms\AufgabenSender;
use Platform\Recruiting\Services\OffenePunkte;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * recruiting:einsatz-pruefung — das Kommando, an dem haengt, ob ein Mensch
 * eine Nachricht bekommt oder nicht.
 *
 * HANDGEBAUTES SCHEMA (Modul-Konvention: Migrationen laufen hier NICHT).
 * Jede Spalte, die das Kommando oder eine der Klassen darunter liest, steht
 * unten ausgeschrieben — eine fehlende macht SQLite stillschweigend zum
 * String-Literal, und der Test waere gruen und wertlos
 * (reference_pruefmuster_gruenes_nichts, elfte Falle). Dass die Spalten des
 * Triggers wirklich aus den Migrationen kommen, haelt an anderer Stelle
 * TriggerStateSchemaTest fest.
 *
 * DAS HEUTE KOMMT AUS Carbon::setTestNow(), nicht aus einer Option: eine
 * Datums-Option am Kommando waere eine Tuer, durch die ein scharfer Lauf
 * mit einem falschen Tag rechnen koennte. Alle Stichtage liegen in der
 * VERGANGENHEIT bezogen auf jeden kuenftigen Testlauf — 2026 ist beim
 * Schreiben dieser Zeilen das laufende Jahr, und ein Datum in der Zukunft
 * waere eine Zeitbombe.
 */
final class EinsatzPruefungTest extends TestCase
{
    private const TEAM = 3;

    /**
     * Ein Zeitstempel, den NIEMAND im Lauf anfassen darf. Bewusst weit vor
     * allen Stichtagen: ein Eloquent-Schreibweg wuerde ihn auf "jetzt"
     * ziehen, und das faellt sofort auf.
     */
    private const ANGEFASST = '2020-01-01 00:00:00';

    private Capsule $capsule;

    private int $naechsteId = 1;

    /** Die Sender-Attrappe; ihre Form prueft test_die_sender_attrappe_passt_zum_echten_sender. */
    private object $sender;

    private object $log;

    private object $cache;

    private string $ausgabe = '';

    /** Die Ausgabe NUR des letzten Laufs — fuer Zahlen, die sich je Lauf aendern. */
    private string $letzteAusgabe = '';

    protected function setUp(): void
    {
        parent::setUp();

        $container = Container::getInstance();
        Container::setInstance($container);

        // Log-Attrappe VOR dem Leeren der Facade-Instanzen binden
        // (reference_log_facade_test_stub.md).
        if (!class_exists('Log', false)) {
            class_alias(\Illuminate\Support\Facades\Log::class, 'Log');
        }
        $this->log = new class {
            public array $zeilen = [];

            public function __call($methode, $argumente)
            {
                $this->zeilen[] = ['art' => $methode, 'text' => (string) ($argumente[0] ?? ''), 'daten' => $argumente[1] ?? []];
            }
        };
        $container->instance('log', $this->log);
        $container->instance('config', new ConfigRepository([]));

        // ET-24: die Laufsperre. Die Attrappe verhaelt sich wie ein echtes
        // Cache-Schloss — wer es haelt, haelt es, bis er es freigibt.
        $this->cache = $this->cacheAttrappe();
        $container->instance('cache', $this->cache);

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

        $this->schemaBauen();

        $this->sender = $this->senderAttrappe();
        $container->instance(AufgabenSender::class, $this->sender);

        // Der ECHTE Beobachter, nicht seine Abwesenheit, ist die Zusicherung
        // von test_die_pruefung_setzt_keinen_zas_marker (Muster
        // BackfillPersonsTest).
        RecEmployeeExportObserver::register();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        Model::unsetConnectionResolver();
        Model::clearBootedModels();
        $container = Container::getInstance();
        $container->forgetInstance('log');
        $container->forgetInstance('config');
        $container->forgetInstance('cache');
        $container->forgetInstance('db');
        $container->forgetInstance('db.schema');
        $container->forgetInstance(AufgabenSender::class);
        Facade::clearResolvedInstances();
        parent::tearDown();
    }

    // =================================================================
    // Die Grundregeln
    // =================================================================

    public function test_ein_angebot_loest_nichts_aus(): void
    {
        $ma = $this->mitarbeiterOhneNachweise();
        $this->einbuchung($ma, ['datum' => '2026-10-20', 'status_id' => 0]);

        $this->laufe('2026-10-01');

        $this->assertSame([], $this->sender->versandt);
    }

    public function test_ein_auftrag_mit_vorlauf_meldet_die_offenen_punkte(): void
    {
        $ma = $this->mitarbeiterOhneNachweise();
        $this->einbuchung($ma, ['datum' => '2026-10-20', 'status_id' => 1]);

        $this->laufe('2026-10-01');

        $this->assertCount(1, $this->sender->versandt);
        $this->assertSame('neu', $this->sender->versandt[0]['anlass']);
        $this->assertSame($ma->id, $this->sender->versandt[0]['ma']);
    }

    /**
     * Die Gegenrichtung zur Vorlaufpruefung: zu kurzfristig gebucht geht
     * NICHTS raus. Eine Nachricht am Vorabend "lade deinen Ausweis hoch" ist
     * keine Hilfe — der Fall gehoert dann auf die HR-Liste.
     *
     * Drei Tage Vorlauf, die Schwelle sind vier (Zahl ausgeschrieben).
     */
    public function test_ein_auftrag_ohne_genug_vorlauf_meldet_nichts(): void
    {
        $ma = $this->mitarbeiterOhneNachweise();
        $this->einbuchung($ma, ['datum' => '2026-10-04', 'status_id' => 1]);

        $this->laufe('2026-10-01');

        $this->assertSame([], $this->sender->versandt);
    }

    public function test_derselbe_stand_meldet_kein_zweites_mal(): void
    {
        $ma = $this->mitarbeiterOhneNachweise();
        $this->einbuchung($ma, ['datum' => '2026-10-20', 'status_id' => 1]);

        $this->laufe('2026-10-01');
        $this->laufe('2026-10-02');

        $this->assertCount(1, $this->sender->versandt);
    }

    /**
     * Ein NEUER offener Punkt meldet wieder — aber erst nach der Pause.
     *
     * ABWEICHUNG VOM BRIEF, und sie ist der Kern dieses Tests: der Brief
     * liess einen Nachweis zwischen den beiden Laeufen ABLAUFEN. Das haette
     * nichts gemessen, und der Grund ist gemessen und nicht vermutet — die
     * Signatur steht ueber den CODES der offenen Punkte, und
     * `ProofChecklist::build()` setzt `offen` schon beim Status `laeuft_ab`
     * (Vorlauffrist aus ProofTypes::leadDays, 60 Tage). Ein Ausweis, der in
     * acht Tagen ablaeuft, steht also in BEIDEN Laeufen als `ausweis` in der
     * Liste und ergibt zweimal dieselbe Signatur.
     *
     * DAS IST KEIN MANGEL DES TESTS, SONDERN EINE EIGENSCHAFT DES SYSTEMS
     * und gehoert in den Bericht: das Ablaufen eines Dokuments allein
     * erzeugt NIE eine neue Nachricht. Was eine erzeugt, ist eine andere
     * PUNKTMENGE — ein Nachweis kommt dazu oder faellt weg. Hier wird der
     * Mensch zum Ersthelfer bestellt, und `ersthelfer` wird Pflicht.
     */
    public function test_ein_neuer_offener_punkt_meldet_wieder(): void
    {
        $ma = $this->mitarbeiterOhneNachweise();
        $this->einbuchung($ma, ['datum' => '2026-11-20', 'status_id' => 1]);

        $this->laufe('2026-10-01');

        $this->ersthelferPflichtDazu($ma);

        // Erst nach der Pause von sieben Tagen (Zahl ausgeschrieben).
        $this->laufe('2026-10-09');

        $this->assertCount(2, $this->sender->versandt);
    }

    /**
     * Die Gegenrichtung: derselbe neue Punkt INNERHALB der Pause bleibt
     * stumm. Ohne diesen Test liesse sich die Pause ersatzlos streichen,
     * ohne dass etwas rot wird — und dann bekaeme jemand, dessen Nachweise
     * nacheinander ablaufen, an drei Tagen hintereinander drei Nachrichten.
     *
     * Der Einsatz liegt bewusst WEIT hinter dem Ende der Pause (20.11. gegen
     * 08.10.), damit nicht ET-15 die Pause schlaegt.
     */
    public function test_ein_neuer_punkt_innerhalb_der_pause_bleibt_stumm(): void
    {
        $ma = $this->mitarbeiterOhneNachweise();
        $this->einbuchung($ma, ['datum' => '2026-11-20', 'status_id' => 1]);

        $this->laufe('2026-10-01');
        $this->ersthelferPflichtDazu($ma);
        $this->laufe('2026-10-06');

        $this->assertCount(1, $this->sender->versandt);
    }

    public function test_zwei_auftraege_am_selben_tag_ergeben_eine_nachricht(): void
    {
        $ma = $this->mitarbeiterOhneNachweise();
        $this->einbuchung($ma, ['datum' => '2026-10-20', 'status_id' => 1]);
        $this->einbuchung($ma, ['datum' => '2026-10-21', 'status_id' => 1]);

        $this->laufe('2026-10-01');

        // Die Signatur haengt an der Person, nicht an der Einbuchung — sonst
        // bekaeme ein Schub am Freitag drei gleiche Nachrichten.
        $this->assertCount(1, $this->sender->versandt);
    }

    /**
     * Und dasselbe ueber ZWEI ANSTELLUNGEN derselben Person (RHEINGEDECK und
     * MA). Beide stehen im Zielkreis, beide haben einen Auftrag — die
     * Nachricht geht trotzdem nur einmal raus, weil die Signatur der PERSON
     * gehoert. Ohne die Entdopplung nach Person bekaeme jeder Doppel-
     * Beschaeftigte alles zweimal.
     */
    public function test_zwei_anstellungen_derselben_person_ergeben_eine_nachricht(): void
    {
        $person = $this->personAnlegen();
        $rg = $this->mitarbeiterOhneNachweise(['rec_person_id' => $person]);
        $mg = $this->mitarbeiterOhneNachweise(['rec_person_id' => $person]);
        $this->einbuchung($rg, ['datum' => '2026-10-20', 'status_id' => 1]);
        $this->einbuchung($mg, ['datum' => '2026-10-21', 'status_id' => 1]);

        $this->laufe('2026-10-01');

        $this->assertCount(1, $this->sender->versandt);
        // Und der Bericht zaehlt EINEN Menschen in ZWEI Anstellungen. Ohne
        // die Buendelung stuenden hier zwei Menschen — die Zahl, an der HR
        // ablaest, wie viele Leute betroffen sind, waere falsch.
        $this->assertStringContainsString('1 Mensch(en) in 2 Anstellung(en)', $this->ausgabe);
    }

    /**
     * Und die Buendelung ist nicht nur Buchhaltung: nach einem FEHLSCHLAG
     * steht die Signatur nicht, nur der Stempel. Ohne Buendelung kaeme die
     * zweite Anstellung derselben Person unmittelbar danach dran, und weil
     * der Einsatz hier vor dem Ende der Pause liegt, schlaegt ET-15 die
     * Pause — der Mensch wuerde im SELBEN Lauf ein zweites Mal
     * angeschrieben. Gemessen: ohne Buendelung zwei Versuche, mit einer.
     */
    public function test_eine_person_mit_zwei_anstellungen_wird_auch_nach_einem_fehlschlag_nur_einmal_angesprochen(): void
    {
        $person = $this->personAnlegen();
        $rg = $this->mitarbeiterOhneNachweise(['rec_person_id' => $person]);
        $mg = $this->mitarbeiterOhneNachweise(['rec_person_id' => $person]);
        $this->einbuchung($rg, ['datum' => '2026-10-06', 'status_id' => 1]);
        $this->einbuchung($mg, ['datum' => '2026-10-06', 'status_id' => 1]);

        $this->sender->antwort = AufgabenSender::STATUS_FAILED;

        $this->laufe('2026-10-01');

        $this->assertCount(1, $this->sender->versandt);
    }

    /**
     * Die Gegenrichtung dazu: zwei VERSCHIEDENE Menschen bekommen zwei
     * Nachrichten. Ohne diesen Test liesse sich die Entdopplung so verengen,
     * dass pro Lauf nur noch EIN Mensch bedient wird.
     */
    public function test_zwei_verschiedene_menschen_bekommen_zwei_nachrichten(): void
    {
        $a = $this->mitarbeiterOhneNachweise();
        $b = $this->mitarbeiterOhneNachweise();
        $this->einbuchung($a, ['datum' => '2026-10-20', 'status_id' => 1]);
        $this->einbuchung($b, ['datum' => '2026-10-21', 'status_id' => 1]);

        $this->laufe('2026-10-01', ['--welle' => 10]);

        $this->assertCount(2, $this->sender->versandt);
    }

    public function test_die_erinnerung_geht_erst_kurz_vor_dem_einsatz(): void
    {
        $ma = $this->mitarbeiterOhneNachweise();
        $this->einbuchung($ma, ['datum' => '2026-10-10', 'status_id' => 1]);

        $this->laufe('2026-10-01');          // erste Nachricht
        $this->laufe('2026-10-05');          // noch zu frueh fuer die Erinnerung
        $this->assertCount(1, $this->sender->versandt);

        $this->laufe('2026-10-08');          // zwei Tage vorher (Zahl ausgeschrieben)
        $this->assertCount(2, $this->sender->versandt);
        $this->assertSame('erinnerung', $this->sender->versandt[1]['anlass']);
    }

    public function test_eine_erinnerung_geht_nur_einmal_je_einbuchung(): void
    {
        $ma = $this->mitarbeiterOhneNachweise();
        $this->einbuchung($ma, ['datum' => '2026-10-10', 'status_id' => 1]);

        $this->laufe('2026-10-01');
        $this->laufe('2026-10-08');
        $this->laufe('2026-10-09');

        $erinnerungen = array_filter($this->sender->versandt, fn ($v) => $v['anlass'] === 'erinnerung');
        $this->assertCount(1, $erinnerungen);
    }

    /**
     * F3 — EINE ERINNERUNG JE MENSCH UND LAUF, und sie nennt den
     * NAECHSTLIEGENDEN faelligen Einsatz.
     *
     * Vorher ergaben zwei Einbuchungen in der Frist zwei Nachrichten.
     * Gemessen hat die Abschlusspruefung drei WhatsApp in EINEM Lauf.
     * Gestempelt werden trotzdem BEIDE — die Nachricht spricht fuer alle in
     * dieser Frist, und ein ungestempelter Rest wuerde in der naechsten
     * Stunde eine zweite Nachricht ausloesen.
     */
    public function test_eine_erinnerung_je_mensch_und_lauf_nennt_den_naechsten_faelligen(): void
    {
        $ma = $this->mitarbeiterOhneNachweise();
        $a = $this->einbuchung($ma, ['datum' => '2026-10-09', 'status_id' => 1]);
        $b = $this->einbuchung($ma, ['datum' => '2026-10-10', 'status_id' => 1]);

        $this->laufe('2026-10-01');
        $this->laufe('2026-10-08');

        $erinnerungen = array_values(array_filter($this->sender->versandt, fn ($v) => $v['anlass'] === 'erinnerung'));

        $this->assertCount(1, $erinnerungen);
        $this->assertSame('2026-10-09', $erinnerungen[0]['stand']['einsatz']['datum']);

        foreach ([$a, $b] as $id) {
            $this->assertNotNull(
                DB::table('rec_dispo_assignments')->where('id', $id)->value('aufgaben_erinnert_at'),
                'auch die zweite Einbuchung muss gestempelt sein, sonst kommt in einer Stunde die naechste Nachricht',
            );
        }
    }

    /**
     * F3, in der Form, in der die Pruefung es gemessen hat: drei
     * Einbuchungen an drei Tagen, EIN Lauf. Vorher drei WhatsApp, jetzt
     * eine.
     */
    public function test_drei_einbuchungen_in_der_frist_ergeben_eine_nachricht(): void
    {
        $ma = $this->mitarbeiterOhneNachweise();
        foreach (['2026-10-01', '2026-10-02', '2026-10-03'] as $datum) {
            $this->einbuchung($ma, ['datum' => $datum, 'status_id' => 1]);
        }

        $this->laufe('2026-10-01');

        $this->assertCount(1, $this->sender->versandt);
        $this->assertSame('erinnerung', $this->sender->versandt[0]['anlass']);
        $this->assertSame(
            0,
            DB::table('rec_dispo_assignments')->whereNull('aufgaben_erinnert_at')->count(),
            'alle drei muessen gestempelt sein',
        );
    }

    /**
     * Und die mehrtaegige Veranstaltung, die der Pruefer genannt hat: eine
     * Fuenf-Tage-Messe ist in rec_dispo_assignments eine Zeile JE TAG. Ohne
     * die Pause je Mensch haette dieser Mensch an fuenf Tagen hintereinander
     * eine Erinnerung bekommen.
     */
    public function test_eine_fuenf_tage_messe_ergibt_eine_einzige_nachricht(): void
    {
        $ma = $this->mitarbeiterOhneNachweise();
        foreach (['2026-10-10', '2026-10-11', '2026-10-12', '2026-10-13', '2026-10-14'] as $datum) {
            $this->einbuchung($ma, ['datum' => $datum, 'status_id' => 1]);
        }

        foreach (['2026-10-08', '2026-10-09', '2026-10-10', '2026-10-11', '2026-10-12', '2026-10-13', '2026-10-14'] as $tag) {
            $this->laufe($tag);
        }

        // EINE Nachricht fuer die ganze Messe, nicht fuenf. Sie geht am
        // 08.10. als "neu" raus (der 12.10. hat genug Vorlauf) und deckt
        // alle fuenf Tage mit ab — ET-26 unterdrueckt die Erinnerung
        // desselben Laufs, die Abdeckung stempelt die uebrigen Tage.
        $this->assertCount(1, $this->sender->versandt);
        $this->assertSame('neu', $this->sender->versandt[0]['anlass']);
        $this->assertSame(
            0,
            DB::table('rec_dispo_assignments')->whereNull('aufgaben_erinnert_at')->count(),
            'kein Messetag darf mit leerem Stempel zurueckbleiben — sonst glaubt das System, '
            .'ihm noch eine Erinnerung zu schulden, und loest sie nie ein',
        );
    }

    /**
     * Die Gegenrichtung zur Pause: ist sie um, geht die naechste Erinnerung
     * sehr wohl raus. Ohne diese Haelfte liesse sich die Erinnerung ganz
     * abschalten, und die Tests oben blieben gruen.
     */
    public function test_nach_der_pause_erinnert_es_wieder(): void
    {
        $ma = $this->mitarbeiterOhneNachweise();
        $this->einbuchung($ma, ['datum' => '2026-10-02', 'status_id' => 1]);
        $this->einbuchung($ma, ['datum' => '2026-10-25', 'status_id' => 1]);

        // Die neue Nachricht ist hier schon erledigt — sonst unterdrueckte
        // sie nach ET-26 die erste Erinnerung, und dieser Test maesse die
        // falsche Regel. Dieselbe Signatur, ein alter Stempel.
        $this->signaturSetzen($ma, '2026-10-01', '2026-09-01 09:00:00');

        $this->laufe('2026-10-01');                  // erinnert an den 02.10.
        $this->laufe('2026-10-23');                  // lange nach der Pause: erinnert an den 25.10.

        $erinnerungen = array_values(array_filter($this->sender->versandt, fn ($v) => $v['anlass'] === 'erinnerung'));

        $this->assertCount(2, $erinnerungen);
        $this->assertSame('2026-10-02', $erinnerungen[0]['stand']['einsatz']['datum']);
        $this->assertSame('2026-10-25', $erinnerungen[1]['stand']['einsatz']['datum']);
    }

    /**
     * Eine verschwundene Einbuchung erinnert nicht. Sie ist aus der letzten
     * ZAS-Lieferung gefallen und keine verlaessliche Grundlage.
     */
    public function test_eine_verschwundene_einbuchung_erinnert_nicht(): void
    {
        $ma = $this->mitarbeiterOhneNachweise();
        // Ein zweiter, unauffaelliger Auftrag haelt den Menschen im
        // Zielkreis — sonst faellt er schon durch die Kandidaten-Abfrage
        // heraus, und der Filter in der Erinnerungs-Abfrage bliebe
        // ungemessen (Mutationsprobe M18: er liess sich ersatzlos
        // streichen, ohne dass etwas rot wurde).
        $this->einbuchung($ma, ['datum' => '2026-11-20', 'status_id' => 1]);
        $verschwunden = $this->einbuchung($ma, [
            'datum'         => '2026-10-10',
            'status_id'     => 1,
            'missing_since' => '2026-10-02 08:00:00',
        ]);

        $this->laufe('2026-10-08', ['--welle' => 10]);

        $erinnerungen = array_filter($this->sender->versandt, fn ($v) => $v['anlass'] === 'erinnerung');
        $this->assertSame([], $erinnerungen, 'eine aus der ZAS-Lieferung gefallene Einbuchung ist keine Grundlage');
        $this->assertNull(
            DB::table('rec_dispo_assignments')->where('id', $verschwunden)->value('aufgaben_erinnert_at'),
        );

        // Vorflug: der Lauf hat diesen Menschen sehr wohl bedient.
        $this->assertCount(1, $this->sender->versandt);
        $this->assertSame('neu', $this->sender->versandt[0]['anlass']);
    }

    // =================================================================
    // F2 — ein naher Einsatz darf keinen fernen verdecken
    // =================================================================

    /**
     * `OffenePunkte` liefert GENAU EINEN Bezug: den naechsten. Liegt der
     * naeher als die Vorlaufzeit, loeste frueher gar nichts aus — der
     * spaetere Einsatz, fuer den die Nachricht noch rechtzeitig kaeme, kam
     * nie zur Sprache. Gemessen hat die Pruefung: Einsatz in zwei Tagen plus
     * Einsatz in 24 Tagen ergab nur eine Erinnerung und KEINE Signatur.
     */
    public function test_ein_naher_einsatz_verdeckt_den_fernen_nicht(): void
    {
        $ma = $this->mitarbeiterOhneNachweise();
        $this->einbuchung($ma, ['datum' => '2026-10-03', 'status_id' => 1]);
        $this->einbuchung($ma, ['datum' => '2026-10-25', 'status_id' => 1]);

        $this->laufe('2026-10-01');

        $neue = array_values(array_filter($this->sender->versandt, fn ($v) => $v['anlass'] === 'neu'));

        $this->assertCount(1, $neue);
        $this->assertSame(
            '2026-10-25',
            $neue[0]['stand']['einsatz']['datum'],
            'Genannt wird der AUSLOESENDE Einsatz — der, fuer den die Nachricht noch rechtzeitig kommt.',
        );
        $this->assertNotNull($this->person($ma)->aufgaben_signatur);
    }

    /**
     * Die Gegenrichtung: gibt es KEINEN kommenden Auftrag mit genug Vorlauf,
     * bleibt es dabei, dass nichts ausloest. Ohne diese Haelfte liesse sich
     * die Vorlaufpruefung in der neuen Schleife ersatzlos streichen.
     */
    public function test_ohne_einen_einzigen_auftrag_mit_vorlauf_loest_nichts_aus(): void
    {
        $ma = $this->mitarbeiterOhneNachweise();
        $this->einbuchung($ma, ['datum' => '2026-10-02', 'status_id' => 1]);
        $this->einbuchung($ma, ['datum' => '2026-10-03', 'status_id' => 1]);
        $this->einbuchung($ma, ['datum' => '2026-10-04', 'status_id' => 1]);

        $this->laufe('2026-10-01');

        $neue = array_filter($this->sender->versandt, fn ($v) => $v['anlass'] === 'neu');
        $this->assertSame([], $neue);
        $this->assertNull($this->person($ma)->aufgaben_signatur);
    }

    /**
     * Die Messung der Pruefung, in ihrer Form: ein Mensch mit je einem
     * Einsatz an 28 aufeinanderfolgenden Tagen, 14 stuendliche Laeufe.
     *
     * VORHER: 16 Erinnerungen und NULL "neu"-Nachrichten — der naechste
     * Einsatz lag immer innerhalb von vier Tagen, also loeste nie etwas aus,
     * und weil nie eine Signatur entstand, lief auch der ganze
     * ET-16/ET-23-Haushalt fuer diesen Menschen leer. Betroffen war genau
     * die Gruppe, auf die es ankommt: die oft im Einsatz ist.
     *
     * JETZT: eine "neu"-Nachricht (danach haelt die Signatur) und EINE
     * Erinnerung — zwei Nachrichten in vierzehn Tagen statt sechzehn. Die
     * zweite Erinnerung aus der vorigen Runde ist seit der Abdeckung
     * (ET-27) weggefallen: die erste Nachricht deckt alles mit ab, was in
     * ihre Pause faellt.
     */
    public function test_ein_dicht_gebuchter_mensch_wird_erreicht_und_nicht_zugeschuettet(): void
    {
        $ma = $this->mitarbeiterOhneNachweise();
        for ($tag = 1; $tag <= 28; $tag++) {
            $this->einbuchung($ma, [
                'datum'     => date('Y-m-d', strtotime('2026-10-01 +'.$tag.' days')),
                'status_id' => 1,
            ]);
        }

        for ($tag = 0; $tag < 14; $tag++) {
            $this->laufe(date('Y-m-d', strtotime('2026-10-01 +'.$tag.' days')));
        }

        $anlaesse = array_count_values(array_column($this->sender->versandt, 'anlass'));

        $this->assertSame(1, $anlaesse['neu'] ?? 0, 'genau eine fruehe Nachricht, danach haelt die Signatur');
        $this->assertSame(1, $anlaesse['erinnerung'] ?? 0, 'eine Erinnerung in 14 Tagen, nicht sechzehn');
    }

    // =================================================================
    // ET-26 / ET-27 — die Folgen der F2/F3-Reparatur
    // =================================================================

    /**
     * ET-26 — KEIN MENSCH BEKOMMT ZWEI NACHRICHTEN IN EINEM LAUF.
     *
     * Das ist erst seit F2 ueberhaupt moeglich: vorher sperrte der nahe
     * Einsatz die neue Nachricht (genau der Fund). Jetzt treffen "neu" fuer
     * den fernen und "Erinnerung" fuer den nahen Einsatz im selben Durchgang
     * zusammen. Gemessen wurde `neu@25.10.` UND `erinnerung@03.10.` —
     * zweimal dieselbe Punktliste, in derselben Minute.
     */
    public function test_kein_mensch_bekommt_zwei_nachrichten_in_einem_lauf(): void
    {
        $ma = $this->mitarbeiterOhneNachweise();
        $nah  = $this->einbuchung($ma, ['datum' => '2026-10-03', 'status_id' => 1]);
        $fern = $this->einbuchung($ma, ['datum' => '2026-10-25', 'status_id' => 1]);

        $this->laufe('2026-10-01');

        $this->assertCount(1, $this->sender->versandt);
        $this->assertSame('neu', $this->sender->versandt[0]['anlass'], 'die neue Nachricht gewinnt — sie nennt den Einsatz, fuer den die Aufgaben noch zu schaffen sind');
        $this->assertSame('2026-10-25', $this->sender->versandt[0]['stand']['einsatz']['datum']);

        // Und der nahe Einsatz ist gestempelt: der Mensch ist ueber dieselbe
        // Punktliste informiert worden. Ohne den Stempel kaeme die
        // Erinnerung in der naechsten Stunde.
        $this->assertNotNull(DB::table('rec_dispo_assignments')->where('id', $nah)->value('aufgaben_erinnert_at'));
        $this->assertNull(
            DB::table('rec_dispo_assignments')->where('id', $fern)->value('aufgaben_erinnert_at'),
            'der ferne Einsatz liegt ausserhalb der Pause und behaelt seine eigene Erinnerung',
        );
    }

    /**
     * Die Gegenrichtung, und ohne sie waere ET-26 ein stiller Verlust: ging
     * die neue Nachricht NICHT raus (Welle voll, Versand gescheitert), darf
     * auch nicht gestempelt werden — sonst verbrennt der Lauf die Erinnerung
     * eines Menschen, der gar nichts bekommen hat.
     */
    public function test_ohne_versand_wird_die_erinnerung_nicht_verbrannt(): void
    {
        $ma = $this->mitarbeiterOhneNachweise();
        $nah = $this->einbuchung($ma, ['datum' => '2026-10-03', 'status_id' => 1]);
        $this->einbuchung($ma, ['datum' => '2026-10-25', 'status_id' => 1]);

        $this->sender->antwort = AufgabenSender::STATUS_FAILED;
        $this->laufe('2026-10-01');

        $this->assertNull(
            DB::table('rec_dispo_assignments')->where('id', $nah)->value('aufgaben_erinnert_at'),
            'ein gescheiterter Versand darf keinen Stempel setzen',
        );
    }

    /**
     * Und die Zahlen muessen in beiden Laufarten dieselben sein. Die
     * ET-26-Flagge haengt deshalb an der FAELLIGKEIT und nicht am Versand:
     * im Trockenlauf wird nie verschickt, also meldete eine am Versand
     * haengende Flagge dort eine Erinnerung als faellig, die der scharfe
     * Lauf gar nicht schickt — genau der Fehler, der bei F5 schon einmal
     * durchgerutscht ist, und genau die Zahl, nach der jemand die
     * Wellengroesse bemisst.
     */
    public function test_der_trockenlauf_meldet_bei_et26_dieselben_zahlen(): void
    {
        $ma = $this->mitarbeiterOhneNachweise();
        $this->einbuchung($ma, ['datum' => '2026-10-03', 'status_id' => 1]);
        $this->einbuchung($ma, ['datum' => '2026-10-25', 'status_id' => 1]);

        $this->laufe('2026-10-01', ['--dry-run' => true]);
        $trocken = $this->faelligZeile();

        $this->laufe('2026-10-01');
        $scharf = $this->faelligZeile();

        $this->assertSame($scharf, $trocken);
        // Und beide sagen die SACHE, nicht nur dasselbe: eine neue
        // Nachricht, keine faellige Erinnerung, eine unterdrueckte.
        $this->assertStringContainsString('0 Mensch(en) fuer eine Erinnerung', $scharf);
        $this->assertStringContainsString('1 bekommen in diesem Lauf schon die neue Nachricht', $scharf);
    }

    /**
     * ET-27 — DIE PAUSE DARF AUCH HIER KEINE NACHRICHT HINTER IHREN EINSATZ
     * SCHIEBEN, und die Messung ist die des Pruefers: Einsaetze am 05.10.
     * und 09.10., elf Laeufe.
     *
     * VORHER: Stempel 05.10. gesetzt, **Stempel 09.10. blieb NULL und blieb
     * es** — der 09.10. wird am 07.10. faellig, ist bis zum 10.10. gesperrt
     * und danach vorbei. Das System glaubte dauerhaft, ihm eine Erinnerung
     * zu schulden, und loeste sie nie ein.
     *
     * JETZT: eine Nachricht, die BEIDE abdeckt, und kein toter Stempel.
     * Warum nicht zwei Nachrichten — und was das kostet — steht im Bericht;
     * die Kurzfassung: die blanke ET-15-Ausnahme ohne Abdeckung ergab
     * gemessen 13 Erinnerungen in 14 Tagen statt 2 und machte die
     * F3-Bremse zunichte.
     */
    public function test_kein_einsatz_bleibt_mit_totem_stempel_zurueck(): void
    {
        $ma = $this->mitarbeiterOhneNachweise();
        $this->einbuchung($ma, ['datum' => '2026-10-05', 'status_id' => 1]);
        $this->einbuchung($ma, ['datum' => '2026-10-09', 'status_id' => 1]);
        $this->signaturSetzen($ma, '2026-10-01', '2026-09-01 09:00:00');

        for ($tag = 0; $tag <= 10; $tag++) {
            $this->laufe(date('Y-m-d', strtotime('2026-10-01 +'.$tag.' days')));
        }

        $erinnerungen = array_values(array_filter($this->sender->versandt, fn ($v) => $v['anlass'] === 'erinnerung'));

        $this->assertCount(1, $erinnerungen);
        $this->assertSame('2026-10-05', $erinnerungen[0]['stand']['einsatz']['datum']);
        $this->assertSame(
            0,
            DB::table('rec_dispo_assignments')->whereNull('aufgaben_erinnert_at')->count(),
            'ET-27: kein Einsatz darf mit leerem Stempel zurueckbleiben',
        );
    }

    /**
     * Und die Ausnahme selbst, in der Lage, in der sie nicht mit der
     * F3-Bremse kollidiert: eine Einbuchung, die ERST NACH der letzten
     * Nachricht geliefert wurde, faellt mitten in die Pause und waere danach
     * vorbei. Sie war bei der Abdeckung noch nicht da, traegt also keinen
     * Stempel — und dann gewinnt der Einsatz, genau wie bei ET-15.
     */
    public function test_eine_neu_gelieferte_einbuchung_schlaegt_die_pause(): void
    {
        $ma = $this->mitarbeiterOhneNachweise();
        $this->einbuchung($ma, ['datum' => '2026-10-03', 'status_id' => 1]);
        $this->signaturSetzen($ma, '2026-10-01', '2026-09-01 09:00:00');

        $this->laufe('2026-10-01');                 // erinnert an den 03.10.
        $this->assertCount(1, $this->sender->versandt);

        // ZAS liefert nach: ein Einsatz am 06.10., mitten in der Pause.
        $this->einbuchung($ma, ['datum' => '2026-10-06', 'status_id' => 1]);

        $this->laufe('2026-10-04');

        $erinnerungen = array_values(array_filter($this->sender->versandt, fn ($v) => $v['anlass'] === 'erinnerung'));
        $this->assertCount(2, $erinnerungen);
        $this->assertSame('2026-10-06', $erinnerungen[1]['stand']['einsatz']['datum']);
    }

    // =================================================================
    // F4 — der Bote ist die Anstellung mit dem Portal, nicht die mit der Buchung
    // =================================================================

    /**
     * `portal_v2_since`, Rufnummer, Kanal und Portal-Token stehen alle an
     * der ANSTELLUNG. Welche Anstellung frueher den Ausschlag gab, haengte
     * davon ab, wo gerade eine Einbuchung lag — bei einem
     * Doppelbeschaeftigten RG+MA mit halb durchgefuehrter Pilotumstellung
     * bekam derselbe Mensch je nach Reihenfolge der Kennungen alles oder gar
     * nichts.
     *
     * Hier die harmlose Reihenfolge: die umgestellte Anstellung hat die
     * GROESSERE Kennung und traegt die Einbuchung.
     */
    public function test_der_bote_ist_die_umgestellte_anstellung(): void
    {
        $person = $this->personAnlegen();
        $altesPortal = $this->mitarbeiterOhneNachweise(['rec_person_id' => $person, 'portal_v2_since' => null]);
        $neuesPortal = $this->mitarbeiterOhneNachweise(['rec_person_id' => $person]);
        $this->einbuchung($neuesPortal, ['datum' => '2026-10-20', 'status_id' => 1]);

        $this->laufe('2026-10-01');

        $this->assertCount(1, $this->sender->versandt);
        $this->assertSame($neuesPortal->id, $this->sender->versandt[0]['ma']);
        $this->assertGreaterThan($altesPortal->id, $neuesPortal->id, 'Vorflug: die Reihenfolge ist die hier gemeinte');
    }

    /**
     * Und die Reihenfolge, an der es gemessen GESCHEITERT ist: die
     * umgestellte Anstellung hat die KLEINERE Kennung, die Einbuchung haengt
     * an der NICHT umgestellten. Vorher: versandt = 0, der Mensch stand im
     * Bericht unter „altes Portal" — obwohl er ueber die andere Zeile ein
     * funktionierendes Portal hat.
     */
    public function test_der_bote_wird_auch_gefunden_wenn_die_buchung_woanders_haengt(): void
    {
        $person = $this->personAnlegen();
        $neuesPortal = $this->mitarbeiterOhneNachweise(['rec_person_id' => $person]);
        $altesPortal = $this->mitarbeiterOhneNachweise(['rec_person_id' => $person, 'portal_v2_since' => null]);
        $this->einbuchung($altesPortal, ['datum' => '2026-10-20', 'status_id' => 1]);

        $this->laufe('2026-10-01');

        $this->assertCount(1, $this->sender->versandt);
        $this->assertSame(
            $neuesPortal->id,
            $this->sender->versandt[0]['ma'],
            'Die Nachricht muss ueber die Anstellung gehen, die das Portal hat — Nummer, Kanal und Token gehoeren zusammen.',
        );
        $this->assertLessThan($altesPortal->id, $neuesPortal->id, 'Vorflug: die Reihenfolge ist die hier gemeinte');
    }

    /**
     * Die Gegenrichtung: hat KEINE einzige Anstellung des Menschen das neue
     * Portal, geht weiterhin nichts raus. Sonst waere die Suche nach dem
     * Boten eine Hintertuer am Portal-Filter.
     */
    public function test_ohne_eine_einzige_umgestellte_anstellung_geht_nichts_raus(): void
    {
        $person = $this->personAnlegen();
        $a = $this->mitarbeiterOhneNachweise(['rec_person_id' => $person, 'portal_v2_since' => null]);
        $this->mitarbeiterOhneNachweise(['rec_person_id' => $person, 'portal_v2_since' => null]);
        $this->einbuchung($a, ['datum' => '2026-10-20', 'status_id' => 1]);

        $this->laufe('2026-10-01');

        $this->assertSame([], $this->sender->versandt);
        $this->assertStringContainsString('altes Portal', $this->ausgabe);
    }

    // =================================================================
    // ET-16 — ohne das verstummt das System dauerhaft
    // =================================================================

    /**
     * Der gemessene Dreischritt: 01.01. fehlt der Ausweis und die Nachricht
     * geht raus. 30.01. ist alles da, die Liste ist leer — und JETZT muss die
     * Signatur weg. 20.07. laeuft genau derselbe Ausweis ab: dieselbe
     * Punktmenge, also dieselbe Signatur. Bliebe sie stehen, waere es
     * Schweigen, und zwar fuer immer.
     *
     * Der zweite Lauf braucht KEINE Einbuchung: dass die Person trotzdem
     * geprueft wird, liegt allein daran, dass sie eine Signatur traegt.
     * Genau das ist die Zielmenge, ohne die ET-16 nur auf dem Papier
     * repariert waere.
     */
    public function test_eine_leere_liste_raeumt_die_signatur_und_meldet_spaeter_wieder(): void
    {
        $ma = $this->mitarbeiterOhneNachweise();
        $this->einbuchung($ma, ['datum' => '2026-01-20', 'status_id' => 1]);

        $this->laufe('2026-01-01');
        $this->assertCount(1, $this->sender->versandt);
        $this->assertNotNull($this->person($ma)->aufgaben_signatur);

        // Alles nachgereicht, gueltig bis zum 19.07.
        $this->alleNachweiseErbringen($ma, '2026-01-30', '2026-07-19');
        $this->laufe('2026-01-30');

        $this->assertNull($this->person($ma)->aufgaben_signatur, 'ET-16: eine leere Liste raeumt die Signatur');
        $this->assertNull($this->person($ma)->aufgaben_gemeldet_at, 'ET-16: und den Stempel gleich mit');
        $this->assertCount(1, $this->sender->versandt, 'ueber eine leere Liste geht nichts raus');

        // Und ein halbes Jahr spaeter laufen dieselben Nachweise ab.
        $this->einbuchung($ma, ['datum' => '2026-07-25', 'status_id' => 1]);
        $this->laufe('2026-07-20');

        $this->assertCount(2, $this->sender->versandt, 'ET-16: ohne das Raeumen waere hier Schweigen — fuer immer');
    }

    /**
     * Die Gegenrichtung, und sie ist die Sorge bei jedem Raeumen: das
     * Raeumen darf NICHT dazu fuehren, dass dieselbe Nachricht gleich
     * nochmal rausgeht. Die Liste ist leer, also wird geraeumt — und im
     * naechsten Lauf steht trotzdem nichts an, weil ueber eine leere Liste
     * nie etwas verschickt wird.
     */
    public function test_das_raeumen_loest_keine_zweite_nachricht_aus(): void
    {
        $ma = $this->mitarbeiterOhneNachweise();
        $this->einbuchung($ma, ['datum' => '2026-01-20', 'status_id' => 1]);
        $this->laufe('2026-01-01');

        $this->alleNachweiseErbringen($ma, '2026-01-30', '2026-07-19');
        $this->laufe('2026-01-30');
        $this->laufe('2026-01-31');
        $this->laufe('2026-02-01');

        $this->assertCount(1, $this->sender->versandt);
    }

    // =================================================================
    // ET-15 — die Pause darf keine Nachricht hinter ihren Einsatz schieben
    // =================================================================

    /**
     * Gemessener Ablauf: 03.10. Nachricht zu E1 am 20.10. 05.10. kommt ein
     * neuer Punkt dazu UND ein Einsatz E2 am 09.10. Die Pause liefe bis zum
     * 10.10. — die Nachricht zu E2 kaeme also NIE, denn danach ist E2
     * vorbei. Liegt der ausloesende Einsatz vor dem Ende der Pause, gewinnt
     * der Einsatz.
     */
    public function test_der_einsatz_schlaegt_die_pause(): void
    {
        $ma = $this->mitarbeiterOhneNachweise();
        $this->einbuchung($ma, ['datum' => '2026-10-20', 'status_id' => 1]);   // E1

        $this->laufe('2026-10-03');
        $this->assertCount(1, $this->sender->versandt);

        $this->ersthelferPflichtDazu($ma);                                     // ein neuer Punkt
        $this->einbuchung($ma, ['datum' => '2026-10-09', 'status_id' => 1]);   // E2
        $this->laufe('2026-10-05');

        $this->assertCount(2, $this->sender->versandt, 'ET-15: E2 am 09.10. liegt vor dem Ende der Pause am 10.10.');
        $this->assertSame('2026-10-09', $this->sender->versandt[1]['stand']['einsatz']['datum']);
    }

    /**
     * Die Gegenrichtung: liegt der ausloesende Einsatz HINTER dem Ende der
     * Pause, bleibt die Pause stehen. Sonst waere ET-15 keine Ausnahme
     * sondern die Abschaffung der Pause — und die ist die einzige Bremse
     * gegen eine Kette von Nachrichten, wenn mehrere Nachweise nacheinander
     * ablaufen.
     *
     * Derselbe Aufbau wie oben, nur liegt E2 am 11.10. statt am 09.10. —
     * einen Tag HINTER dem Ende der Pause am 10.10.
     */
    public function test_ein_einsatz_hinter_der_pause_schlaegt_sie_nicht(): void
    {
        $ma = $this->mitarbeiterOhneNachweise();
        $this->einbuchung($ma, ['datum' => '2026-10-20', 'status_id' => 1]);   // E1

        $this->laufe('2026-10-03');

        $this->ersthelferPflichtDazu($ma);                                     // ein neuer Punkt
        $this->einbuchung($ma, ['datum' => '2026-10-11', 'status_id' => 1]);   // E2
        $this->laufe('2026-10-05');

        $this->assertCount(1, $this->sender->versandt);
    }

    /**
     * Und die Signatur bleibt auch bei ET-15 die Bremse: derselbe Stand,
     * derselbe nahe Einsatz, zwei Laeufe — eine Nachricht. Ohne diese
     * Zusicherung liesse sich ET-15 als "naher Einsatz hebt ALLE Bremsen
     * auf" umsetzen, und dann kaeme in den vier Tagen vor dem Einsatz
     * stuendlich eine Nachricht.
     */
    public function test_der_nahe_einsatz_hebt_die_signatur_nicht_auf(): void
    {
        $ma = $this->mitarbeiterOhneNachweise();
        $this->einbuchung($ma, ['datum' => '2026-10-20', 'status_id' => 1]);
        $this->laufe('2026-10-03');

        $this->ersthelferPflichtDazu($ma);
        $this->einbuchung($ma, ['datum' => '2026-10-09', 'status_id' => 1]);
        $this->laufe('2026-10-05');
        $this->laufe('2026-10-05');

        $this->assertCount(2, $this->sender->versandt);
    }

    // =================================================================
    // ET-23 — `sent` ist kein Beweis der Zustellung
    // =================================================================

    /**
     * Meldet Meta spaeter per Webhook, dass die Nachricht nicht zugestellt
     * werden konnte, steht die gespeicherte Nachricht auf `failed`. Dann
     * wird die Signatur geraeumt, damit es einen zweiten Versuch gibt.
     *
     * Der Stempel bleibt dabei bewusst STEHEN: der zweite Versuch kommt nach
     * der Pause, nicht in der naechsten Stunde. Sonst bekaeme ein dauerhaft
     * unerreichbarer Anschluss stuendlich einen Versuch.
     */
    public function test_eine_nachtraeglich_gescheiterte_nachricht_raeumt_die_signatur(): void
    {
        $ma = $this->mitarbeiterOhneNachweise();
        $this->einbuchung($ma, ['datum' => '2026-10-20', 'status_id' => 1]);

        $this->sender->nachrichtId = $this->nachrichtAnlegen('sent');
        $this->laufe('2026-10-01');
        $this->assertCount(1, $this->sender->versandt);
        $this->assertNotNull($this->person($ma)->aufgaben_signatur);

        // Der Webhook trifft ein.
        DB::table('comms_whatsapp_messages')->where('id', $this->sender->nachrichtId)->update(['status' => 'failed']);

        $this->laufe('2026-10-02');
        $this->assertNull($this->person($ma)->aufgaben_signatur, 'ET-23: die Signatur ist geraeumt');
        $this->assertCount(1, $this->sender->versandt, 'der zweite Versuch kommt erst nach der Pause');

        // UND DER STEMPEL STEHT NOCH. Dieser Lauf ist die eigentliche Probe
        // darauf (Mutationsprobe M9b): raeumte das Nachlesen oben AUCH
        // `aufgaben_gemeldet_at`, bliebe das im Lauf vom 02.10. unsichtbar
        // (der Wert wurde dort vorher schon gelesen) und schluege erst hier
        // durch — mit einer zweiten Nachricht am naechsten Tag statt nach
        // der Pause. Ein dauerhaft unerreichbarer Anschluss bekaeme dann
        // taeglich einen Versuch.
        $this->assertNotNull($this->person($ma)->aufgaben_gemeldet_at);
        $this->laufe('2026-10-03');
        $this->assertCount(1, $this->sender->versandt, 'ET-23 raeumt die Signatur, nicht die Pause');

        $this->laufe('2026-10-08');
        $this->assertCount(2, $this->sender->versandt);
    }

    /**
     * Die Gegenrichtung, ohne die das Raeumen eine Spam-Maschine waere:
     * steht die gespeicherte Nachricht weiterhin auf `sent`, bleibt die
     * Signatur stehen und es geht nichts noch einmal raus — auch nicht nach
     * der Pause.
     */
    public function test_eine_zugestellte_nachricht_laesst_die_signatur_stehen(): void
    {
        $ma = $this->mitarbeiterOhneNachweise();
        $this->einbuchung($ma, ['datum' => '2026-10-20', 'status_id' => 1]);

        $this->sender->nachrichtId = $this->nachrichtAnlegen('sent');
        $this->laufe('2026-10-01');

        $this->laufe('2026-10-02');
        $this->laufe('2026-10-08');
        $this->laufe('2026-10-19');

        $this->assertNotNull($this->person($ma)->aufgaben_signatur);

        // Nur die NEUEN zaehlen: am 19.10. ist der Einsatz einen Tag
        // entfernt, da geht regulaer eine Erinnerung raus — die hat mit der
        // Signatur nichts zu tun und darf diese Zusicherung nicht truebuen.
        $neue = array_filter($this->sender->versandt, fn ($v) => $v['anlass'] === 'neu');
        $this->assertCount(1, $neue);
    }

    /**
     * Die Gegenrichtung von ET-23 (im Brief ausdruecklich genannt): faellt
     * die Ausnahme NACH dem HTTP-POST, meldet der Sender `failed`, obwohl
     * die Nachricht drausen ist. Wiederholte das Kommando dann stuendlich,
     * bekaeme der Mensch sie zwoelfmal am Tag.
     *
     * Der Zuschnitt: bei `failed` wird der STEMPEL trotzdem gesetzt, die
     * Signatur aber nicht. Der naechste Versuch kommt damit nach der Pause
     * von sieben Tagen — nicht in der naechsten Stunde.
     */
    public function test_ein_fehlschlag_wiederholt_sich_erst_nach_der_pause(): void
    {
        $ma = $this->mitarbeiterOhneNachweise();
        $this->einbuchung($ma, ['datum' => '2026-11-20', 'status_id' => 1]);

        $this->sender->antwort = AufgabenSender::STATUS_FAILED;
        $this->laufe('2026-10-01');

        $this->assertCount(1, $this->sender->versandt);
        $this->assertNull($this->person($ma)->aufgaben_signatur, 'ein Fehlschlag gilt nicht als informiert');
        $this->assertNotNull($this->person($ma)->aufgaben_gemeldet_at, 'der Stempel bremst die Wiederholung');

        $this->laufe('2026-10-02');
        $this->assertCount(1, $this->sender->versandt, 'kein zweiter Versuch in der naechsten Stunde');

        $this->laufe('2026-10-08');
        $this->assertCount(2, $this->sender->versandt, 'aber einer nach der Pause');
    }

    // =================================================================
    // Die harte Sperre — der HR-Fall
    // =================================================================

    public function test_eine_fehlende_arbeitserlaubnis_oeffnet_einen_hr_fall(): void
    {
        $ma = $this->mitarbeiterOhneNachweise(['is_eu_citizen' => false]);
        $this->einbuchung($ma, ['datum' => '2026-10-20', 'status_id' => 1]);

        $this->laufe('2026-10-01');

        $fall = RecHrDeskCase::query()->where('rec_employee_id', $ma->id)->first();
        $this->assertNotNull($fall);
        $this->assertSame(RecHrDeskCase::REASON_WORK_PERMIT, $fall->reason);
        $this->assertSame(RecHrDeskCase::STATUS_OPEN, $fall->status);
        $this->assertSame(self::TEAM, (int) $fall->team_id);
        $this->assertNull($fall->rec_applicant_id, 'dieser Mensch war nie Bewerber');
    }

    public function test_ein_zweiter_lauf_oeffnet_keinen_zweiten_hr_fall(): void
    {
        $ma = $this->mitarbeiterOhneNachweise(['is_eu_citizen' => false]);
        $this->einbuchung($ma, ['datum' => '2026-10-20', 'status_id' => 1]);

        $this->laufe('2026-10-01');
        $this->laufe('2026-10-02');

        $this->assertSame(1, RecHrDeskCase::query()->where('rec_employee_id', $ma->id)->count());
    }

    /**
     * Die Gegenrichtung zur Dublettenpruefung: ein GESCHLOSSENER Fall darf
     * einen neuen nicht verhindern. Wer freigegeben wurde und dessen
     * Aufenthaltstitel danach wieder fehlt, braucht einen frischen Fall —
     * sonst ist die Sperre nach der ersten Freigabe fuer immer aus.
     */
    public function test_ein_geschlossener_fall_verhindert_keinen_neuen(): void
    {
        $ma = $this->mitarbeiterOhneNachweise(['is_eu_citizen' => false]);
        $this->einbuchung($ma, ['datum' => '2026-10-20', 'status_id' => 1]);

        $this->laufe('2026-10-01');
        RecHrDeskCase::query()->where('rec_employee_id', $ma->id)
            ->update(['status' => RecHrDeskCase::STATUS_APPROVED]);

        $this->laufe('2026-10-02');

        $this->assertSame(2, RecHrDeskCase::query()->where('rec_employee_id', $ma->id)->count());
    }

    /**
     * Ein EU-Buerger ohne Aufenthaltstitel-Pflicht bekommt KEINEN Fall,
     * obwohl ihm Nachweise fehlen. Ohne diese Gegenprobe liesse sich die
     * Sperrpruefung durch ein hartes `true` ersetzen — und dann laegen 1300
     * Menschen auf dem HR-Schreibtisch.
     */
    public function test_ein_fehlender_ausweis_oeffnet_keinen_hr_fall(): void
    {
        $ma = $this->mitarbeiterOhneNachweise(['is_eu_citizen' => true]);
        $this->einbuchung($ma, ['datum' => '2026-10-20', 'status_id' => 1]);

        $this->laufe('2026-10-01');

        $this->assertSame(0, RecHrDeskCase::query()->count());
    }

    /**
     * Der Fall entsteht AUCH OHNE Personen-Zeile. Er haengt am Mitarbeiter,
     * nicht an der Person — und das ist die Haelfte des Triggers, die auch
     * fuer die ZAS-Menschen ohne Personen-Klammer traegt. Die Nachricht
     * bleibt in diesem Fall aus (es gibt keinen Ort, an dem "schon gemeldet"
     * stehen koennte), die harte Sperre nicht.
     */
    public function test_ohne_personen_zeile_entsteht_der_fall_trotzdem(): void
    {
        $ma = $this->mitarbeiterOhneNachweise(['is_eu_citizen' => false, 'rec_person_id' => null]);
        $this->einbuchung($ma, ['datum' => '2026-10-20', 'status_id' => 1]);

        $this->laufe('2026-10-01');

        $this->assertSame(1, RecHrDeskCase::query()->where('rec_employee_id', $ma->id)->count());
        $this->assertSame([], $this->sender->versandt, 'ohne Personen-Zeile gibt es keinen Ort fuer "schon gemeldet"');
        // Die ZAHL, nicht nur das Wort (Mutationsprobe M20): ohne den
        // Abbruch nach dem Zaehler liefe derselbe Mensch zweimal durch und
        // stuende doppelt im Bericht.
        $this->assertStringContainsString('ohne Personen-Zeile: 1 ', $this->ausgabe);
    }

    /**
     * Und zwei Anstellungen derselben Person ergeben EINEN Fall, nicht zwei
     * — die Nachweise gehoeren dem Menschen, also auch die Sperre.
     */
    public function test_zwei_anstellungen_derselben_person_ergeben_einen_fall(): void
    {
        $person = $this->personAnlegen();
        $rg = $this->mitarbeiterOhneNachweise(['rec_person_id' => $person, 'is_eu_citizen' => false]);
        $mg = $this->mitarbeiterOhneNachweise(['rec_person_id' => $person, 'is_eu_citizen' => false]);
        $this->einbuchung($rg, ['datum' => '2026-10-20', 'status_id' => 1]);
        $this->einbuchung($mg, ['datum' => '2026-10-21', 'status_id' => 1]);

        $this->laufe('2026-10-01');

        $this->assertSame(1, RecHrDeskCase::query()->count());
    }

    // =================================================================
    // ET-24 — die Laufsperre
    // =================================================================

    /**
     * `withoutOverlapping(30)` am Zeitplan schuetzt den Zeitplan nur gegen
     * SICH SELBST. Der geplante Betrieb ist aber ein Mischbetrieb:
     * stuendlich prueft der Zeitplan, von Hand werden die Wellen gefahren.
     * Zwei Laeufe gleichzeitig lesen denselben alten Stand (die Signatur
     * wird erst NACH dem Versand geschrieben) und schreiben beide — der
     * Mensch bekaeme die Nachricht zweimal, in derselben Minute, und keine
     * der Bremsen greift.
     */
    public function test_ein_zweiter_lauf_kommt_nicht_dazwischen(): void
    {
        $ma = $this->mitarbeiterOhneNachweise(['is_eu_citizen' => false]);
        $this->einbuchung($ma, ['datum' => '2026-10-20', 'status_id' => 1]);

        $this->sperreBelegen();

        $ergebnis = $this->laufe('2026-10-01');

        $this->assertSame([], $this->sender->versandt);
        $this->assertNull($this->person($ma)->aufgaben_signatur);
        $this->assertSame(0, RecHrDeskCase::query()->count(), 'auch die Fall-Anlage bleibt aus');
        $this->assertStringContainsString('laeuft bereits', $this->ausgabe);

        // SUCCESS, nicht FAILURE: eine Kollision ist der vorgesehene
        // Betrieb, und die Arbeit holt der naechste Lauf nach. Mit FAILURE
        // schlueg der Zeitplan jedes Mal Alarm, wenn jemand von Hand eine
        // Welle faehrt.
        $this->assertSame(Command::SUCCESS, $ergebnis);
    }

    /**
     * Die Gegenrichtung: die Sperre wird am Ende wieder freigegeben. Ohne
     * das Freigeben liefe nach dem ersten Lauf nie wieder etwas — und zwar
     * bis zum Verfall des Schlosses, also eine halbe Stunde lang.
     */
    public function test_die_sperre_wird_am_ende_wieder_freigegeben(): void
    {
        $a = $this->mitarbeiterOhneNachweise();
        $this->einbuchung($a, ['datum' => '2026-10-20', 'status_id' => 1]);
        $this->laufe('2026-10-01');
        $this->assertCount(1, $this->sender->versandt);

        $b = $this->mitarbeiterOhneNachweise();
        $this->einbuchung($b, ['datum' => '2026-10-20', 'status_id' => 1]);
        $this->laufe('2026-10-02');

        $this->assertCount(2, $this->sender->versandt);
        $this->assertSame($b->id, $this->sender->versandt[1]['ma']);
    }

    /** Und ein Trockenlauf laeuft auch neben einem scharfen Lauf. */
    public function test_der_trockenlauf_braucht_die_sperre_nicht(): void
    {
        $ma = $this->mitarbeiterOhneNachweise();
        $this->einbuchung($ma, ['datum' => '2026-10-20', 'status_id' => 1]);

        $this->sperreBelegen();

        $this->laufe('2026-10-01', ['--dry-run' => true]);

        $this->assertStringContainsString('Faellig: 1 Mensch(en)', $this->ausgabe);
        $this->assertStringNotContainsString('laeuft bereits', $this->ausgabe);
        $this->assertSame([], $this->sender->versandt);
    }

    // =================================================================
    // F5 / F6 — der Trockenlauf und die vierte Sackgasse
    // =================================================================

    /**
     * F6 — DIE VIERTE SACKGASSE. Ohne Personen-Zeile sprang der `continue`
     * ueber die GANZE Schleife, also auch ueber die Erinnerung. Die
     * Begruendung („es gibt keinen Ort, an dem ‚schon gemeldet' stehen
     * koennte") traegt nur fuer die neue Nachricht: der Zustand der
     * Erinnerung liegt in `rec_dispo_assignments.aufgaben_erinnert_at` und
     * braucht keine Personen-Zeile.
     *
     * Fuer diese Menschen ist die Erinnerung der EINZIGE Kanal, und die
     * Gruppe waechst nach: neue Anstellungen aus Funnel und ZAS entstehen
     * weiterhin ohne Personen-Zeile. Jeder neue Mitarbeiter war ab Anlage
     * dauerhaft stumm.
     */
    public function test_ohne_personen_zeile_geht_die_erinnerung_trotzdem_raus(): void
    {
        $ma = $this->mitarbeiterOhneNachweise(['rec_person_id' => null]);
        $einbuchung = $this->einbuchung($ma, ['datum' => '2026-10-02', 'status_id' => 1]);

        $this->laufe('2026-10-01');

        $this->assertCount(1, $this->sender->versandt);
        $this->assertSame('erinnerung', $this->sender->versandt[0]['anlass']);
        $this->assertNotNull(
            DB::table('rec_dispo_assignments')->where('id', $einbuchung)->value('aufgaben_erinnert_at'),
            'die Wiederholungsbremse der Erinnerung braucht keine Personen-Zeile',
        );
        $this->assertStringContainsString('ohne Personen-Zeile: 1 ', $this->ausgabe);
    }

    /**
     * Die Gegenrichtung, die zugleich zeigt, dass die alte Begruendung fuer
     * die NEUE Nachricht weiter gilt: ohne Personen-Zeile wird sie nicht
     * verschickt, denn es gaebe keinen Ort fuer „schon gemeldet" und sie
     * ginge in jedem stuendlichen Lauf erneut raus.
     */
    public function test_ohne_personen_zeile_bleibt_die_neue_nachricht_aus(): void
    {
        $ma = $this->mitarbeiterOhneNachweise(['rec_person_id' => null]);
        $this->einbuchung($ma, ['datum' => '2026-10-20', 'status_id' => 1]);

        $this->laufe('2026-10-01');
        $this->laufe('2026-10-02');

        $this->assertSame([], $this->sender->versandt);
    }

    /**
     * F5 — DER TROCKENLAUF DARF NICHT UNTERTREIBEN. Die ET-23-Raeumung
     * setzte `$letzteSignatur = null` nur im scharfen Zweig; der
     * Trockenlauf rechnete deshalb mit der alten Signatur weiter und meldete
     * „Faellig: 0", wo der scharfe Lauf „Faellig: 1" meldet. Das ist genau
     * die Zahl, nach der jemand die Wellengroesse bemisst.
     */
    public function test_der_trockenlauf_zaehlt_dieselben_faelligen_wie_der_scharfe_lauf(): void
    {
        $ma = $this->mitarbeiterOhneNachweise();
        $this->einbuchung($ma, ['datum' => '2026-11-20', 'status_id' => 1]);

        $this->sender->nachrichtId = $this->nachrichtAnlegen('sent');
        $this->laufe('2026-10-01');

        // Meta meldet die Nichtzustellung per Webhook nach.
        DB::table('comms_whatsapp_messages')->where('id', $this->sender->nachrichtId)->update(['status' => 'failed']);

        $this->laufe('2026-10-20', ['--dry-run' => true]);
        $this->assertStringContainsString('Faellig: 1 Mensch(en) fuer eine neue Nachricht', $this->letzteAusgabe);

        // Und der scharfe Lauf tut dann auch wirklich, was der Trockenlauf
        // angekuendigt hat.
        $this->laufe('2026-10-20');
        $this->assertStringContainsString('Faellig: 1 Mensch(en) fuer eine neue Nachricht', $this->letzteAusgabe);
        $this->assertCount(2, $this->sender->versandt);
    }

    // =================================================================
    // Die Betriebsvorgaben
    // =================================================================

    public function test_dry_run_schreibt_nichts_und_verschickt_nichts(): void
    {
        $ma = $this->mitarbeiterOhneNachweise(['is_eu_citizen' => false]);
        $this->einbuchung($ma, ['datum' => '2026-10-20', 'status_id' => 1]);

        $this->laufe('2026-10-01', ['--dry-run' => true, '--welle' => 10]);

        $this->assertSame([], $this->sender->versandt);
        $this->assertNull($this->person($ma)->aufgaben_signatur);
        $this->assertSame(0, RecHrDeskCase::query()->count());
    }

    /**
     * Die Gegenprobe zum Trockenlauf: derselbe Aufbau OHNE --dry-run
     * schreibt und verschickt sehr wohl. Ohne sie koennte das Kommando in
     * jedem Lauf nichts tun, und der Trockenlauf-Test waere gruen.
     */
    public function test_ohne_dry_run_wird_geschrieben_und_verschickt(): void
    {
        $ma = $this->mitarbeiterOhneNachweise(['is_eu_citizen' => false]);
        $this->einbuchung($ma, ['datum' => '2026-10-20', 'status_id' => 1]);

        $this->laufe('2026-10-01', ['--welle' => 10]);

        $this->assertCount(1, $this->sender->versandt);
        $this->assertNotNull($this->person($ma)->aufgaben_signatur);
        $this->assertSame(1, RecHrDeskCase::query()->count());
    }

    /**
     * Auch der Trockenlauf raeumt NICHT — das Raeumen der Signatur ist ein
     * Schreibvorgang wie jeder andere.
     */
    public function test_dry_run_raeumt_die_signatur_nicht(): void
    {
        $ma = $this->mitarbeiterOhneNachweise();
        $this->einbuchung($ma, ['datum' => '2026-01-20', 'status_id' => 1]);
        $this->laufe('2026-01-01');

        $this->alleNachweiseErbringen($ma, '2026-01-30', '2026-07-19');
        $this->laufe('2026-01-30', ['--dry-run' => true]);

        $this->assertNotNull($this->person($ma)->aufgaben_signatur);
    }

    public function test_ohne_welle_wird_niemand_angeschrieben(): void
    {
        $this->mehrereMitAuftrag(3);

        $this->laufeOhneWelle('2026-10-01');

        $this->assertSame([], $this->sender->versandt);
        // Die Pruefung lief trotzdem: die Ausgabe nennt die drei Faelle.
        $this->assertStringContainsString('3', $this->ausgabe);
    }

    public function test_die_welle_haelt_ihre_grenze(): void
    {
        $this->mehrereMitAuftrag(5);

        $this->laufe('2026-10-01', ['--welle' => 2]);

        $this->assertCount(2, $this->sender->versandt);
    }

    /**
     * Die Welle deckelt MENSCHEN, nicht Nachrichten — und sie darf die
     * uebrigen nicht als "schon gemeldet" zuruecklassen. Wer in dieser Welle
     * nicht drankam, muss im naechsten Lauf drankommen.
     */
    public function test_wer_nicht_in_die_welle_passt_bleibt_ungemeldet(): void
    {
        $this->mehrereMitAuftrag(3);

        $this->laufe('2026-10-01', ['--welle' => 1]);
        $this->assertCount(1, $this->sender->versandt);

        $this->laufe('2026-10-01', ['--welle' => 5]);
        $this->assertCount(3, $this->sender->versandt);
    }

    public function test_ein_stolpernder_datensatz_beendet_den_lauf_nicht(): void
    {
        $kaputt = $this->mitarbeiterOhneNachweise();
        $this->einbuchung($kaputt, ['datum' => 'kein-datum', 'status_id' => 1]);

        $gut = $this->mitarbeiterOhneNachweise();
        $this->einbuchung($gut, ['datum' => '2026-10-20', 'status_id' => 1]);

        $ergebnis = $this->laufe('2026-10-01', ['--welle' => 10]);

        // Der gute Datensatz wurde bedient, der Lauf meldet den Fehlschlag.
        $this->assertCount(1, $this->sender->versandt);
        $this->assertSame($gut->id, $this->sender->versandt[0]['ma']);
        $this->assertSame(Command::FAILURE, $ergebnis);
    }

    /**
     * Die Gegenrichtung zum Rueckgabewert: ohne Stolperstein meldet der Lauf
     * Erfolg. An diesem Wert haengt, ob ein Zeitplan Alarm schlaegt — er
     * zaehlt, nicht der Text der Ausgabe.
     */
    public function test_ein_sauberer_lauf_meldet_erfolg(): void
    {
        $ma = $this->mitarbeiterOhneNachweise();
        $this->einbuchung($ma, ['datum' => '2026-10-20', 'status_id' => 1]);

        $this->assertSame(Command::SUCCESS, $this->laufe('2026-10-01', ['--welle' => 10]));
    }

    public function test_die_pruefung_setzt_keinen_zas_marker(): void
    {
        $ma = $this->mitarbeiterOhneNachweise(['is_eu_citizen' => false]);
        $this->einbuchung($ma, ['datum' => '2026-10-20', 'status_id' => 1]);

        // Vorflug: der Beobachter ist registriert und wuerde anspringen.
        $this->assertTrue($this->beobachterIstScharf());

        DB::table('rec_employees')->where('id', $ma->id)->update(['updated_at' => self::ANGEFASST]);

        $this->laufe('2026-10-01', ['--welle' => 10]);

        // Erst der Beleg, DASS etwas passiert ist — sonst waere diese Probe
        // auch gruen, wenn das Kommando gar nichts getan haette.
        $this->assertCount(1, $this->sender->versandt);
        $this->assertNotNull($this->person($ma)->aufgaben_signatur);
        $this->assertSame(1, RecHrDeskCase::query()->count());

        $zeile = DB::table('rec_employees')->where('id', $ma->id)->first();

        // Diese Zeile traegt: ein Eloquent-Schreibweg wuerde updated_at mitziehen.
        $this->assertSame(self::ANGEFASST, (string) $zeile->updated_at);
        // Nachrangig: phone und die Pruefspalten stehen bewusst NICHT in
        // RELEVANT_EMPLOYEE_FIELDS, der Marker allein bewiese also nichts.
        $this->assertNull($zeile->zas_changed_at);
    }

    /**
     * Dasselbe fuer die beiden anderen Tabellen, in die das Kommando
     * schreibt: die Personen-Zeile und die Einbuchung. Auch dort gilt
     * observer-frei — und `updated_at` ist der feldunabhaengige Beleg.
     */
    public function test_die_pruefung_fasst_updated_at_nirgends_an(): void
    {
        $ma = $this->mitarbeiterOhneNachweise();
        $this->einbuchung($ma, ['datum' => '2026-10-10', 'status_id' => 1]);

        DB::table('rec_persons')->where('id', $ma->rec_person_id)->update(['updated_at' => self::ANGEFASST]);
        DB::table('rec_dispo_assignments')->update(['updated_at' => self::ANGEFASST]);

        $this->laufe('2026-10-01', ['--welle' => 10]);
        $this->laufe('2026-10-08', ['--welle' => 10]);

        $this->assertCount(2, $this->sender->versandt, 'Vorflug: es wurde wirklich beides geschrieben');
        $this->assertNotNull($this->person($ma)->aufgaben_signatur);
        $this->assertNotNull(DB::table('rec_dispo_assignments')->value('aufgaben_erinnert_at'));

        $this->assertSame(self::ANGEFASST, (string) DB::table('rec_persons')->where('id', $ma->rec_person_id)->value('updated_at'));
        $this->assertSame(self::ANGEFASST, (string) DB::table('rec_dispo_assignments')->value('updated_at'));
    }

    /**
     * NICHT-EU, und das ist der Punkt (Pruefung Runde 1, A22): die Ausgabe
     * hat ZWEI Zeilen mit Personenbezug — die der Angeschriebenen und die
     * der Gesperrten. Mit einem EU-Mitarbeiter wurde die zweite nie
     * erreicht, ein Name liess sich dort einsetzen, ohne dass etwas fiel.
     * Der Vorflug unten belegt, dass beide Zeilen wirklich entstanden sind.
     */
    public function test_die_ausgabe_nennt_keine_namen(): void
    {
        $ma = $this->mitarbeiterOhneNachweise([
            'first_name'    => 'Hannelore',
            'last_name'     => 'Kowalski',
            'is_eu_citizen' => false,
        ]);
        $this->einbuchung($ma, ['datum' => '2026-10-20', 'status_id' => 1]);

        $this->laufe('2026-10-01', ['--welle' => 10]);

        // Vorflug: BEIDE Zeilen mit Personenbezug sind entstanden.
        $this->assertStringContainsString('(neu: sent)', $this->ausgabe, 'die Versand-Zeile fehlt');
        $this->assertStringContainsString('Arbeitserlaubnis fehlt: 1 Mensch(en)', $this->ausgabe, 'die Sperr-Zeile fehlt');
        $this->assertStringContainsString('(aufenthaltstitel', $this->ausgabe, 'die Sperr-Zeile nennt den Grund');

        $this->assertStringNotContainsString('Hannelore', $this->ausgabe);
        $this->assertStringNotContainsString('Kowalski', $this->ausgabe);
        // Und die Kennung steht sehr wohl da — ohne sie liesse sich nach
        // einem Lauf ueber hunderte Menschen nicht feststellen, wer gemeint
        // ist (derselbe Schnitt wie im AufgabenSender).
        $this->assertStringContainsString('#'.$ma->id, $this->ausgabe);
    }

    /**
     * Auch keine volle Rufnummer. Gegenprobe im selben Test: der Name der
     * Nummer taucht nirgends auf, aber die Kennung schon — sonst waere die
     * Zusicherung mit einer leeren Ausgabe erfuellt.
     */
    public function test_die_ausgabe_nennt_keine_volle_rufnummer(): void
    {
        $ma = $this->mitarbeiterOhneNachweise([
            'phone'         => '+4915199887766',
            'is_eu_citizen' => false,
        ]);
        $this->einbuchung($ma, ['datum' => '2026-10-20', 'status_id' => 1]);

        $this->laufe('2026-10-01', ['--welle' => 10]);

        // Vorflug wie oben: beide Zeilen mit Personenbezug sind entstanden
        // (A23b — mit einem EU-Mitarbeiter blieb die Sperr-Zeile ungedeckt).
        $this->assertStringContainsString('(neu: sent)', $this->ausgabe);
        $this->assertStringContainsString('Arbeitserlaubnis fehlt: 1 Mensch(en)', $this->ausgabe);

        $this->assertStringNotContainsString('99887766', $this->ausgabe);
        $this->assertStringNotContainsString('+4915199887766', $this->ausgabe);
        $this->assertStringContainsString('#'.$ma->id, $this->ausgabe);
    }

    /**
     * D1 — DER ERSTE LAUF NACH DEM DEPLOY. Am Tag der Auslieferung sind die
     * Meta-Vorlagen noch nicht genehmigt, der Sender meldet also
     * `nicht_konfiguriert`. Schriebe das Kommando dafuer einen Stempel,
     * verzoegerte sich der echte Start um eine ganze Pause — sieben Tage, in
     * denen niemand erfaehrt, was ihm fehlt. Und es MUSS sofort losgehen,
     * sobald die Vorlage eingetragen ist: der zweite Teil misst genau das.
     */
    public function test_ohne_meta_vorlage_wird_weder_signatur_noch_stempel_geschrieben(): void
    {
        $ma = $this->mitarbeiterOhneNachweise();
        $this->einbuchung($ma, ['datum' => '2026-11-20', 'status_id' => 1]);

        $this->sender->antwort = AufgabenSender::STATUS_NICHT_KONFIGURIERT;
        $this->laufe('2026-10-01');

        $this->assertCount(1, $this->sender->versandt, 'Vorflug: versucht wurde es');
        $this->assertNull($this->person($ma)->aufgaben_signatur);
        $this->assertNull($this->person($ma)->aufgaben_gemeldet_at, 'ein Stempel hier kostet sieben Tage');

        // Und am naechsten Tag ist die Vorlage da: es geht SOFORT los, ohne
        // Wartezeit.
        $this->sender->antwort = AufgabenSender::STATUS_SENT;
        $this->laufe('2026-10-02');

        $this->assertCount(2, $this->sender->versandt);
        $this->assertNotNull($this->person($ma)->aufgaben_signatur);
    }

    /**
     * F1, von der Seite des Kommandos: eine untaugliche Vorlage (kein
     * Portal-Link) darf genauso wenig stempeln wie eine fehlende. Sonst
     * machte ein Konfigurationsfehler aus dem stuendlichen Lauf eine
     * woechentlich wiederholte, aussichtslose Versandwelle ueber den ganzen
     * Bestand — und sobald die Vorlage richtig steht, soll es ohne
     * Wartezeit losgehen.
     */
    public function test_eine_untaugliche_vorlage_wird_weder_signatur_noch_stempel(): void
    {
        $ma = $this->mitarbeiterOhneNachweise();
        $this->einbuchung($ma, ['datum' => '2026-11-20', 'status_id' => 1]);

        $this->sender->antwort = AufgabenSender::STATUS_VORLAGE_UNTAUGLICH;
        $this->laufe('2026-10-01');

        $this->assertCount(1, $this->sender->versandt, 'Vorflug: versucht wurde es');
        $this->assertNull($this->person($ma)->aufgaben_signatur);
        $this->assertNull($this->person($ma)->aufgaben_gemeldet_at);

        $this->sender->antwort = AufgabenSender::STATUS_SENT;
        $this->laufe('2026-10-02');

        $this->assertCount(2, $this->sender->versandt);
        $this->assertNotNull($this->person($ma)->aufgaben_signatur);
    }

    /**
     * A8 — DER TOTE VERWEIS. Der Docblock verspricht: eine Nachricht, die es
     * nicht mehr gibt, gilt als "nichts nachzulesen" und NICHT als
     * Fehlschlag. Sonst loeste das Aufraeumen alter Nachrichten eine
     * Versandwelle aus. Geprueft wird das ueber die Abwehr selbst: mit
     * `!== 'sent'` statt `=== 'failed'` wuerde hier geraeumt und nach der
     * Pause ein zweites Mal gesendet.
     */
    public function test_ein_toter_verweis_raeumt_die_signatur_nicht(): void
    {
        $ma = $this->mitarbeiterOhneNachweise();
        $this->einbuchung($ma, ['datum' => '2026-11-20', 'status_id' => 1]);

        $this->sender->nachrichtId = $this->nachrichtAnlegen('sent');
        $this->laufe('2026-10-01');
        $this->assertNotNull($this->person($ma)->aufgaben_signatur);

        // Die Nachricht wird aufgeraeumt, der Verweis zeigt ins Leere.
        DB::table('comms_whatsapp_messages')->where('id', $this->sender->nachrichtId)->delete();

        $this->laufe('2026-10-08');   // die Pause waere um

        $this->assertNotNull($this->person($ma)->aufgaben_signatur);
        $this->assertCount(1, $this->sender->versandt);
    }

    /**
     * Und dieselbe Abwehr fuer einen Zwischenstatus: solange Meta noch nicht
     * gemeldet hat, dass die Zustellung gescheitert ist, gilt der Mensch als
     * informiert. Nur `failed` raeumt.
     */
    public function test_eine_noch_unentschiedene_nachricht_raeumt_die_signatur_nicht(): void
    {
        $ma = $this->mitarbeiterOhneNachweise();
        $this->einbuchung($ma, ['datum' => '2026-11-20', 'status_id' => 1]);

        $this->sender->nachrichtId = $this->nachrichtAnlegen('sent');
        $this->laufe('2026-10-01');

        DB::table('comms_whatsapp_messages')
            ->where('id', $this->sender->nachrichtId)
            ->update(['status' => 'pending']);

        $this->laufe('2026-10-08');

        $this->assertNotNull($this->person($ma)->aufgaben_signatur);
        $this->assertCount(1, $this->sender->versandt);
    }

    /**
     * D2 — `--team=` im ZWEITEN Zweig des Zielkreises. Der Einsatz-Zweig war
     * gemessen, der Signatur-Zweig nicht: wer eine Signatur traegt, wurde
     * auch bei `--team=3` aus jedem anderen Team mitgezogen und voll
     * bedient.
     *
     * Gemessen wird am sichtbarsten Eingriff, den dieser Zweig hat: dem
     * Raeumen der Signatur bei leerer Liste. Der fremde Mensch hat KEINEN
     * kommenden Auftrag — er kaeme also ausschliesslich ueber den
     * Signatur-Zweig in den Lauf.
     */
    public function test_der_team_filter_greift_auch_im_signatur_zweig(): void
    {
        $fremder = $this->mitarbeiterOhneNachweise(['team_id' => self::TEAM + 1]);
        $this->alleNachweiseErbringen($fremder, '2026-10-01', '2027-06-01');
        DB::table('rec_persons')->where('id', $fremder->rec_person_id)->update([
            'aufgaben_signatur'    => str_repeat('a', 64),
            'aufgaben_gemeldet_at' => '2026-09-01 09:00:00',
        ]);

        $this->laufe('2026-10-01', ['--team' => (string) self::TEAM]);

        $this->assertNotNull(
            $this->person($fremder)->aufgaben_signatur,
            'das fremde Team darf der Lauf nicht anfassen',
        );

        // Die Gegenrichtung im selben Test: OHNE --team wird er sehr wohl
        // geprueft und seine Signatur geraeumt. Ohne diese Haelfte waere die
        // Zusicherung oben auch mit einem Lauf erfuellt, der gar nichts tut.
        $this->laufe('2026-10-02');

        $this->assertNull($this->person($fremder)->aufgaben_signatur);
    }

    public function test_der_team_filter_laesst_andere_teams_unberuehrt(): void
    {
        $meiner = $this->mitarbeiterOhneNachweise();
        $fremder = $this->mitarbeiterOhneNachweise(['team_id' => self::TEAM + 1]);
        $this->einbuchung($meiner, ['datum' => '2026-10-20', 'status_id' => 1]);
        $this->einbuchung($fremder, ['datum' => '2026-10-20', 'status_id' => 1]);

        $this->laufe('2026-10-01', ['--welle' => 10, '--team' => (string) self::TEAM]);

        $this->assertCount(1, $this->sender->versandt);
        $this->assertSame($meiner->id, $this->sender->versandt[0]['ma']);
    }

    /**
     * Ohne --team laufen BEIDE Teams mit. Ohne diese Gegenprobe liesse sich
     * der Team-Filter so verdrehen, dass er immer greift.
     */
    public function test_ohne_team_filter_laufen_alle_teams(): void
    {
        $meiner = $this->mitarbeiterOhneNachweise();
        $fremder = $this->mitarbeiterOhneNachweise(['team_id' => self::TEAM + 1]);
        $this->einbuchung($meiner, ['datum' => '2026-10-20', 'status_id' => 1]);
        $this->einbuchung($fremder, ['datum' => '2026-10-20', 'status_id' => 1]);

        $this->laufe('2026-10-01', ['--welle' => 10]);

        $this->assertCount(2, $this->sender->versandt);
    }

    public function test_der_ids_filter_nimmt_nur_die_genannten(): void
    {
        $a = $this->mitarbeiterOhneNachweise();
        $b = $this->mitarbeiterOhneNachweise();
        $this->einbuchung($a, ['datum' => '2026-10-20', 'status_id' => 1]);
        $this->einbuchung($b, ['datum' => '2026-10-20', 'status_id' => 1]);

        $this->laufe('2026-10-01', ['--welle' => 10, '--ids' => (string) $b->id]);

        $this->assertCount(1, $this->sender->versandt);
        $this->assertSame($b->id, $this->sender->versandt[0]['ma']);
    }

    /**
     * Das alte Portal: dort fuehrt der Knopf auf eine 404-Seite. Eine
     * Nachricht "schau ins Portal" an jemanden ohne portal_v2_since waere
     * eine Sackgasse — und sie wuerde den Menschen zugleich als "informiert"
     * abhaken. Die PRUEFUNG laeuft trotzdem: der HR-Fall entsteht.
     */
    public function test_das_alte_portal_bekommt_keine_nachricht_aber_den_hr_fall(): void
    {
        $ma = $this->mitarbeiterOhneNachweise(['is_eu_citizen' => false, 'portal_v2_since' => null]);
        $this->einbuchung($ma, ['datum' => '2026-10-20', 'status_id' => 1]);

        $this->laufe('2026-10-01', ['--welle' => 10]);

        $this->assertSame([], $this->sender->versandt);
        $this->assertNull($this->person($ma)->aufgaben_signatur);
        $this->assertSame(1, RecHrDeskCase::query()->count());
        $this->assertStringContainsString('altes Portal', $this->ausgabe);
    }

    /**
     * ET-17: den blinden Fleck sichtbar machen. Ohne `rec_person_id`
     * verlangt Zweig 2 des PersonScopeResolver zusaetzlich dieselbe
     * Handynummer — weicht sie ab, verschwindet der Einsatz der
     * Schwester-Anstellung spurlos. Geheilt wird das hier NICHT (das waere
     * eine Entscheidung darueber, WER der Mensch ist, und die gehoert in
     * rec_person_id); gezaehlt und genannt wird es.
     */
    public function test_der_blinde_fleck_wird_gezaehlt_und_genannt(): void
    {
        $ma = $this->mitarbeiterOhneNachweise(['rec_person_id' => null, 'person_key' => 'p-geteilt', 'phone' => '+4915111111111']);
        $this->mitarbeiterOhneNachweise(['rec_person_id' => null, 'person_key' => 'p-geteilt', 'phone' => '+4915122222222']);
        $this->einbuchung($ma, ['datum' => '2026-10-20', 'status_id' => 1]);

        $this->laufe('2026-10-01', ['--welle' => 10]);

        $this->assertStringContainsString('abweichender Nummer: 1', $this->ausgabe);
    }

    /**
     * Die Gegenrichtung: stimmt die Nummer ueberein, ist nichts abweichend —
     * sonst waere die Zahl oben eine Konstante und keine Messung.
     */
    public function test_ohne_abweichende_nummer_bleibt_der_fleck_bei_null(): void
    {
        $ma = $this->mitarbeiterOhneNachweise(['rec_person_id' => null, 'person_key' => 'p-geteilt', 'phone' => '+4915111111111']);
        $this->mitarbeiterOhneNachweise(['rec_person_id' => null, 'person_key' => 'p-geteilt', 'phone' => '+4915111111111']);
        $this->einbuchung($ma, ['datum' => '2026-10-20', 'status_id' => 1]);

        $this->laufe('2026-10-01', ['--welle' => 10]);

        $this->assertStringContainsString('abweichender Nummer: 0', $this->ausgabe);
    }

    /**
     * Ohne diese beiden Zeilen gibt es die Datei, aber kein Kommando — und
     * der Zeitplan ruft ins Leere, waehrend jeder Test hier gruen bleibt
     * (Muster KontoZuruecksetzenTest::test_das_kommando_ist_registriert).
     */
    public function test_das_kommando_ist_registriert_und_steht_im_zeitplan(): void
    {
        $quelle = file_get_contents(dirname(__DIR__, 2).'/src/RecruitingServiceProvider.php');

        $this->assertStringContainsString('Commands\\EinsatzPruefung::class', $quelle);
        $this->assertStringContainsString("Schedule::command('recruiting:einsatz-pruefung')", $quelle);
    }

    /**
     * Und der Zeitplan-Eintrag traegt KEIN --welle. Das ist eine
     * Entscheidung, keine Vergesslichkeit: ohne die Flagge laeuft stuendlich
     * nur die Pruefung, und niemand wird angeschrieben. Wer hier --welle
     * ergaenzt, schaltet den Versand ueber den ganzen Bestand scharf — das
     * soll ihm auffallen, und zwar hier.
     */
    public function test_der_zeitplan_schaltet_den_versand_nicht_scharf(): void
    {
        $quelle = file_get_contents(dirname(__DIR__, 2).'/src/RecruitingServiceProvider.php');

        // DIE SACHE, NICHT DER WORTLAUT (Pruefung Runde 1): die vorige
        // Fassung suchte nach der Zeichenkette
        // 'recruiting:einsatz-pruefung --welle' und waere bei
        // ->command('recruiting:einsatz-pruefung', ['--welle' => 50]) gruen
        // geblieben. Geprueft wird jetzt der GANZE Eintrag bis zum
        // abschliessenden Semikolon — in welcher Schreibweise die Flagge
        // auch stuende, sie faellt auf.
        $anfang = strpos($quelle, "Schedule::command('recruiting:einsatz-pruefung'");
        $this->assertNotFalse($anfang, 'Es gibt keinen Zeitplan-Eintrag fuer das Kommando.');

        $ende = strpos($quelle, ';', $anfang);
        $eintrag = substr($quelle, $anfang, $ende - $anfang);

        $this->assertStringNotContainsString(
            'welle',
            $eintrag,
            'Der Zeitplan-Eintrag traegt eine Welle und schaltet damit den Versand ueber den '
            .'ganzen Bestand scharf. Das ist eine Entscheidung, kein Versehen — und sie gehoert '
            .'nicht hierher, solange die Meta-Vorlagen nicht genehmigt sind.',
        );
    }

    /**
     * Die Attrappe darf nicht grosszuegiger sein als der Wirt: haette der
     * echte Sender eine andere Aufrufform, liefen alle Tests darueber ins
     * Leere. Muster: AufgabenSenderTest::testAttrappenSignaturPasstZuSendTemplate.
     */
    public function test_die_sender_attrappe_passt_zum_echten_sender(): void
    {
        foreach (['sende', 'letzteNachrichtId'] as $methode) {
            $echt = new \ReflectionMethod(AufgabenSender::class, $methode);
            $attrappe = new \ReflectionMethod($this->sender, $methode);

            $this->assertSame(
                array_map(fn (\ReflectionParameter $p) => $p->getName().':'.$p->getType(), $echt->getParameters()),
                array_map(fn (\ReflectionParameter $p) => $p->getName().':'.$p->getType(), $attrappe->getParameters()),
                "Die Attrappe von {$methode}() weicht vom echten Sender ab.",
            );
            $this->assertSame((string) $echt->getReturnType(), (string) $attrappe->getReturnType());
        }
    }

    // =================================================================
    // Werkzeug
    // =================================================================

    /**
     * Ein Lauf MIT Welle — die Vorgabe fuer alle Tests, die etwas ueber den
     * Versand aussagen.
     *
     * WARUM DIE VORGABE: ohne --welle geht nichts raus, das ist die Bremse
     * des Kommandos. Stuende sie in jedem Test, pruefte jeder "es geht
     * nichts raus"-Test in Wahrheit nur die Bremse und nicht seine eigene
     * Regel — die Vorlaufpruefung, der Angebots-Filter und die Pause waeren
     * alle tautologisch gruen. Die Bremse selbst pruefen deshalb genau zwei
     * eigene Tests, ueber laufeOhneWelle() und ueber --welle=2.
     *
     * @return int Rueckgabewert des Kommandos
     */
    private function laufe(string $datum, array $optionen = []): int
    {
        return $this->laufRoh($datum, $optionen + ['--welle' => 100]);
    }

    /** Ein Lauf OHNE jede Welle-Angabe — da darf niemand angeschrieben werden. */
    private function laufeOhneWelle(string $datum): int
    {
        return $this->laufRoh($datum, []);
    }

    private function laufRoh(string $datum, array $optionen): int
    {
        Carbon::setTestNow($datum.' 09:00:00');

        $command = new EinsatzPruefung();
        $command->setLaravel(new EinsatzPruefungFakeLaravel());

        $input = new ArrayInput($optionen, $command->getDefinition());
        $output = new BufferedOutput();

        $ergebnis = $command->run($input, $output);
        $this->letzteAusgabe = $output->fetch();
        $this->ausgabe .= $this->letzteAusgabe;

        return $ergebnis;
    }

    /**
     * Ein Cache-Schloss, das sich wie eines verhaelt: get() nur beim ersten
     * Mal, release() gibt frei. Eine Attrappe, die IMMER true liefert, waere
     * grosszuegiger als der Wirt und machte jede Sperr-Zusicherung wertlos.
     */
    private function cacheAttrappe(): object
    {
        return new class {
            /** @var array<string, true> */
            public array $gehalten = [];

            public function lock(string $name, int $sekunden = 0): object
            {
                return new class($this, $name) {
                    public function __construct(private object $speicher, private string $name) {}

                    public function get(): bool
                    {
                        if (isset($this->speicher->gehalten[$this->name])) {
                            return false;
                        }

                        $this->speicher->gehalten[$this->name] = true;

                        return true;
                    }

                    public function release(): bool
                    {
                        unset($this->speicher->gehalten[$this->name]);

                        return true;
                    }
                };
            }
        };
    }

    /** Jemand anderes haelt gerade die Laufsperre. */
    private function sperreBelegen(): void
    {
        $this->assertTrue(
            $this->cache->lock('recruiting:einsatz-pruefung:lauf', 1800)->get(),
            'Vorflug: die Sperre war vorher frei.',
        );
    }

    /** Die „Faellig:"-Zeile des letzten Laufs — die Zahlen, nach denen jemand plant. */
    private function faelligZeile(): string
    {
        foreach (preg_split('/\R/', $this->letzteAusgabe) ?: [] as $zeile) {
            if (str_starts_with(trim($zeile), 'Faellig:')) {
                return trim($zeile);
            }
        }

        $this->fail('Der Bericht hat keine "Faellig:"-Zeile.');
    }

    private function senderAttrappe(): object
    {
        return new class {
            /** @var list<array{ma:int, anlass:string, stand:array}> */
            public array $versandt = [];

            public string $antwort = AufgabenSender::STATUS_SENT;

            public ?int $nachrichtId = null;

            private ?int $letzte = null;

            public function sende(RecEmployee $employee, array $stand, string $anlass): string
            {
                $this->versandt[] = ['ma' => (int) $employee->id, 'anlass' => $anlass, 'stand' => $stand];
                $this->letzte = $this->antwort === AufgabenSender::STATUS_SENT ? $this->nachrichtId : null;

                return $this->antwort;
            }

            public function letzteNachrichtId(): ?int
            {
                return $this->letzte;
            }
        };
    }

    /**
     * Springt der Beobachter in DIESER Umgebung ueberhaupt an? Ohne diesen
     * Beleg prueft test_die_pruefung_setzt_keinen_zas_marker eine Umgebung,
     * die den Unterschied gar nicht herstellen kann — in diesem Zweig schon
     * dreimal passiert.
     *
     * Der Proband ist ein eigener Mitarbeiter ohne Einbuchung und ohne
     * Personen-Zeile; er kommt im Lauf selbst nicht vor.
     */
    private function beobachterIstScharf(): bool
    {
        $proband = $this->mitarbeiterOhneNachweise(['rec_person_id' => null, 'person_key' => null]);
        $proband->nationality = 'DE';     // steht in RELEVANT_EMPLOYEE_FIELDS
        $proband->save();

        return DB::table('rec_employees')->where('id', $proband->id)->value('zas_changed_at') !== null;
    }

    private function personAnlegen(): int
    {
        return (int) DB::table('rec_persons')->insertGetId([
            'uuid'       => 'p-'.$this->naechsteId++,
            'team_id'    => self::TEAM,
            'created_at' => self::ANGEFASST,
            'updated_at' => self::ANGEFASST,
        ]);
    }

    /** Aktiver EU-Aushilfe mit Personen-Zeile, neuem Portal und ohne einen einzigen Nachweis. */
    private function mitarbeiterOhneNachweise(array $attr = []): RecEmployee
    {
        if (!array_key_exists('rec_person_id', $attr)) {
            $attr['rec_person_id'] = $this->personAnlegen();
        }

        $id = (int) DB::table('rec_employees')->insertGetId(array_merge([
            'uuid'             => 'e-'.$this->naechsteId++,
            'team_id'          => self::TEAM,
            'phone'            => '+49151'.str_pad((string) $this->naechsteId++, 8, '0', STR_PAD_LEFT),
            'is_active'        => true,
            'is_eu_citizen'    => true,
            'employment_type'  => 'aushilfe',
            'is_first_aider'   => false,
            'portal_v2_since'  => '2026-09-24 00:00:00',
            'created_at'       => self::ANGEFASST,
            'updated_at'       => self::ANGEFASST,
        ], $attr));

        return RecEmployee::find($id);
    }

    private function einbuchung(RecEmployee $employee, array $attr = []): int
    {
        $eventId = (int) DB::table('rec_dispo_events')->insertGetId([
            'uuid'        => 'ev-'.$this->naechsteId++,
            'einsatz_ref' => 'RG-'.$this->naechsteId,
            'name'        => $attr['event_name'] ?? null,
            'created_at'  => self::ANGEFASST,
            'updated_at'  => self::ANGEFASST,
        ]);
        unset($attr['event_name']);

        return (int) DB::table('rec_dispo_assignments')->insertGetId(array_merge([
            'uuid'               => 'as-'.$this->naechsteId++,
            'ds_ref'             => 'DS-'.$this->naechsteId,
            'rec_dispo_event_id' => $eventId,
            'pnr_raw'            => 'RG14',
            'rec_employee_id'    => $employee->id,
            'datum'              => '2026-10-20',
            'status_id'          => 1,
            'created_at'         => self::ANGEFASST,
            'updated_at'         => self::ANGEFASST,
        ], $attr));
    }

    /**
     * Ein neuer PFLICHT-Nachweis kommt dazu: der Mensch wird zum Ersthelfer
     * bestellt, `ersthelfer` wird damit Pflicht (ProofTypes::requiredFor).
     * Das ist die einzige Art, auf die sich die Punktmenge — und damit die
     * Signatur — wirklich aendert; ein blosses Ablaufen tut es nicht, weil
     * `laeuft_ab` schon als offen zaehlt.
     *
     * Geschrieben ueber den Query Builder, damit der Aufbau des Tests nicht
     * selbst den ZAS-Marker setzt, den der Lauf danach nicht setzen soll.
     */
    private function ersthelferPflichtDazu(RecEmployee $employee): void
    {
        $vorher = count((new OffenePunkte())->fuer($employee, '2026-10-01')['punkte']);

        DB::table('rec_employees')->where('id', $employee->id)->update(['is_first_aider' => true]);

        $this->assertGreaterThan(
            $vorher,
            count((new OffenePunkte())->fuer($employee->fresh(), '2026-10-01')['punkte']),
            'Vorflug: nach diesem Schritt muss wirklich ein Punkt mehr offen sein.',
        );
    }

    /**
     * Die Signatur des aktuellen Stands an der Person setzen — damit die
     * NEUE Nachricht als erledigt gilt und ein Test ausschliesslich die
     * Erinnerung misst. Query Builder, wie das Kommando selbst.
     */
    private function signaturSetzen(RecEmployee $employee, string $heute, string $gemeldetAm): void
    {
        $punkte = (new OffenePunkte())->fuer($employee, $heute)['punkte'];
        $this->assertNotSame([], $punkte, 'Vorflug: es muss ueberhaupt etwas offen sein.');

        DB::table('rec_persons')->where('id', $employee->rec_person_id)->update([
            'aufgaben_signatur'    => \Platform\Recruiting\Support\TriggerRegeln::signatur($punkte),
            'aufgaben_gemeldet_at' => $gemeldetAm,
        ]);
    }

    private function nachweisGueltigBis(RecEmployee $employee, string $code, ?string $gueltigBis): void
    {
        DB::table('rec_employee_proofs')->insert([
            'uuid'            => 'pf-'.$this->naechsteId++,
            'team_id'         => self::TEAM,
            'rec_employee_id' => $employee->id,
            'proof_type_code' => $code,
            'valid_until'     => $gueltigBis,
            'created_at'      => self::ANGEFASST,
            'updated_at'      => self::ANGEFASST,
        ]);
    }

    /**
     * Jeden offenen Punkt dieses Menschen erbringen. Die Liste kommt aus
     * OffenePunkte selbst statt aus einer Namensliste im Test — welche
     * Nachweise Pflicht sind, haengt an Beschaeftigungsart, Staatsbuerger-
     * schaft und Ersthelfer-Flag, und eine Namensliste hier waere nach der
     * naechsten Katalog-Aenderung still unvollstaendig.
     */
    private function alleNachweiseErbringen(RecEmployee $employee, string $heute, string $gueltigBis): void
    {
        foreach ((new OffenePunkte())->fuer($employee, $heute)['punkte'] as $punkt) {
            $this->nachweisGueltigBis($employee, $punkt['code'], $gueltigBis);
        }

        $this->assertSame(
            [],
            (new OffenePunkte())->fuer($employee, $heute)['punkte'],
            'Vorflug: nach diesem Schritt darf wirklich nichts mehr offen sein.',
        );
    }

    private function mehrereMitAuftrag(int $anzahl): void
    {
        for ($i = 0; $i < $anzahl; $i++) {
            $this->einbuchung($this->mitarbeiterOhneNachweise(), ['datum' => '2026-10-20', 'status_id' => 1]);
        }
    }

    private function nachrichtAnlegen(string $status): int
    {
        return (int) DB::table('comms_whatsapp_messages')->insertGetId([
            'status'     => $status,
            'direction'  => 'outbound',
            'created_at' => self::ANGEFASST,
            'updated_at' => self::ANGEFASST,
        ]);
    }

    private function person(RecEmployee $employee): object
    {
        return DB::table('rec_persons')->where('id', $employee->fresh()->rec_person_id)->first();
    }

    /**
     * Jede Spalte, die das Kommando oder eine Klasse darunter liest, steht
     * hier. Gegen die echten Migrationen abgeglichen.
     */
    private function schemaBauen(): void
    {
        $schema = $this->capsule->schema();

        // Dokumente (Task 1): OffenePunkte liest jetzt auch rec_document_recipients — die Tabellen kommen aus den Migrationen.
        foreach (['2026_10_09_000001_create_rec_documents_table', '2026_10_09_000002_create_rec_document_recipients_table'] as $m) {
            (require dirname(__DIR__, 2) . '/database/migrations/' . $m . '.php')->up();
        }

        // Vertrag aus der Akte (Task 6): OffenePunkte liest jetzt auch rec_contracts.
        foreach ([
            '2026_04_15_100000_create_rec_contract_tables',
            '2026_08_12_000001_add_type_to_rec_contract_templates',
            '2026_08_21_000002_add_superseded_by_to_rec_contracts',
            '2026_10_07_000002_add_employee_anchor_to_contracts',
        ] as $m) {
            (require dirname(__DIR__, 2) . '/database/migrations/' . $m . '.php')->up();
        }

        $schema->create('rec_persons', function ($t) {
            $t->increments('id');
            $t->string('uuid', 64)->unique();
            $t->integer('team_id')->nullable();
            $t->string('aufgaben_signatur', 64)->nullable();
            $t->timestamp('aufgaben_gemeldet_at')->nullable();
            $t->unsignedBigInteger('aufgaben_nachricht_id')->nullable();
            $t->timestamps();
        });

        $schema->create('rec_employees', function ($t) {
            $t->increments('id');
            $t->string('uuid', 64)->nullable();
            $t->integer('team_id')->nullable();
            $t->integer('rec_person_id')->nullable();
            $t->string('person_key', 64)->nullable();
            $t->string('phone')->nullable();
            $t->string('first_name')->nullable();
            $t->string('last_name')->nullable();
            $t->string('nationality')->nullable();
            $t->boolean('is_active')->nullable();
            $t->boolean('is_eu_citizen')->nullable();
            $t->string('employment_type')->nullable();
            $t->boolean('is_first_aider')->nullable();
            $t->timestamp('portal_v2_since')->nullable();
            $t->timestamp('zas_changed_at')->nullable();
            $t->timestamp('payroll_data_changed_at')->nullable();
            $t->text('payroll_data_changed_fields')->nullable();
            $t->timestamps();
        });

        $schema->create('rec_employee_proofs', function ($t) {
            $t->increments('id');
            $t->string('uuid', 64);
            $t->integer('team_id')->nullable();
            $t->integer('rec_employee_id');
            $t->string('person_key', 64)->nullable();
            $t->string('proof_type_code', 40);
            $t->integer('file_id')->nullable();
            $t->integer('file_back_id')->nullable();
            $t->date('valid_until')->nullable();
            $t->integer('version')->default(1);
            $t->timestamp('superseded_at')->nullable();
            $t->timestamp('reminded_at')->nullable();
            $t->integer('confirmed_by_user_id')->nullable();
            $t->timestamp('confirmed_at')->nullable();
            $t->string('uploaded_via', 20)->default('employee');
            $t->integer('uploaded_by_user_id')->nullable();
            $t->timestamps();
        });

        $schema->create('rec_dispo_events', function ($t) {
            $t->increments('id');
            $t->string('uuid', 64)->unique();
            $t->string('einsatz_ref')->unique();
            $t->string('name')->nullable();
            $t->timestamps();
        });

        $schema->create('rec_dispo_assignments', function ($t) {
            $t->increments('id');
            $t->string('uuid', 64)->unique();
            $t->string('ds_ref')->unique();
            $t->integer('rec_dispo_event_id')->nullable();
            $t->string('pnr_raw')->nullable();
            $t->integer('rec_employee_id')->nullable();
            $t->date('datum');
            $t->string('von', 8)->nullable();
            $t->string('bis', 8)->nullable();
            $t->unsignedTinyInteger('status_id')->default(0);
            $t->string('taetigkeit')->nullable();
            $t->timestamp('last_seen_at')->nullable();
            $t->timestamp('missing_since')->nullable();
            $t->timestamp('aufgaben_erinnert_at')->nullable();
            $t->timestamps();
        });

        $schema->create('rec_hr_desk_cases', function ($t) {
            $t->increments('id');
            $t->string('uuid', 64)->nullable();
            $t->integer('rec_applicant_id')->nullable();
            $t->unsignedBigInteger('rec_employee_id')->nullable();
            $t->integer('team_id')->nullable();
            $t->string('reason', 50);
            $t->string('status', 20)->default('open');
            $t->text('notes')->nullable();
            $t->dateTime('opened_at')->nullable();
            $t->integer('opened_by_user_id')->nullable();
            $t->dateTime('resolved_at')->nullable();
            $t->integer('resolved_by_user_id')->nullable();
            $t->text('resolution_notes')->nullable();
            $t->timestamps();
        });

        // Die Tabelle des Fremdmoduls, in der die verschickte Nachricht
        // liegt — ET-23 liest dort ihren Status nach.
        $schema->create('comms_whatsapp_messages', function ($t) {
            $t->increments('id');
            $t->string('direction', 20)->nullable();
            $t->string('status', 30)->nullable();
            $t->timestamps();
        });

        // RecEmployeeExportObserver::trackPayrollChanges() liest hier; ohne
        // die Tabelle verschluckt safelyRun() den Fehler, und der Vorflug
        // belegte weniger, als er behauptet.
        $schema->create('rec_applicant_settings', function ($t) {
            $t->increments('id');
            $t->integer('team_id')->unique();
            $t->text('settings')->nullable();
            $t->timestamps();
        });
    }
}

/**
 * Illuminate\Console\Command::run() braucht runningUnitTests(), sonst nichts
 * (Muster BackfillPersonsFakeLaravel).
 */
final class EinsatzPruefungFakeLaravel extends Container
{
    public function runningUnitTests(): bool
    {
        return true;
    }
}
