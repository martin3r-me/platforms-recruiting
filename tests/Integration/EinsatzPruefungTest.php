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

    private string $ausgabe = '';

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
     * Die Erinnerung nennt das Datum IHRER Einbuchung, nicht pauschal das
     * des naechsten Einsatzes. Zwei Einbuchungen in der Erinnerungsfrist
     * (09.10. und 10.10.) ergeben zwei Erinnerungen mit zwei Daten — ohne
     * diese Zuordnung saehe der Mensch zweimal denselben Tag und haette
     * keine Ahnung, welcher Einsatz gemeint ist.
     */
    public function test_jede_erinnerung_nennt_das_datum_ihrer_eigenen_einbuchung(): void
    {
        $ma = $this->mitarbeiterOhneNachweise();
        $this->einbuchung($ma, ['datum' => '2026-10-09', 'status_id' => 1]);
        $this->einbuchung($ma, ['datum' => '2026-10-10', 'status_id' => 1]);

        $this->laufe('2026-10-01');
        $this->laufe('2026-10-08');

        $daten = array_values(array_map(
            fn ($v) => $v['stand']['einsatz']['datum'] ?? null,
            array_filter($this->sender->versandt, fn ($v) => $v['anlass'] === 'erinnerung'),
        ));

        $this->assertSame(['2026-10-09', '2026-10-10'], $daten);
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

    public function test_die_ausgabe_nennt_keine_namen(): void
    {
        $ma = $this->mitarbeiterOhneNachweise(['first_name' => 'Hannelore', 'last_name' => 'Kowalski']);
        $this->einbuchung($ma, ['datum' => '2026-10-20', 'status_id' => 1]);

        $this->laufe('2026-10-01', ['--welle' => 10]);

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
        $ma = $this->mitarbeiterOhneNachweise(['phone' => '+4915199887766']);
        $this->einbuchung($ma, ['datum' => '2026-10-20', 'status_id' => 1]);

        $this->laufe('2026-10-01', ['--welle' => 10]);

        $this->assertStringNotContainsString('99887766', $this->ausgabe);
        $this->assertStringNotContainsString('+4915199887766', $this->ausgabe);
        $this->assertStringContainsString('#'.$ma->id, $this->ausgabe);
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

        $this->assertStringNotContainsString('recruiting:einsatz-pruefung --welle', $quelle);
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
        $this->ausgabe .= $output->fetch();

        return $ergebnis;
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
