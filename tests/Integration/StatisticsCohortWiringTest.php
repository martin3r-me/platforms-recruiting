<?php

namespace Platform\Recruiting\Tests\Integration;

use Illuminate\Container\Container;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Livewire\Statistics\Index;
use Platform\Recruiting\Services\Statistics\CohortViewModel;

/**
 * ANSCHLUSS-NACHWEIS fuer die Ausschreibungs-Tabelle: kommen die Felder, die der
 * CohortAssigner nur mit ??-Bruecke liest, aus der ECHTEN Query auch wirklich an?
 *
 * Warum das keine Unit-Tests leisten koennen: der Assigner liest
 * `$applicant['phase_order_reached']`, `$pivot['posting_title']` und
 * `$pivot['posting_closed']` defensiv mit `?? null` bzw. `?? ''`. Ein vergessener
 * Anschluss in der Livewire-Komponente faellt damit NICHT als Fehler auf — die
 * Phasen-Spalten bleiben einfach leer und der Ausschreibungs-Titel leer. Genau
 * diese Sorte stiller Fehlanzeige soll die Seite abschaffen, also braucht sie
 * einen Test, der die Kette Query → Mapping → Assigner am Stueck laeuft.
 *
 * Aufbau wie PostingTargetFieldsTest (Container + Capsule von Hand, ECHTE
 * Migrationen, kein testbench) — mit zwei Ergaenzungen:
 *  - die Migrationen kommen per glob, weil die Query quer durch rec_applicants,
 *    rec_postings, rec_positions, rec_phases, rec_phase_transitions und die
 *    Pivot-Tabelle liest; eine handgepflegte Liste waere genau dann still
 *    falsch, wenn eine neue Spalte dazukommt (Muster:
 *    EmployeeCreationCertificateTest);
 *  - `auth()` ist in der Komponente die einzige Laravel-Abhaengigkeit
 *    (teamId()) und wird als Attrappe in den Container gebunden.
 *
 * Model::unsetEventDispatcher(): die 'creating'/'created'-Hooks von RecPosting
 * (UUID + PostingRefCodeService) sind hier unbeteiligtes Beiwerk. Datensaetze
 * werden deshalb per Query Builder eingefuegt, mit expliziter UUID.
 */
class StatisticsCohortWiringTest extends TestCase
{
    private const TEAM = 3;

    /** Ausschreibung online: status=published UND is_active. */
    private const POSTING_OFFEN = 10;

    /** Ausschreibung NICHT online (draft) — also geschlossen. */
    private const POSTING_ZU = 11;

    /** Ausschreibung eines FREMDEN Teams: taucht im Pivot auf, nicht im Stammdaten-Lookup. */
    private const POSTING_FREMD = 12;

    /**
     * Feste Uhr. Ohne sie haengt die Pipeline-Ampel am Kalender: nach dem
     * 30.09.2026 kippt die Hochrechnung in „Laufzeit vorbei", vor dem 08.07.2026
     * in „zu frueh fuer eine Aussage" — der Test waere an einem Stichtag rot
     * geworden, ohne dass jemand etwas geaendert hat.
     */
    private const HEUTE = '2026-08-17 10:00:00';

    public static function setUpBeforeClass(): void
    {
        $container = Container::getInstance();

        $capsule = new Capsule();
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $capsule->setAsGlobal();
        $capsule->bootEloquent();
        Model::unguard();
        Model::unsetEventDispatcher();

        $container->instance('db', $capsule->getDatabaseManager());
        $container->instance('db.schema', $capsule->getConnection()->getSchemaBuilder());
        Facade::setFacadeApplication($container);

        // auth()->user()->currentTeam->id — die einzige Framework-Abhaengigkeit
        // der Komponente. Als Attrappe im Container, nicht als Umbau der
        // Komponente: getestet werden soll der Produktionspfad.
        // Der auth()-Helper hat einen Rueckgabetyp — die Attrappe muss die
        // Factory-Schnittstelle wirklich implementieren, ein beliebiges Objekt
        // reicht nicht.
        $container->instance(AuthFactory::class, new class(self::TEAM) implements AuthFactory
        {
            public function __construct(private int $teamId) {}

            public function user(): object
            {
                return new class($this->teamId)
                {
                    public object $currentTeam;

                    public function __construct(int $teamId)
                    {
                        $this->currentTeam = (object) ['id' => $teamId];
                    }
                };
            }

            public function guard($name = null)
            {
                return $this;
            }

            public function shouldUse($name)
            {
                // nicht benutzt: die Komponente ruft nur auth()->user()
            }
        });

        // Feste Uhr fuer die Hochrechnung der Pipeline-Ampel (siehe HEUTE).
        Carbon::setTestNow(Carbon::parse(self::HEUTE));

        self::runRealMigrations();
        self::seed();
    }

    public static function tearDownAfterClass(): void
    {
        // Siehe DuplicateMatchQueryTest: setFacadeApplication() setzt nur
        // static::$app, nicht den prozessweiten $resolvedInstance-Cache.
        Facade::clearResolvedInstances();
        Container::getInstance()->forgetInstance(AuthFactory::class);
        // PFLICHT: die Testuhr ist statisch und wuerde sonst in jede spaeter im
        // selben Prozess laufende Testklasse hineinreichen.
        Carbon::setTestNow();
    }

    /**
     * Komponente fuer die Verdrahtungs-Tests — mit Status „alle".
     *
     * Der Bestand dieser Klasse enthaelt absichtlich eine DRAFT-Ausschreibung
     * (POSTING_ZU), und die faellt seit Task 10 mit dem Standard „online" aus der
     * Auswahl. Die Tests hier pruefen den Weg von der Query bis zur Zeile; dafuer
     * muessen beide Ausschreibungen drin sein. Der Filter selbst wird in
     * test_status_filter_nimmt_geschlossene_ausschreibungen_aus_der_auswahl()
     * geprueft, also genau einmal und an seiner eigenen Stelle.
     */
    private function component(?string $ort = null, string $status = 'alle'): Index
    {
        $component = new Index();
        $component->ortFilter = $ort;
        $component->postingStatusFilter = $status;

        return $component;
    }

    private function cohortRows(?string $ort = null): array
    {
        return $this->component($ort)->cohort()['rows'];
    }

    /**
     * Genau EINE Zeile suchen — der Test soll nicht an der Zeilenreihenfolge
     * haengen, und bei mehreren Treffern soll er nicht still die erste nehmen
     * (die Zeilen sind je Ausschreibung UND Phase, „posting + type" ist also
     * nicht eindeutig).
     */
    private function rowOf(array $rows, ?int $postingId, string $type, ?string $keyContains = null): array
    {
        $hits = array_values(array_filter($rows, fn ($row) => $row['posting_id'] === $postingId
            && $row['type'] === $type
            && ($keyContains === null || str_contains($row['key'], $keyContains))));

        $this->assertCount(
            1,
            $hits,
            'genau eine Zeile erwartet fuer posting_id=' . var_export($postingId, true)
                . ", type={$type}, key~" . var_export($keyContains, true),
        );

        return $hits[0];
    }

    public function test_ausschreibungs_titel_und_status_kommen_an_der_zeile_an(): void
    {
        $rows = $this->cohortRows();

        $offen = $this->rowOf($rows, self::POSTING_OFFEN, 'ohne_schulung', 'Telefonat');
        $this->assertSame('Kellner (m/w/d)', $offen['posting_title'], 'Titel der Ausschreibung');
        $this->assertFalse($offen['posting_closed'], 'published + is_active ist online, nicht geschlossen');

        // draft: nicht veroeffentlicht, also geschlossen — auch wenn is_active gesetzt ist
        $zu = $this->rowOf($rows, self::POSTING_ZU, 'ohne_schulung');
        $this->assertSame('Aushilfe Bankett', $zu['posting_title']);
        $this->assertTrue($zu['posting_closed'], 'draft ist nicht online, also geschlossen');
    }

    public function test_ziel_stammdaten_haengen_an_der_zeile_und_bleiben_null_wenn_ungepflegt(): void
    {
        $rows = $this->cohortRows();

        $offen = $this->rowOf($rows, self::POSTING_OFFEN, 'ohne_schulung', 'Telefonat');
        $this->assertSame(10, $offen['bedarf']);
        $this->assertSame(8.0, $offen['bewerbungs_faktor']);
        $this->assertSame('2026-07-01', $offen['published_ymd'], 'Y-m-d-String, kein Carbon');
        $this->assertSame('2026-09-30', $offen['closes_ymd']);

        // Nicht gepflegt bleibt null — kein Default, kein Raten (graue Ampel)
        $zu = $this->rowOf($rows, self::POSTING_ZU, 'ohne_schulung');
        $this->assertNull($zu['bedarf']);
        $this->assertNull($zu['bewerbungs_faktor']);
        $this->assertNull($zu['published_ymd']);
        $this->assertNull($zu['closes_ymd']);
    }

    public function test_phase_erreicht_wird_aus_phase_und_transition_log_gefuellt(): void
    {
        $rows = $this->cohortRows();
        $vm = new CohortViewModel();

        // Bewerber 101 steht in Phase 2, das Log kennt Phase 3 (spaeter
        // zurueckgesetzt) -> Maximum ist 3, kumulativ also 1, 2 und 3.
        // Bewerber 102 steht in Phase 1 ohne Log-Eintrag -> nur Phase 1.
        $offen = $this->rowOf($rows, self::POSTING_OFFEN, 'ohne_schulung', 'Telefonat');
        $phasen = $offen['columns']['phase_reached'];

        $this->assertSame([101], $phasen[3] ?? [], 'Log gewinnt gegen die niedrigere aktuelle Phase');
        $this->assertSame([101], $phasen[2] ?? [], 'kumulativ: Phase 2 ist mit erreicht');
        $this->assertSame([101], $phasen[1] ?? [], 'kumulativ: Phase 1 ist mit erreicht');

        // ... und derselbe Zugriff, den die Tabelle nimmt (order-qualifiziert)
        $this->assertSame(1, $vm->countIn([$offen], CohortViewModel::phaseColumnKey(3)));
        $this->assertSame(1, $vm->countIn([$offen], CohortViewModel::phaseColumnKey(1)));

        $zu = $this->rowOf($rows, self::POSTING_ZU, 'ohne_schulung');
        $this->assertSame([102], $zu['columns']['phase_reached'][1] ?? []);
        $this->assertArrayNotHasKey(2, $zu['columns']['phase_reached'], 'ohne Log-Eintrag endet der Trichter bei der aktuellen Phase');
    }

    public function test_log_einer_anderen_stelle_macht_den_trichter_nicht_tiefer(): void
    {
        // Bewerber 103 steht in Phase 1 der Stelle 1, hat aber ein Log auf der
        // Stelle 2 mit order 3. Phasen sind pro Stelle geklont — diese order ist
        // im Trichter DIESER Ausschreibung keine erreichte Stufe. Ohne die
        // Einschraenkung auf die aktuelle Stelle waere die Trichter-Tiefe nach
        // jedem Stellenwechsel zu gross (gemessen: 15 Wechsel, elf in sechs Tagen).
        $rows = $this->cohortRows();
        $row = $this->rowOf($rows, self::POSTING_OFFEN, 'ohne_schulung', 'Eingang');

        $this->assertSame([103], $row['columns']['phase_reached'][1] ?? []);
        $this->assertArrayNotHasKey(
            3,
            $row['columns']['phase_reached'],
            'order 3 stammt aus dem Phasensatz der ANDEREN Stelle und darf nicht zaehlen',
        );
        $this->assertArrayNotHasKey(2, $row['columns']['phase_reached']);
    }

    public function test_die_ausschreibung_eines_fremden_teams_bildet_keine_zeile_mehr(): void
    {
        // GEAENDERTE ZUSICHERUNG (Abschluss-Review Task 10). Frueher hiess dieser
        // Test test_posting_ohne_lookup_eintrag_bleibt_grau_statt_null und pruefte,
        // dass die Ausschreibung eines FREMDEN Teams als eigene Zeile erscheint —
        // mit Titel aus dem Pivot und grauen Ampeln, weil der team-gescopte
        // Stammdaten-Lookup ihren Bedarf 50 nicht liefert.
        //
        // Das war die Absicherung gegen das Durchsickern von ZAHLEN. Mit der
        // Ausschreibungs-Tabelle kam der TITEL dazu, und damit war der fremde Titel
        // sichtbar (belegt am Bewerber, dessen einzige Ausschreibung die fremde ist).
        // Deshalb ist der Pivot jetzt team-gescopt, und die Zusicherung ist
        // strenger: die fremde Ausschreibung bildet GAR KEINE Zeile.
        //
        // Die Bewerbung verschwindet dabei nicht — sie faellt in „ohne
        // Ausschreibung" (Fall 3) und wird vom Block „Ohne Filial-Zuordnung"
        // benannt. Beides steht unten im Test.
        $component = $this->component();
        $cohort = $component->cohort();
        $rows = $cohort['rows'];

        $this->assertSame(
            [],
            array_values(array_filter($rows, fn ($row) => $row['posting_id'] === self::POSTING_FREMD)),
            'keine Zeile fuer die Ausschreibung des fremden Teams',
        );
        $this->assertStringNotContainsString(
            'Fremdes Team',
            json_encode(array_map(fn ($row) => $row['posting_title'], $rows), JSON_UNESCAPED_UNICODE),
            'auch der Titel nicht',
        );

        // Der Bewerber 104 haengt NUR an der fremden Ausschreibung: seine Zeile ist
        // jetzt „ohne Ausschreibung" — vollstaendig enthalten, nur ohne Ziel.
        $ohne = array_values(array_filter($rows, fn ($row) => $row['posting_id'] === null));
        $this->assertCount(1, $ohne);
        $this->assertSame([104], $ohne[0]['ids']);
        $this->assertSame('ohne Ausschreibung', $ohne[0]['group']['ort']);
        $this->assertNull($ohne[0]['bedarf'], 'Bedarf 50 des fremden Teams sickert weiterhin nicht durch');

        // Rekonziliation bleibt geschlossen, und der vierte Block benennt die Zeile
        $this->assertContains(104, $cohort['total_ids']);
        $this->assertSame(
            $component->countIn($rows, 'ids'),
            count($cohort['total_ids']),
        );
        $this->assertContains(
            104,
            (new CohortViewModel())->resolveIds($cohort['unreachable_rows'], ['scope' => 'all'], 'ids'),
            'die Bewerbung steht im Block „Ohne Filial-Zuordnung"',
        );
    }

    public function test_gesamt_zeile_nennt_ihre_bezugsgroessen(): void
    {
        // Zwei Unterschriften im Bestand, aber nur eine an einer Ausschreibung mit
        // gepflegtem Bedarf. Die Spalte „Unterschrieben" zeigt 2, die Quote rechnet
        // mit 1 von 10 — genau die Stelle, an der die Zeile ohne benannte
        // Bezugsgroessen unlesbar war ("2 von 10, also 20 %?").
        $component = $this->component();
        $groups = (new CohortViewModel())->postingGroups($component->cohort()['rows']);
        $light = $component->fulfilmentTotalLight($groups);

        $this->assertSame(2, $component->countIn($component->cohort()['rows'], 'unterschrieben'), 'Spaltenwert');
        $this->assertSame(1, $light['signed'], 'Zaehler der Quote');
        $this->assertSame(10, $light['bedarf']);
        $this->assertSame(10, $light['pct']);

        // Seit der Pivot team-gescopt ist, bildet die Ausschreibung des fremden
        // Teams keine Zeile mehr — ihr Bewerber (104, MIT Unterschrift) steht in
        // „ohne Ausschreibung". Der Fall wandert damit von „Ausschreibung ohne
        // gepflegten Bedarf" in den Null-Bucket, und das ist genau der Grund, warum
        // fulfilmentTotals die zwei Toepfe TRENNT: an einer Ausschreibung ohne
        // Bedarf kann man etwas pflegen, an einer Bewerbung ohne Ausschreibung nicht.
        $this->assertSame(1, $light['excluded_postings'], 'nur noch die Ausschreibung ohne gepflegten Bedarf');
        $this->assertSame(0, $light['excluded_signed'], 'dort liegt keine Unterschrift');
        $this->assertSame(1, $light['without_posting_groups'], 'die Bewerbung ohne (eigene) Ausschreibung');
        $this->assertSame(1, $light['without_posting_signed']);
        // Die Zahlen gehen weiter auf: Zaehler + ausgelassene = Spaltenwert
        $this->assertSame(2, $light['signed'] + $light['excluded_signed'] + $light['without_posting_signed']);
        $this->assertStringContainsString('NICHT in dieser Quote', $light['reason']);
        $this->assertStringContainsString('1 Ausschreibung ohne gepflegten Bedarf', $light['reason']);
        $this->assertStringContainsString('die Bewerbungen ohne Ausschreibung (1 Unterschrift)', $light['reason']);
    }

    public function test_fussnote_ohne_jeden_gepflegten_bedarf_erfindet_keine_null(): void
    {
        // „0 von 0 benötigten Einstellungen" behauptete, es sei nichts noetig und
        // nichts erreicht — beides erfunden. Ohne gepflegten Bedarf gibt es keinen
        // Nenner, also keine Quote; der Text muss genau das sagen.
        $component = new Index();
        $light = $component->fulfilmentTotalLight([
            ['posting_id' => 5, 'bedarf' => null, 'ids' => [1], 'columns' => ['unterschrieben' => [1]]],
        ]);

        $this->assertNull($light['bedarf']);
        $this->assertNull($light['pct'], 'keine Quote, nicht 0 %');
        $this->assertSame('grey', $light['status']);
        $this->assertStringContainsString('Kein Bedarf gepflegt', $light['reason']);
        $this->assertStringNotContainsString('0 von 0', $light['reason']);
        $this->assertStringContainsString('1 Ausschreibung ohne gepflegten Bedarf', $light['reason']);
    }

    public function test_fussnote_trennt_ausschreibung_ohne_bedarf_von_ohne_ausschreibung(): void
    {
        // Die genannten Zahlen muessen zu den ZEILEN der Tabelle passen: eine
        // Ausschreibung ohne Bedarf ist ein Pflege-Hinweis, die Zeile „ohne
        // Ausschreibung" ist etwas anderes — dort gibt es nichts zu pflegen.
        $component = new Index();
        $light = $component->fulfilmentTotalLight([
            ['posting_id' => 5, 'bedarf' => 4, 'ids' => [1], 'columns' => ['unterschrieben' => [1]]],
            ['posting_id' => 6, 'bedarf' => null, 'ids' => [2], 'columns' => ['unterschrieben' => [2]]],
            ['posting_id' => null, 'bedarf' => null, 'ids' => [3], 'columns' => ['unterschrieben' => [3]]],
        ]);

        $this->assertSame(25, $light['pct'], '1 von 4');
        $this->assertStringContainsString('1 von 4 benötigten Einstellungen', $light['reason']);
        $this->assertStringContainsString('1 Ausschreibung ohne gepflegten Bedarf (1 Unterschrift)', $light['reason']);
        $this->assertStringContainsString('die Bewerbungen ohne Ausschreibung (1 Unterschrift)', $light['reason']);
        $this->assertStringNotContainsString('2 Ausschreibungen', $light['reason'], 'der Null-Bucket ist keine Ausschreibung');
    }

    public function test_ausschreibungs_zeilen_tragen_bedarf_und_ampel_bis_in_die_tabelle(): void
    {
        // Der Weg, den die View geht: cohort()-Zeilen -> postingGroups() ->
        // TargetLight. Zeigt in einem Zug, dass die angehaengten Stammdaten in
        // der Gruppe UND in der Ampel ankommen.
        $component = $this->component();
        $groups = (new CohortViewModel())->postingGroups($component->cohort()['rows']);

        $bedarfe = [];
        foreach ($groups as $group) {
            $bedarfe[(string) ($group['posting_id'] ?? 'ohne')] = $group['bedarf'];
        }
        $this->assertSame(10, $bedarfe[(string) self::POSTING_OFFEN]);
        $this->assertNull($bedarfe[(string) self::POSTING_ZU]);

        $offen = null;
        foreach ($groups as $group) {
            if ($group['posting_id'] === self::POSTING_OFFEN) {
                $offen = $group;
            }
        }
        $this->assertNotNull($offen);

        // Erfuellung: eine Unterschrift von zehn benoetigten = 10 %, rot
        $this->assertSame(10, $component->fulfilmentLight($offen)['pct']);
        $this->assertSame('red', $component->fulfilmentLight($offen)['status']);

        // Pipeline gegen die FESTE Uhr (17.08.2026) exakt nachgerechnet:
        // Laufzeit 01.07.–30.09. = 91 Tage, davon 47 vergangen; 2 Bewerbungen
        // hochgerechnet ergeben round(2 / 47 * 91) = 4 von 80 benoetigten = 5 %.
        $pipeline = $component->pipelineLight($offen);
        $this->assertSame(80, $pipeline['target'], '10 x 8,0 benoetigte Bewerbungen');
        $this->assertSame(4, $pipeline['projected']);
        $this->assertSame(5, $pipeline['pct']);
        $this->assertSame('red', $pipeline['status']);

        // Ungepflegte Ausschreibung bleibt grau — nichts wird geraten
        $zu = null;
        foreach ($groups as $group) {
            if ($group['posting_id'] === self::POSTING_ZU) {
                $zu = $group;
            }
        }
        $this->assertSame('grey', $component->pipelineLight($zu)['status']);
        $this->assertNull($component->fulfilmentLight($zu)['pct']);
    }

    public function test_phasen_ueberschriften_kommen_aus_dem_phasensatz_der_filiale(): void
    {
        $component = new Index();
        $component->ortFilter = 'Essen';

        $this->assertSame(
            [1 => 'Eingang', 2 => 'Telefonat', 3 => 'Schulung'],
            $component->phaseLabels(),
        );

        // Inaktive Phasen gehoeren nicht in die Kopfzeile
        $this->assertArrayNotHasKey(4, $component->phaseLabels());

        // Die Phasen der ANDEREN Filiale haben denselben `order`, aber eigene
        // Namen — genau deshalb ist der Ort Pflicht und die Kopfzeile nicht fest
        // verdrahtet.
        $component->ortFilter = 'Wuppertal';
        $this->assertSame([1 => 'Eingang', 3 => 'Probearbeit'], $component->phaseLabels());
    }

    public function test_ort_ist_pflichtauswahl_und_wird_vorbelegt(): void
    {
        // Erster Seitenaufruf: mount() belegt die Pflichtauswahl mit dem ersten
        // Ort. Ohne diese Vorbelegung stuende die Seite beim ersten Aufruf ohne
        // Zahlen da — und phaseLabels() waere in dem Zustand die Falle, um die es
        // hier geht (where('location', null) wird zu whereNull).
        $component = new Index();
        $this->assertFalse($component->hasOrt(), 'vor mount() ist nichts gewaehlt');

        $component->mount();
        $this->assertSame('Essen', $component->ortFilter, 'erster Ort alphabetisch');
        $this->assertTrue($component->hasOrt());

        // Eine bestehende Auswahl (Livewire-Hydrierung) wird NICHT ueberschrieben
        $hydriert = new Index();
        $hydriert->ortFilter = 'Wuppertal';
        $hydriert->mount();
        $this->assertSame('Wuppertal', $hydriert->ortFilter);

        // '' zaehlt wie „nichts gewaehlt": ein geleertes Select oder eine
        // Hydrierung an der updated-Hook vorbei darf nicht als gewaehlte Filiale
        // durchgehen, sonst zeigt die Seite leere Tabellen statt der Aufforderung.
        $leer = new Index();
        $leer->ortFilter = '';
        $this->assertFalse($leer->hasOrt());
        $leer->mount();
        $this->assertSame('Essen', $leer->ortFilter, 'aus einem Leerstring wird die Vorbelegung');

        // Zuruecksetzen heisst Ausgangszustand, nicht „nichts gewaehlt"
        $reset = new Index();
        $reset->ortFilter = 'Wuppertal';
        $reset->postingStatusFilter = 'alle';
        $reset->interviewFrom = '2026-01-01';
        $reset->resetFilters();
        $this->assertTrue($reset->hasOrt(), 'nach dem Zuruecksetzen steht wieder eine Filiale');
        $this->assertSame('Essen', $reset->ortFilter);
        $this->assertSame('online', $reset->postingStatusFilter);
        $this->assertNull($reset->interviewFrom);
    }

    public function test_status_filter_nimmt_geschlossene_ausschreibungen_aus_der_auswahl(): void
    {
        // EINE Definition von „online" (published UND aktiv): der Filter liest nur
        // das Feld posting_closed, das cohort() als exaktes Gegenteil setzt.
        $online = new Index(); // Standard ist 'online'
        $this->assertSame('online', $online->postingStatusFilter);
        $cohort = $online->cohort();

        $postingIds = array_map(fn ($row) => $row['posting_id'], $cohort['rows']);
        $this->assertNotContains(self::POSTING_ZU, $postingIds, 'die draft-Ausschreibung ist nicht online');
        $this->assertContains(self::POSTING_OFFEN, $postingIds);

        // Rekonziliation bleibt geschlossen: total_ids wird aus den verbliebenen
        // Zeilen neu gebildet, die Gesamt-Zeile ist also weiter die Addition ihrer
        // Zeilen. (Ein Anzeige-Filter haette hier eine stille Differenz erzeugt.)
        $this->assertSame(
            $online->countIn($cohort['rows'], 'ids'),
            count($cohort['total_ids']),
            'Summe der Zeilen = Gesamtmenge',
        );

        // Die herausgefilterten Zeilen sind NICHT verschwunden: sie liegen
        // beiseite und tragen den Block „Geschlossene Ausschreibungen".
        $closedIds = array_map(fn ($row) => $row['posting_id'], $cohort['closed_rows']);
        $this->assertSame([self::POSTING_ZU], array_values(array_unique($closedIds)));
        $this->assertSame(1, $online->countIn($cohort['closed_rows'], 'ids'));

        // Gruppiert wie die Tabelle. Bewusst ueber das ViewModel und nicht ueber
        // das Computed closedPostingGroups(): das liest $this->cohort als
        // PROPERTY, und Computed Properties gibt es nur im Livewire-Lebenszyklus.
        // Gerechnet wird derselbe Aufruf mit denselben Zeilen; dass die Huelle das
        // Ergebnis in die View traegt, prueft der Render-Test.
        $closedGroups = (new CohortViewModel())->postingGroups($cohort['closed_rows']);
        $this->assertCount(1, $closedGroups);
        $this->assertTrue($closedGroups[0]['posting_closed']);
        $this->assertSame('Aushilfe Bankett', $closedGroups[0]['posting_title']);

        // Mit 'alle' ist die geschlossene Ausschreibung wieder in der Auswahl
        $alle = $this->component();
        $this->assertContains(
            self::POSTING_ZU,
            array_map(fn ($row) => $row['posting_id'], $alle->cohort()['rows']),
        );

        // Unbekannter Wert faellt auf 'online' zurueck — sonst wirkte ein
        // gecrafteter oder geleerter Wert wie 'alle'.
        $krumm = new Index();
        $krumm->updatedPostingStatusFilter('');
        $this->assertSame('online', $krumm->postingStatusFilter);
        $krumm->updatedPostingStatusFilter('alle');
        $this->assertSame('alle', $krumm->postingStatusFilter);
    }

    public function test_drill_down_des_geschlossenen_blocks_trifft_seine_personen(): void
    {
        // Die Zahl im Block „Geschlossene Ausschreibungen" wird aus closed_rows
        // gerechnet — also muss ihr Token auch gegen closed_rows aufloesen, sonst
        // bleibt das Modal leer (die Zeilen sind bei Status „online" nicht in der
        // Auswahl).
        $component = new Index();
        $vm = new CohortViewModel();
        $cohort = $component->cohort();

        $token = $component->drillToken('posting', 'Aushilfe Bankett', [
            'posting' => self::POSTING_ZU,
            'set' => 'closed',
        ]);
        $spec = $vm->decodeScope($token);

        $this->assertSame([102], $vm->resolveIdsFromClient($cohort['closed_rows'], $spec, 'ids'));
        // Gegenprobe: dieselbe Auswahl OHNE die beiseitegelegte Menge trifft
        // nichts — genau deshalb reist 'set' im Token mit.
        $this->assertSame([], $vm->resolveIdsFromClient($cohort['rows'], $spec, 'ids'));
    }

    public function test_zeitraum_filtert_nur_die_termine_nicht_den_bewerbungseingang(): void
    {
        // Task 10: filterFrom/filterTo (Bewerbungsdatum) sind ENTFALLEN. Ein
        // Termin-Zeitraum darf die Kohorte nicht beschneiden — sonst rechnete die
        // Erfuellungsquote einen Ausschnitt der Bewerbungen gegen den vollen
        // Bedarf.
        $this->assertFalse(
            property_exists(Index::class, 'filterFrom'),
            'kein Bewerbungs-Zeitraum mehr auf der Statistik-Seite',
        );
        $this->assertFalse(property_exists(Index::class, 'filterTo'));

        $ohne = $this->component();
        $mit = $this->component();
        $mit->interviewFrom = '2027-01-01';
        $mit->interviewTo = '2027-01-31';

        $this->assertSame(
            count($ohne->cohort()['total_ids']),
            count($mit->cohort()['total_ids']),
            'der Termin-Zeitraum laesst die Kohorte unberuehrt',
        );

        // ... und die Bewerbungen von Juli 2026 sind trotz des Zeitraums 2027 alle
        // noch da (vier Bewerber im Bestand, keiner ist Testbewerber)
        $this->assertSame(4, count($mit->cohort()['total_ids']));
    }

    public function test_bewerbung_ohne_datum_bleibt_eine_eigene_zeile(): void
    {
        // Der Zeilentyp 'ohne_datum' haengt an Stufe 2 der Praezedenz-Kette
        // (applied_at IS NULL) und NICHT am Zeitraum — er muss den Wegfall des
        // Bewerbungs-Zeitraums unveraendert ueberleben, sonst fehlt er im Block
        // „Ausgeschieden" und die Rekonziliation stimmt nicht mehr.
        Capsule::table('rec_applicants')->insert([
            ['id' => 199, 'uuid' => 'app-199', 'team_id' => self::TEAM, 'applied_at' => null,
             'rec_phase_id' => 1, 'is_test' => 0,
             'created_at' => self::HEUTE, 'updated_at' => self::HEUTE],
        ]);
        Capsule::table('rec_applicant_posting')->insert([
            ['rec_applicant_id' => 199, 'rec_posting_id' => self::POSTING_OFFEN,
             'created_at' => self::HEUTE, 'updated_at' => self::HEUTE],
        ]);

        try {
            $component = $this->component();
            $cohort = $component->cohort();

            $ohneDatum = array_values(array_filter($cohort['rows'], fn ($r) => $r['type'] === 'ohne_datum'));
            $this->assertCount(1, $ohneDatum);
            $this->assertSame([199], $ohneDatum[0]['ids']);
            $this->assertNull($ohneDatum[0]['max_applied_at'], 'ohne Datum gibt es keinen Kohorten-Anker');

            // Rekonziliation: die Zeile zaehlt in der Gesamtmenge mit
            $this->assertContains(199, $cohort['total_ids']);
            $this->assertSame(
                $component->countIn($cohort['rows'], 'ids'),
                count($cohort['total_ids']),
            );
        } finally {
            // Der Bestand ist klassenweit — die Zusatz-Zeile darf nicht in die
            // anderen Tests dieser Klasse lecken (kein order-by=random, aber die
            // Reihenfolge ist trotzdem keine Zusicherung).
            Capsule::table('rec_applicant_posting')->where('rec_applicant_id', 199)->delete();
            Capsule::table('rec_applicants')->where('id', 199)->delete();
        }
    }

    // -----------------------------------------------------------------
    // Schema und Datenbestand
    // -----------------------------------------------------------------

    /**
     * Schema aus den ECHTEN Migrationen, die eigenen per glob (Begruendung siehe
     * Klassen-Kommentar).
     *
     * Aus platforms-core nur die beiden Extra-Feld-Tabellen: eine
     * DATEN-Migration des Moduls (2026_04_12_000003_migrate_extra_fields_to_phases)
     * liest sie, sonst bricht der Durchlauf ab. Alles andere aus Fremdpaketen ist
     * nicht noetig — SQLite erzwingt Fremdschluessel ohne PRAGMA foreign_keys=ON
     * nicht, und gelesen werden nur Recruiting-Tabellen.
     */
    private static function runRealMigrations(): void
    {
        $core = self::packageRootOf(\Platform\Core\Models\CoreExtraFieldDefinition::class);

        $files = [
            $core . '/database/migrations/2026_02_07_000001_create_core_extra_field_definitions_table.php',
            $core . '/database/migrations/2026_02_07_000002_create_core_extra_field_values_table.php',
        ];

        $own = glob(dirname(__DIR__, 2) . '/database/migrations/*.php');
        sort($own);

        foreach (array_merge($files, $own) as $path) {
            if (!file_exists($path)) {
                throw new \RuntimeException("Migration fehlt: {$path}");
            }
            $migration = require $path;
            $migration->up();
        }
    }

    /** Wurzel-Aufloesung per Reflection: platforms-core liegt nicht als Geschwister der Module. */
    private static function packageRootOf(string $class): string
    {
        $dir = dirname((new \ReflectionClass($class))->getFileName());

        for ($i = 0; $i < 10; $i++) {
            if (is_dir($dir . '/database/migrations')) {
                return $dir;
            }
            $parent = dirname($dir);
            if ($parent === $dir) {
                break;
            }
            $dir = $parent;
        }

        throw new \RuntimeException('Paketwurzel nicht gefunden: ' . $class);
    }

    private static function seed(): void
    {
        $now = self::HEUTE;

        Capsule::table('rec_positions')->insert([
            ['id' => 1, 'uuid' => 'pos-1', 'team_id' => self::TEAM, 'title' => 'Kellner',
             'location' => 'Essen', 'is_active' => 1, 'created_at' => $now, 'updated_at' => $now],
            // zweite Stelle mit EIGENEM, geklontem Phasensatz — Grundlage des
            // Stellenwechsel-Falls
            ['id' => 2, 'uuid' => 'pos-2', 'team_id' => self::TEAM, 'title' => 'Küche',
             'location' => 'Wuppertal', 'is_active' => 1, 'created_at' => $now, 'updated_at' => $now],
        ]);

        Capsule::table('rec_phases')->insert([
            ['id' => 1, 'uuid' => 'ph-1', 'team_id' => self::TEAM, 'rec_position_id' => 1,
             'name' => 'Eingang', 'order' => 1, 'is_active' => 1, 'created_at' => $now, 'updated_at' => $now],
            ['id' => 2, 'uuid' => 'ph-2', 'team_id' => self::TEAM, 'rec_position_id' => 1,
             'name' => 'Telefonat', 'order' => 2, 'is_active' => 1, 'created_at' => $now, 'updated_at' => $now],
            ['id' => 3, 'uuid' => 'ph-3', 'team_id' => self::TEAM, 'rec_position_id' => 1,
             'name' => 'Schulung', 'order' => 3, 'is_active' => 1, 'created_at' => $now, 'updated_at' => $now],
            // inaktiv: darf in phaseLabels() nicht auftauchen
            ['id' => 4, 'uuid' => 'ph-4', 'team_id' => self::TEAM, 'rec_position_id' => 1,
             'name' => 'Archiv', 'order' => 4, 'is_active' => 0, 'created_at' => $now, 'updated_at' => $now],
            // geklonter Satz der ZWEITEN Stelle: gleiche orders, eigene IDs
            ['id' => 5, 'uuid' => 'ph-5', 'team_id' => self::TEAM, 'rec_position_id' => 2,
             'name' => 'Eingang', 'order' => 1, 'is_active' => 1, 'created_at' => $now, 'updated_at' => $now],
            ['id' => 6, 'uuid' => 'ph-6', 'team_id' => self::TEAM, 'rec_position_id' => 2,
             'name' => 'Probearbeit', 'order' => 3, 'is_active' => 1, 'created_at' => $now, 'updated_at' => $now],
        ]);

        Capsule::table('rec_postings')->insert([
            // online: published + aktiv, Ziel-Felder gepflegt
            ['id' => self::POSTING_OFFEN, 'uuid' => 'post-10', 'team_id' => self::TEAM, 'rec_position_id' => 1,
             'title' => 'Kellner (m/w/d)', 'activity' => 'Service', 'status' => 'published', 'is_active' => 1,
             'published_at' => '2026-07-01 08:00:00', 'closes_at' => '2026-09-30 23:59:59',
             'bedarf' => 10, 'bewerbungs_faktor' => 8.0, 'created_at' => $now, 'updated_at' => $now],
            // draft: nicht online, Ziel-Felder NICHT gepflegt
            ['id' => self::POSTING_ZU, 'uuid' => 'post-11', 'team_id' => self::TEAM, 'rec_position_id' => 1,
             'title' => 'Aushilfe Bankett', 'activity' => 'Bankett', 'status' => 'draft', 'is_active' => 1,
             'published_at' => null, 'closes_at' => null,
             'bedarf' => null, 'bewerbungs_faktor' => null, 'created_at' => $now, 'updated_at' => $now],
            // FREMDES Team: haengt per Pivot am Bewerber (die Relation ist nicht
            // team-gescopt), taucht aber im forTeam-gescopten Stammdaten-Lookup
            // NICHT auf. Bedarf 50 ist absichtlich gepflegt — genau dieser Wert
            // darf nicht durchsickern.
            ['id' => self::POSTING_FREMD, 'uuid' => 'post-12', 'team_id' => 99, 'rec_position_id' => 1,
             'title' => 'Fremdes Team', 'activity' => 'Service', 'status' => 'published', 'is_active' => 1,
             'published_at' => '2026-07-01 08:00:00', 'closes_at' => '2026-09-30 23:59:59',
             'bedarf' => 50, 'bewerbungs_faktor' => 4.0, 'created_at' => $now, 'updated_at' => $now],
        ]);

        Capsule::table('rec_applicants')->insert([
            ['id' => 101, 'uuid' => 'app-101', 'team_id' => self::TEAM, 'applied_at' => '2026-07-10',
             'rec_phase_id' => 2, 'is_test' => 0, 'created_at' => $now, 'updated_at' => $now],
            ['id' => 102, 'uuid' => 'app-102', 'team_id' => self::TEAM, 'applied_at' => '2026-07-11',
             'rec_phase_id' => 1, 'is_test' => 0, 'created_at' => $now, 'updated_at' => $now],
            // 103: STELLENWECHSEL — steht heute in Phase 1 der Stelle 1, hat aber
            // ein Log auf der Stelle 2 (order 3). Diese Tiefe gehoert nicht in den
            // Trichter dieser Ausschreibung.
            ['id' => 103, 'uuid' => 'app-103', 'team_id' => self::TEAM, 'applied_at' => '2026-07-12',
             'rec_phase_id' => 1, 'is_test' => 0, 'created_at' => $now, 'updated_at' => $now],
            // 104: haengt an der Ausschreibung des fremden Teams
            ['id' => 104, 'uuid' => 'app-104', 'team_id' => self::TEAM, 'applied_at' => '2026-07-13',
             'rec_phase_id' => 1, 'is_test' => 0, 'created_at' => $now, 'updated_at' => $now],
        ]);

        Capsule::table('rec_applicant_posting')->insert([
            ['rec_applicant_id' => 101, 'rec_posting_id' => self::POSTING_OFFEN, 'created_at' => $now, 'updated_at' => $now],
            ['rec_applicant_id' => 102, 'rec_posting_id' => self::POSTING_ZU, 'created_at' => $now, 'updated_at' => $now],
            ['rec_applicant_id' => 103, 'rec_posting_id' => self::POSTING_OFFEN, 'created_at' => $now, 'updated_at' => $now],
            ['rec_applicant_id' => 104, 'rec_posting_id' => self::POSTING_FREMD, 'created_at' => $now, 'updated_at' => $now],
        ]);

        // 101 war schon in Phase 3 und wurde auf 2 zurueckgesetzt: das Log kennt
        // die tiefere Phase, die aktuelle Phase nicht. Genau der Fall, fuer den
        // das Maximum aus beiden Quellen gebildet wird.
        Capsule::table('rec_phase_transitions')->insert([
            ['team_id' => self::TEAM, 'rec_applicant_id' => 101, 'rec_position_id' => 1,
             'from_phase_id' => 1, 'to_phase_id' => 2, 'trigger' => 'manual', 'source' => 'live',
             'occurred_at' => '2026-07-12 09:00:00', 'created_at' => $now, 'updated_at' => $now],
            ['team_id' => self::TEAM, 'rec_applicant_id' => 101, 'rec_position_id' => 1,
             'from_phase_id' => 2, 'to_phase_id' => 3, 'trigger' => 'manual', 'source' => 'live',
             'occurred_at' => '2026-07-13 09:00:00', 'created_at' => $now, 'updated_at' => $now],
            ['team_id' => self::TEAM, 'rec_applicant_id' => 101, 'rec_position_id' => 1,
             'from_phase_id' => 3, 'to_phase_id' => 2, 'trigger' => 'manual', 'source' => 'live',
             'occurred_at' => '2026-07-14 09:00:00', 'created_at' => $now, 'updated_at' => $now],
            // 103 war auf der ANDEREN Stelle in Phase order 3 (Phase-ID 6).
            // Nach dem Wechsel auf Stelle 1 ist das im Trichter dieser
            // Ausschreibung keine erreichte Stufe.
            ['team_id' => self::TEAM, 'rec_applicant_id' => 103, 'rec_position_id' => 2,
             'from_phase_id' => 5, 'to_phase_id' => 6, 'trigger' => 'manual', 'source' => 'live',
             'occurred_at' => '2026-07-13 09:00:00', 'created_at' => $now, 'updated_at' => $now],
        ]);

        // Zwei Unterschriften, und zwar bewusst UNGLEICH verteilt: eine an der
        // gepflegten Ausschreibung (zaehlt in der Quote), eine an der
        // Ausschreibung ohne Ziel (zaehlt in der Spalte „Unterschrieben", aber
        // nicht in der Quote). Das ist der Fall, in dem die Gesamt-Zeile ohne
        // benannte Bezugsgroessen unlesbar war.
        Capsule::table('rec_contracts')->insert([
            // rec_contract_template_id ist NOT NULL; welche Vorlage es ist, spielt
            // fuer die Zaehlung keine Rolle (SQLite erzwingt den FK hier nicht).
            ['uuid' => 'con-1', 'team_id' => self::TEAM, 'rec_applicant_id' => 101,
             'rec_contract_template_id' => 1,
             'status' => 'signed', 'sent_at' => '2026-07-20 10:00:00', 'signed_at' => '2026-07-21 10:00:00',
             'created_at' => $now, 'updated_at' => $now],
            ['uuid' => 'con-2', 'team_id' => self::TEAM, 'rec_applicant_id' => 104,
             'rec_contract_template_id' => 1,
             'status' => 'signed', 'sent_at' => '2026-07-22 10:00:00', 'signed_at' => '2026-07-23 10:00:00',
             'created_at' => $now, 'updated_at' => $now],
        ]);
    }
}
