<?php

namespace Platform\Recruiting\Tests\Integration;

use Illuminate\Container\Container;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Events\Dispatcher;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Facade;
use Illuminate\View\Compilers\BladeCompiler;
use Illuminate\View\Engines\EngineResolver;
use Illuminate\View\Factory as ViewFactory;
use Illuminate\View\FileViewFinder;
use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Services\Statistics\CohortViewModel;

/**
 * DIE GANZE SEITE gerendert — und am DOM geprueft.
 *
 * Zwei Zusicherungen, die sich nur hier belegen lassen:
 *
 * 1. DIE VIER BLOECKE STEHEN AUSSERHALB DES FILIAL-GUARDS. Stuenden sie im @else,
 *    zeigte die Seite ohne gewaehlte Filiale ausschliesslich die Aufforderung.
 *    Genau zu unterscheiden sind dabei zwei Zustaende, und nur der zweite ist der
 *    scharfe:
 *      - Standorte GEPFLEGT, aber keine Filiale gewaehlt: die Bloecke zeigen, was
 *        in keiner Filial-Ansicht steht (geschlossen bzw. ohne Zuordnung). Die
 *        uebrigen Bewerbungen stecken in der Auswahl — sichtbar wird sie mit der
 *        Filialwahl.
 *      - KEIN Standort gepflegt: dann ist jede Bewerbung entweder geschlossen oder
 *        ohne erreichbare Filiale, die Bloecke benennen also den GANZEN Bestand
 *        (im Test nachgerechnet: 2 + 5 = 7 von 7). Ohne Bloecke waere die Seite
 *        hier vollstaendig stumm.
 *
 * 2. KEIN ATTRIBUTWERT BRICHT VORZEITIG AB. Der Vorgaenger dieses Tests war eine
 *    Tautologie: er schnitt die Attributwerte mit einem Regex am ersten ASCII-"
 *    aus dem QUELLTEXT — also VOR dem Schaden — und konnte die Fehlerklasse, fuer
 *    die er gebaut war, per Konstruktion nicht sehen (die Variante mit ZWEI
 *    ASCII-Quotes blieb gruen, obwohl das title im DOM auf 35 statt 155 Zeichen
 *    brach). Geprueft wird deshalb am gerenderten DOM: Attributnamen gegen eine
 *    Whitelist (ein abgebrochener Wert erzeugt Attribute aus Satzwoertern) UND die
 *    Balance der typografischen Anfuehrungszeichen im geparsten Wert.
 *
 * WIE die Seite ohne Host-App rendert (die UI-Komponenten liegen in einem
 * Fremdpaket, `route()` braucht die Router-Bindings):
 *  - die `x-ui-*`-Komponenten werden durch STUBS im Temp-Verzeichnis aufgeloest
 *    (anonyme Blade-Komponenten, die ihre String-Attribute durchreichen — damit
 *    landen auch die Attribute der Komponenten-Tags im geprueften DOM);
 *  - `route()` wird nie erreicht: es steht nur im Drill-Modal-Zweig fuer eine
 *    NICHT leere Auswahl, und $drillIds ist leer;
 *  - `$this` in der Blade zeigt auf die Komponente (BoundCompilerEngine, wie
 *    StatisticsTablesRenderTest), Computed Properties reicht die Probe durch.
 */
class StatisticsPageRenderTest extends TestCase
{
    private const TEAM = 7;

    private const HEUTE = '2026-08-17 10:00:00';

    /**
     * Attributnamen, die in den Statistik-Views vorkommen DUERFEN. Bewusst eine
     * geschlossene Liste: ein vorzeitig endender Attributwert macht aus den
     * folgenden Satzwoertern Attribute („import", „er", „ebenfalls"), und die
     * fallen nur auf, wenn Unbekanntes auffaellt. Ein neues, echtes Attribut
     * einzutragen ist eine Zeile — und im Review sichtbar.
     *
     * @var list<string>
     */
    private const ERLAUBTE_ATTRIBUTE = [
        'class', 'style', 'title', 'id', 'name', 'type', 'value', 'href', 'target',
        'colspan', 'rowspan', 'scope', 'disabled', 'required', 'checked', 'selected',
        'placeholder', 'label', 'hint', 'size', 'variant', 'for', 'data-stub',
        'subtitle',
        'http-equiv', 'content',
        // Alpine und Livewire
        'x-data', 'x-show', 'x-text', 'x-cloak', 'x-on:click',
        'wire:click', 'wire:model', 'wire:model.live', 'wire:loading.attr',
        'hidefooter', 'nulllabel', 'options', 'nullable',
    ];

    private string $cacheDir;

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

        Carbon::setTestNow(Carbon::parse(self::HEUTE));

        self::runRealMigrations();
        self::seed();
    }

    public static function tearDownAfterClass(): void
    {
        Facade::clearResolvedInstances();
        Container::getInstance()->forgetInstance(AuthFactory::class);
        Carbon::setTestNow();
    }

    protected function setUp(): void
    {
        $this->cacheDir = sys_get_temp_dir() . '/statistik-page-' . getmypid() . '-' . uniqid();
        @mkdir($this->cacheDir . '/views/components', 0777, true);
        $this->writeUiStubs();
    }

    protected function tearDown(): void
    {
        foreach (['/views/components', '/views', ''] as $sub) {
            foreach (glob($this->cacheDir . $sub . '/*') ?: [] as $file) {
                if (is_file($file)) {
                    @unlink($file);
                }
            }
            @rmdir($this->cacheDir . $sub);
        }
    }

    // -----------------------------------------------------------------
    // 1. Bloecke ausserhalb des Guards
    // -----------------------------------------------------------------

    public function test_ohne_filialauswahl_stehen_die_bloecke_trotzdem_da(): void
    {
        $html = $this->renderPage(null);

        // Der Guard greift: keine Kacheln, keine Tabellen
        $this->assertStringContainsString('Bitte oben eine Filiale wählen', $html);
        $this->assertStringNotContainsString('Time-to-Hire', $html, 'keine KPI-Kacheln ohne Filiale');
        $this->assertStringNotContainsString('Eine Zeile je Ausschreibung', $html, 'keine Tabelle 1');
        $this->assertStringNotContainsString('Eine Zeile je Termin', $html, 'keine Tabelle 2');

        // ... und die vier Bloecke sind trotzdem da, MIT Zahlen
        $this->assertStringContainsString('Ausgeschieden', $html);
        $this->assertStringContainsString('Geschlossene Ausschreibungen', $html);
        $this->assertStringContainsString('Ohne Filial-Zuordnung', $html);
        $this->assertStringContainsString('Blöcke unter dieser Meldung', $html);

        // Die Zahlen im Kopf der beiden Ablage-Bloecke sind die echten: zwei
        // geschlossene (1002 Entwurf in Essen, 1005 geschlossen in Wuppertal — der
        // Block ignoriert die Filiale) und zwei ohne Filial-Zuordnung (1003 an einer
        // Stelle ohne Standort, 1004 ohne jede Ausschreibung).
        $this->assertSame(2, $this->blockTotal($html, 'Geschlossene Ausschreibungen'));
        $this->assertSame(2, $this->blockTotal($html, 'Ohne Filial-Zuordnung'));
    }

    public function test_ohne_gepflegten_standort_benennen_die_bloecke_alles(): void
    {
        // Der Zustand, in dem der Guard sonst die ganze Seite verschluckt: an KEINER
        // Stelle ist ein Standort gepflegt. Dann gibt es nichts zu waehlen — und
        // jede Bewerbung steckt in Block 2 oder 3.
        Capsule::table('rec_positions')->whereIn('id', [71, 73])->update(['location' => null]);

        try {
            $html = $this->renderPage(null);

            $this->assertStringContainsString('An keiner Stelle ist ein Standort gepflegt', $html);
            $this->assertStringContainsString('Blöcke unter dieser Meldung', $html);

            // unveraendert 2 geschlossene (1002 Entwurf, 1005 geschlossen) — aber
            // jetzt ist JEDE andere Bewerbung ohne erreichbare Filiale: 1001, 1006
            // und 1007 haengen an Ausschreibungen, deren Stelle den Standort gerade
            // verloren hat, dazu 1003 (Stelle ohne Standort) und 1004 (ohne
            // Ausschreibung). 2 + 5 = 7 = alle Bewerbungen des Bestands: in diesem
            // Zustand benennen die Bloecke wirklich alles.
            $this->assertSame(2, $this->blockTotal($html, 'Geschlossene Ausschreibungen'));
            $this->assertSame(5, $this->blockTotal($html, 'Ohne Filial-Zuordnung'));
            $this->assertSame(
                7,
                (int) Capsule::table('rec_applicants')->where('team_id', self::TEAM)->where('is_test', 0)->count(),
            );
        } finally {
            Capsule::table('rec_positions')->where('id', 71)->update(['location' => 'Essen']);
            Capsule::table('rec_positions')->where('id', 73)->update(['location' => 'Wuppertal']);
        }
    }

    public function test_mit_filiale_stehen_kacheln_tabellen_und_bloecke_zusammen(): void
    {
        $html = $this->renderPage('Essen');

        $this->assertStringNotContainsString('Bitte oben eine Filiale wählen', $html);
        $this->assertStringContainsString('Time-to-Hire', $html);
        $this->assertStringContainsString('Eine Zeile je Ausschreibung', $html);
        $this->assertStringContainsString('Eine Zeile je Termin', $html);
        $this->assertStringContainsString('Ohne Filial-Zuordnung', $html);

        // Die Ablage-Bloecke haengen nicht an der Filialauswahl — dieselben Zahlen
        // wie ohne Auswahl
        $this->assertSame(2, $this->blockTotal($html, 'Geschlossene Ausschreibungen'));
        $this->assertSame(2, $this->blockTotal($html, 'Ohne Filial-Zuordnung'));
    }

    public function test_die_termin_tabelle_rendert_zeile_herkunft_und_belegung(): void
    {
        // Tabelle 2 war die Tabelle, die am laengsten von NICHTS ausgefuehrt wurde.
        // Hier laeuft sie mit Daten: eine Termin-Zeile, zwei Herkunfts-Unterzeilen
        // (zwei Ausschreibungen) und die Summenzeile.
        $html = $this->renderPage('Essen');

        $this->assertStringNotContainsString('Keine Schulungstermine in dieser Auswahl', $html);
        $this->assertStringContainsString('10.08.2026 10:00', $html, 'die Termin-Zeile');
        $this->assertStringContainsString('(2 Ausschreibungen)', $html, 'zwei Herkuenfte');
        $this->assertSame(2, substr_count($html, 'Herkunft:'), 'eine Unterzeile je Ausschreibung');
        $this->assertStringContainsString('Bahnhof Duisburg, Gleis 3', $html, 'Veranstaltungsort');

        // BELEGUNG (meter.blade.php): zwei belegte Plaetze von sechs, Balken 33 %.
        // Ohne diese Zusicherung ueberlebt eine Mutation im Balken-Partial jeden
        // Test — die Zelle rendert dann zwar Unsinn, aber sie rendert.
        $this->assertStringContainsString('style="width: 33%"', $html, 'Balken 2 von 6');
        $this->assertSame(['taken' => 2, 'max' => 6], $this->meterZahlen($html, 0), 'IST/SOLL der Termin-Zeile');

        // Die Summenzeile rechnet ueber dieselbe Auswahl (ein Termin) und zeigt
        // deshalb dieselben Zahlen — und keine erfundene Quote.
        $this->assertStringContainsString('Termin dieser Auswahl', $html);
    }

    /**
     * Zahlen des n-ten Belegungs-Balkens der Seite, so wie sie im DOM stehen
     * („2 / 6“). Der Balken-Container ist das div mit fester Breite
     * (meter.blade.php).
     *
     * @return array{taken:int, max:?int}
     */
    private function meterZahlen(string $html, int $index): array
    {
        $xpath = new \DOMXPath($this->dom($html));
        $meters = $xpath->query('descendant-or-self::div[contains(@class, "mx-auto w-20")]');
        $this->assertGreaterThan($index, $meters->length, 'kein Belegungs-Balken gerendert');

        $zeile = trim((string) preg_replace('/\s+/u', ' ', $meters->item($index)->textContent));
        $this->assertMatchesRegularExpression('#^(\d+) / (\d+|∞)#u', $zeile, "unerwartete Belegung: {$zeile}");
        preg_match('#^(\d+) / (\d+|∞)#u', $zeile, $treffer);

        return [
            'taken' => (int) $treffer[1],
            'max' => $treffer[2] === '∞' ? null : (int) $treffer[2],
        ];
    }

    /**
     * Der WERT einer KPI-Kachel, ueber ihr Label gefunden: im Markup steht der Wert
     * im Element VOR dem Label-Block, beides in derselben Kachel.
     */
    private function kpiWert(string $html, string $label): string
    {
        $xpath = new \DOMXPath($this->dom($html));
        $labelBlock = $xpath->query(
            'descendant-or-self::div[contains(@class, "text-sm")][starts-with(normalize-space(text()), "' . $label . '")]',
        )->item(0);
        $this->assertNotNull($labelBlock, "keine Kachel mit dem Label „{$label}“");

        $wert = $labelBlock->previousSibling;
        while ($wert !== null && !$wert instanceof \DOMElement) {
            $wert = $wert->previousSibling;
        }
        $this->assertNotNull($wert, "kein Wert-Element in der Kachel „{$label}“");

        return trim($wert->textContent);
    }

    /** Gerenderte Seite als DOM — mit UTF-8-Hinweis, sonst zerfallen die Umlaute. */
    private function dom(string $html): \DOMDocument
    {
        $dom = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML('<meta http-equiv="Content-Type" content="text/html; charset=utf-8">' . $html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return $dom;
    }

    public function test_die_conversion_kachel_liest_dieselbe_quelle_wie_die_tabelle(): void
    {
        // DER VIERTE FALL von „richtig gerechnet, falsch beschriftet": die Kachel
        // zeigte 0 %, wo die Gesamt-Zeile der Tabelle „–" zeigt. Die Regel steht im
        // CohortViewModel („keine Bewerbungen heisst keine Quote, nicht 0 %"), die
        // Kachel hatte sie neu erfunden — und es gab dort ueberhaupt keine
        // Zusicherung: `tiles()['conversion']` liess sich auf jeden Wert setzen,
        // ohne dass ein Test rot wurde.
        $vm = new CohortViewModel();

        $mitDaten = new StatisticsRenderProbe();
        $mitDaten->ortFilter = 'Essen';
        $this->assertSame(
            $vm->conversionOf($mitDaten->cohort['rows']),
            $mitDaten->tiles['conversion'],
            'die Kachel rechnet nicht selbst, sie liest conversionOf',
        );

        // LEERE Auswahl: keine Quote, NICHT 0 %. Derselbe Wert wie in der Tabelle.
        $leer = new StatisticsRenderProbe();
        $leer->ortFilter = 'Essen';
        $leer->activityFilter = 'Gibt es nicht';
        $this->assertSame([], $leer->cohort['rows'], 'die Auswahl ist wirklich leer');
        $this->assertNull($leer->tiles['conversion']);
        $this->assertNull($vm->conversionOf($leer->cohort['rows']));

        // ... und die Seite zeigt dort „–", nicht „0 %"
        $html = $this->renderPage('Essen', 'Gibt es nicht');
        $this->assertSame('–', $this->kpiWert($html, 'Conversion'));
        $this->assertStringContainsString('Keine Bewerbungen in dieser Auswahl — keine Quote', $html);

        // Gegenprobe mit Daten: dort steht eine echte Quote in Prozent
        $this->assertMatchesRegularExpression('/^\d+ %$/', $this->kpiWert($this->renderPage('Essen'), 'Conversion'));
    }

    public function test_ohne_filiale_behauptet_der_block_nicht_das_ganze_team(): void
    {
        // Der Status-Filter steht standardmaessig auf „online" und schneidet schon —
        // „die Auswahl ist das ganze Team" waere dann falsch (gemessen: „Abgesagt 1"
        // bei zwei Absagen im Team).
        $online = $this->renderPage(null);
        $this->assertStringContainsString('aber nicht das ganze Team', $online);
        $this->assertStringNotContainsString('ist die Auswahl das ganze Team', $online);

        // Mit Status „alle" und ohne weitere Filter ist die Aussage wieder wahr
        $alle = $this->renderPage(null, null, 'alle');
        $this->assertStringContainsString('ist die Auswahl das ganze Team', $alle);
        $this->assertStringNotContainsString('aber nicht das ganze Team', $alle);
    }

    public function test_der_auswahl_hinweis_nennt_jede_aktive_filterdimension(): void
    {
        // Aufgezaehlt werden die TATSAECHLICH aktiven Dimensionen. Eine feste Liste
        // liess die FILIALE aus, obwohl sie Block 1 genauso schneidet — und bei
        // Filiale plus Status „alle" stand gar kein Zusatz, obwohl die Zahlen
        // geschnitten sind.
        $mitFiliale = $this->renderPage('Essen', null, 'alle');
        $this->assertStringContainsString('Die Zahlen folgen der aktuellen Auswahl (Filiale)', $mitFiliale);

        $mitStatus = $this->renderPage('Essen');
        $this->assertStringContainsString('aktuellen Auswahl (Filiale, Status)', $mitStatus);

        $mitDrei = $this->renderPage('Essen', 'Service');
        $this->assertStringContainsString('aktuellen Auswahl (Filiale, Status, Tätigkeit)', $mitDrei);

        // Ohne jede Einschraenkung (keine Filiale, Status „alle") faellt der Zusatz weg
        $ohne = $this->renderPage(null, null, 'alle');
        $this->assertStringNotContainsString('Die Zahlen folgen der aktuellen Auswahl', $ohne);
    }

    public function test_kapazitaet_null_wird_wie_unbegrenzt_angezeigt(): void
    {
        // Erreichbar ueber die Termin-Pflege (max_participants ist `min:0`). Die
        // Summen-Rechnung zaehlt so einen Termin als „ohne Platzbegrenzung", die
        // Zelle schrieb aber „2 / 0" und behauptete damit eine Ueberbuchung, die
        // niemand nachrechnen kann.
        Capsule::table('rec_interviews')->where('id', 720)->update(['max_participants' => 0]);

        try {
            $html = $this->renderPage('Essen');

            $this->assertSame(['taken' => 2, 'max' => null], $this->meterZahlen($html, 0), 'die 0 liest wie ∞');
            $this->assertStringContainsString('/&nbsp;∞', $html);
            $this->assertStringNotContainsString('/&nbsp;0', $html, 'keine Kapazität 0 in der Anzeige');
            $this->assertStringNotContainsString('style="width:', $html, 'ohne Nenner kein Balken');
        } finally {
            Capsule::table('rec_interviews')->where('id', 720)->update(['max_participants' => 6]);
        }
    }

    // -----------------------------------------------------------------
    // 2. Attribut-Waechter am DOM
    // -----------------------------------------------------------------

    public function test_kein_attributwert_bricht_vorzeitig_ab(): void
    {
        // Beide Zustaende der Seite, weil sie unterschiedliche Texte rendern.
        foreach ([null, 'Essen'] as $ort) {
            $this->assertAttributesIntact($this->renderPage($ort), 'ortFilter=' . var_export($ort, true));
        }
    }

    public function test_waechter_prueft_auch_die_texte_mit_aktivem_filter(): void
    {
        // Mit einschraenkendem Filter erscheinen ANDERE Block-Texte (kein
        // Vollstaendigkeits-Versprechen) — also auch andere Attributwerte.
        $html = $this->renderPage('Essen', 'Service');

        $this->assertAttributesIntact($html, 'mit Taetigkeits-Filter');
        $this->assertStringContainsString('folgt der aktuellen Auswahl', $html,
            'kein Vollstaendigkeits-Versprechen bei aktivem Filter');
        $this->assertStringNotContainsString('ist dieser Block die vollständige Liste', $html);
    }

    public function test_ohne_einschraenkende_filter_darf_der_text_vollstaendigkeit_zusagen(): void
    {
        $html = $this->renderPage('Essen');

        $this->assertStringContainsString('ist dieser Block die vollständige Liste', $html);
        $this->assertStringNotContainsString('folgt der aktuellen Auswahl', $html);
    }

    public function test_der_leer_text_behauptet_nichts_ueber_die_gesamtheit(): void
    {
        // DERSELBE Fehler wie im Blocktext, nur im leeren Zweig: „Keine geschlossene
        // Ausschreibung mit Bewerbungen" ist eine Aussage ueber die Gesamtheit, gilt
        // aber nur fuer die Auswahl. Mit Taetigkeit „Service" fallen BEIDE
        // geschlossenen Ausschreibungen (Bankett, Küche) nur durch den Filter heraus.
        $mitFilter = $this->renderPage('Essen', 'Service');

        $this->assertSame(0, $this->blockTotal($mitFilter, 'Geschlossene Ausschreibungen'), 'leergefiltert');
        $this->assertStringContainsString(
            'In dieser Auswahl keine geschlossene Ausschreibung mit Bewerbungen',
            $mitFilter,
        );
        $this->assertStringNotContainsString('>Keine geschlossene Ausschreibung mit Bewerbungen.', $mitFilter);

        // Und die Gegenrichtung fuer den anderen Block: mit „Bankett" ist der Block
        // „Ohne Filial-Zuordnung" leergefiltert (1003 ist Service, 1004 hat keine
        // Taetigkeit).
        $bankett = $this->renderPage('Essen', 'Bankett');
        $this->assertSame(0, $this->blockTotal($bankett, 'Ohne Filial-Zuordnung'));
        $this->assertStringContainsString(
            'In dieser Auswahl hängt jede Bewerbung an einer Ausschreibung mit gepflegtem Standort',
            $bankett,
        );

        // OHNE Filter darf der knappe Satz stehen — geprueft im echten Leerzustand:
        // ohne die Pivot-Zeilen der geschlossenen Ausschreibungen ist der Block leer,
        // und dann ist „keine geschlossene Ausschreibung" die Wahrheit.
        Capsule::table('rec_applicant_posting')->whereIn('rec_applicant_id', [1002, 1005])->delete();

        try {
            $ohneFilter = $this->renderPage('Essen');

            $this->assertSame(0, $this->blockTotal($ohneFilter, 'Geschlossene Ausschreibungen'));
            $this->assertStringContainsString('Keine geschlossene Ausschreibung mit Bewerbungen.', $ohneFilter);
            $this->assertStringNotContainsString('In dieser Auswahl keine geschlossene', $ohneFilter);
        } finally {
            $now = self::HEUTE;
            Capsule::table('rec_applicant_posting')->insert([
                ['rec_applicant_id' => 1002, 'rec_posting_id' => 711, 'created_at' => $now, 'updated_at' => $now],
                ['rec_applicant_id' => 1005, 'rec_posting_id' => 713, 'created_at' => $now, 'updated_at' => $now],
            ]);
        }
    }

    /**
     * DIE Zusicherung: jedes Element im gerenderten DOM traegt nur bekannte
     * Attribute, und in jedem Attributwert ist jedes typografische
     * Anfuehrungszeichen geschlossen.
     */
    private function assertAttributesIntact(string $html, string $label): void
    {
        $geprueft = 0;
        foreach ((new \DOMXPath($this->dom($html)))->query('descendant-or-self::*') as $element) {
            if (!$element instanceof \DOMElement) {
                continue;
            }
            foreach ($element->attributes as $attribute) {
                $geprueft++;
                $name = strtolower($attribute->nodeName);

                $this->assertContains(
                    $name,
                    self::ERLAUBTE_ATTRIBUTE,
                    "{$label}: unbekanntes Attribut <{$element->tagName} {$name}> — so sieht ein "
                        . 'vorzeitig endender Attributwert aus (der Rest des Satzes wird zu Attributen). '
                        . 'Ist das Attribut echt, gehört es in ERLAUBTE_ATTRIBUTE.',
                );

                // BALANCE der typografischen Anfuehrungszeichen, und zwar ohne
                // Vorbedingung — jeder Wert wird gerechnet.
                //
                // Runde 3 hatte hier `if ($offen > 0)` stehen, und das war ein
                // Rueckschritt: ein VERWAISTER SCHLIESSER (Import“, oeffnendes „
                // verloren) hat keinen Oeffner, wurde also nie geprueft und blieb
                // gruen. Die damalige Begruendung („faengt die Whitelist") trifft
                // fuer diesen Fall nicht zu — ein verwaister Schliesser bricht kein
                // Attribut auf, er ist nur ein halbes Zitat, und niemand sonst sieht
                // ihn. Deshalb gibt es jetzt keine Vorbedingung mehr.
                //
                // Das Zeichen “ ist zweideutig: deutscher SCHLIESSER und englischer
                // OEFFNER. Aufgeloest wird das ueber die Gleichung statt ueber eine
                // Fallunterscheidung — jedes “ gehoert zu genau einem „ (deutsches
                // Paar) oder zu genau einem ” (englisches Paar):
                //
                //     Anzahl(“) === Anzahl(„) + Anzahl(”)
                //
                // Damit sind „…“ und “…” beide in Ordnung (kein falsches Rot fuer
                // englische Typografie), waehrend „…" (gemischt), …“ (verwaister
                // Schliesser) und „… (verwaister Oeffner) rot werden. Zwei ASCII-
                // Quotes ("…") faengt die Whitelist oben, nicht diese Gleichung.
                $wert = (string) $attribute->nodeValue;
                $mitte = substr_count($wert, '“');
                $deutschOffen = substr_count($wert, '„');
                $englischZu = substr_count($wert, '”');

                $this->assertSame(
                    $deutschOffen + $englischZu,
                    $mitte,
                    "{$label}: Attribut {$name} mit unbalanciertem Anführungszeichen "
                        . "(„ {$deutschOffen}, “ {$mitte}, ” {$englischZu}): "
                        . mb_substr($wert, 0, 80),
                );
            }
        }

        $this->assertGreaterThan(100, $geprueft, "{$label}: zu wenige Attribute geprüft — rendert die Seite?");
    }

    /** Die Zahl im Kopf eines Blocks (die Pille neben dem Titel). */
    private function blockTotal(string $html, string $title): int
    {
        $xpath = new \DOMXPath($this->dom($html));
        $titleSpan = $xpath->query('descendant-or-self::span[normalize-space(text())="' . $title . '"]')->item(0);
        $this->assertNotNull($titleSpan, "Block „{$title}“ nicht gefunden");

        $next = $titleSpan->nextSibling;
        while ($next !== null && !$next instanceof \DOMElement) {
            $next = $next->nextSibling;
        }
        $this->assertNotNull($next, "keine Zahl neben dem Blocktitel „{$title}“");

        return (int) trim($next->textContent);
    }

    // -----------------------------------------------------------------
    // Render-Werkzeug
    // -----------------------------------------------------------------

    private function renderPage(?string $ort, ?string $activity = null, string $status = 'online'): string
    {
        $component = new StatisticsRenderProbe();
        $component->ortFilter = $ort;
        $component->activityFilter = $activity;
        $component->postingStatusFilter = $status;

        $viewsRoot = dirname(__DIR__, 2) . '/resources/views';

        $files = new Filesystem();
        $compiler = new BladeCompiler($files, $this->cacheDir);

        $resolver = new EngineResolver();
        $resolver->register('blade', fn () => new BoundCompilerEngine($compiler, $files, $component));

        $finder = new FileViewFinder($files, [$this->cacheDir . '/views', $viewsRoot]);
        $finder->addNamespace('recruiting', $viewsRoot);

        $factory = new ViewFactory($resolver, $finder, new Dispatcher(new Container()));

        // Zwei Bindings, die der ComponentTagCompiler braucht, wenn er auf ein
        // <x-ui-*>-Tag stoesst:
        //  - die View-Factory, um `components.ui-panel` zu FINDEN (unsere Stubs),
        //  - eine Application, weil er ZUERST einen Klassennamen raet
        //    (guessClassName -> app()->getNamespace()). Die Attrappe liefert nur
        //    diesen Namespace; die Klasse existiert nicht, und danach greift die
        //    View-Auflösung.
        $container = Container::getInstance();
        $container->instance(\Illuminate\Contracts\View\Factory::class, $factory);
        // 'view' zusaetzlich, weil die anonyme Komponente ihre Factory ueber den
        // Alias holt (Illuminate\View\Component::factory).
        $container->instance('view', $factory);
        $container->instance(
            \Illuminate\Contracts\Foundation\Application::class,
            new class extends Container implements \Illuminate\Contracts\Foundation\Application
            {
                public function getNamespace()
                {
                    return 'App\\';
                }

                public function version() { return '0.0.0-test'; }
                public function basePath($path = '') { return ''; }
                public function bootstrapPath($path = '') { return ''; }
                public function configPath($path = '') { return ''; }
                public function databasePath($path = '') { return ''; }
                public function langPath($path = '') { return ''; }
                public function publicPath($path = '') { return ''; }
                public function resourcePath($path = '') { return ''; }
                public function storagePath($path = '') { return ''; }
                public function environment(...$environments) { return 'testing'; }
                public function runningInConsole() { return true; }
                public function runningUnitTests() { return true; }
                public function hasDebugModeEnabled() { return false; }
                public function maintenanceMode() { throw new \RuntimeException('nicht benutzt'); }
                public function isDownForMaintenance() { return false; }
                public function registerConfiguredProviders() {}
                public function register($provider, $force = false) { return $provider; }
                public function registerDeferredProvider($provider, $service = null) {}
                public function resolveProvider($provider) { throw new \RuntimeException('nicht benutzt'); }
                public function booted($callback) {}
                public function booting($callback) {}
                public function bootstrapWith(array $bootstrappers) {}
                public function configurationIsCached() { return false; }
                public function detectEnvironment(\Closure $callback) { return 'testing'; }
                public function environmentFile() { return '.env'; }
                public function environmentFilePath() { return ''; }
                public function environmentPath() { return ''; }
                public function getCachedConfigPath() { return ''; }
                public function getCachedPackagesPath() { return ''; }
                public function getCachedRoutesPath() { return ''; }
                public function getCachedServicesPath() { return ''; }
                public function getLocale() { return 'de'; }
                public function getProviders($provider) { return []; }
                public function hasBeenBootstrapped() { return true; }
                public function loadDeferredProviders() {}
                public function setLocale($locale) {}
                public function shouldSkipMiddleware() { return true; }
                public function terminate() {}
                public function terminating($callback) { return $this; }
                public function boot() {}
                public function isBooted() { return true; }
            },
        );

        try {
            return $factory->make('recruiting::livewire.statistics.index')->render();
        } finally {
            $container->forgetInstance(\Illuminate\Contracts\View\Factory::class);
            $container->forgetInstance('view');
            $container->forgetInstance(\Illuminate\Contracts\Foundation\Application::class);
        }
    }

    /**
     * Stubs fuer die UI-Komponenten des Fremdpakets. Sie reichen ihre STRING-
     * Attribute durch (Array-Attribute wie :options nicht — die sind keine
     * HTML-Attribute), damit auch die Attribute der Komponenten-Tags im geprueften
     * DOM landen.
     */
    private function writeUiStubs(): void
    {
        $bag = '{{ $attributes->filter(fn ($value) => is_string($value) || is_numeric($value)) }}';

        $stubs = [
            'ui-panel' => '<div data-stub="panel" ' . $bag . '>{{ $slot }}</div>',
            'ui-button' => '<button type="button" ' . $bag . '>{{ $slot }}</button>',
            'ui-input-select' => '<select data-stub="select" ' . $bag . '></select>',
            'ui-input-date' => '<input type="date" data-stub="date" ' . $bag . ' />',
            'ui-modal' => '<div data-stub="modal" ' . $bag . '>{{ $header ?? \'\' }}{{ $slot }}</div>',
        ];

        foreach ($stubs as $name => $markup) {
            file_put_contents($this->cacheDir . '/views/components/' . $name . '.blade.php', $markup);
        }
    }

    // -----------------------------------------------------------------
    // Schema und Datenbestand
    // -----------------------------------------------------------------

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
            ['id' => 71, 'uuid' => 'ppos-71', 'team_id' => self::TEAM, 'title' => 'Kellner',
             'location' => 'Essen', 'is_active' => 1, 'created_at' => $now, 'updated_at' => $now],
            // ohne gepflegten Standort -> Block „Ohne Filial-Zuordnung"
            ['id' => 72, 'uuid' => 'ppos-72', 'team_id' => self::TEAM, 'title' => 'Springer',
             'location' => null, 'is_active' => 1, 'created_at' => $now, 'updated_at' => $now],
            ['id' => 73, 'uuid' => 'ppos-73', 'team_id' => self::TEAM, 'title' => 'Küche',
             'location' => 'Wuppertal', 'is_active' => 1, 'created_at' => $now, 'updated_at' => $now],
        ]);

        Capsule::table('rec_phases')->insert([
            ['id' => 101, 'uuid' => 'pph-101', 'team_id' => self::TEAM, 'rec_position_id' => 71,
             'name' => 'Eingang', 'order' => 1, 'is_active' => 1, 'created_at' => $now, 'updated_at' => $now],
            ['id' => 102, 'uuid' => 'pph-102', 'team_id' => self::TEAM, 'rec_position_id' => 71,
             // Apostroph im Phasennamen: er reist durch @js in den wire:click und
             // durch mehrere title-Attribute
             'name' => "Telefonat 'kurz'", 'order' => 2, 'is_active' => 1,
             'created_at' => $now, 'updated_at' => $now],
            ['id' => 103, 'uuid' => 'pph-103', 'team_id' => self::TEAM, 'rec_position_id' => 72,
             'name' => 'Eingang', 'order' => 1, 'is_active' => 1, 'created_at' => $now, 'updated_at' => $now],
            ['id' => 104, 'uuid' => 'pph-104', 'team_id' => self::TEAM, 'rec_position_id' => 73,
             'name' => 'Eingang', 'order' => 1, 'is_active' => 1, 'created_at' => $now, 'updated_at' => $now],
        ]);

        Capsule::table('rec_postings')->insert([
            ['id' => 710, 'uuid' => 'ppost-710', 'team_id' => self::TEAM, 'rec_position_id' => 71,
             'title' => 'Kellner (m/w/d)', 'activity' => 'Service', 'status' => 'published', 'is_active' => 1,
             'published_at' => '2026-07-01 08:00:00', 'closes_at' => '2026-09-30 23:59:59',
             'bedarf' => 10, 'bewerbungs_faktor' => 8.0, 'created_at' => $now, 'updated_at' => $now],
            ['id' => 711, 'uuid' => 'ppost-711', 'team_id' => self::TEAM, 'rec_position_id' => 71,
             'title' => 'Aushilfe Bankett', 'activity' => 'Bankett', 'status' => 'draft', 'is_active' => 1,
             'published_at' => null, 'closes_at' => null,
             'bedarf' => null, 'bewerbungs_faktor' => null, 'created_at' => $now, 'updated_at' => $now],
            ['id' => 712, 'uuid' => 'ppost-712', 'team_id' => self::TEAM, 'rec_position_id' => 72,
             'title' => 'Springer ohne Standort', 'activity' => 'Service', 'status' => 'published', 'is_active' => 1,
             'published_at' => null, 'closes_at' => null,
             'bedarf' => null, 'bewerbungs_faktor' => null, 'created_at' => $now, 'updated_at' => $now],
            ['id' => 713, 'uuid' => 'ppost-713', 'team_id' => self::TEAM, 'rec_position_id' => 73,
             'title' => 'Küchenhilfe alt', 'activity' => 'Küche', 'status' => 'closed', 'is_active' => 1,
             'published_at' => null, 'closes_at' => null,
             'bedarf' => null, 'bewerbungs_faktor' => null, 'created_at' => $now, 'updated_at' => $now],
            // ZWEITE online-Ausschreibung derselben Filiale: nur so bekommt der
            // Termin unten zwei HERKUNFTS-Unterzeilen
            ['id' => 714, 'uuid' => 'ppost-714', 'team_id' => self::TEAM, 'rec_position_id' => 71,
             'title' => 'Aushilfe Service', 'activity' => 'Service', 'status' => 'published', 'is_active' => 1,
             'published_at' => null, 'closes_at' => null,
             'bedarf' => null, 'bewerbungs_faktor' => null, 'created_at' => $now, 'updated_at' => $now],
        ]);

        // TERMIN mit Buchungen: ohne ihn rendert Tabelle 2 nur ihre Leer-Meldung —
        // Datenzeile, Herkunfts-Unterzeilen, Belegung und Summenzeile blieben
        // ungerendert, und genau diese Tabelle war die, die am laengsten von nichts
        // ausgefuehrt wurde.
        Capsule::table('rec_interview_types')->insert([
            ['id' => 81, 'uuid' => 'ptype-81', 'team_id' => self::TEAM, 'name' => 'Schulung',
             'is_active' => 1, 'created_at' => $now, 'updated_at' => $now],
        ]);

        Capsule::table('rec_interviews')->insert([
            ['id' => 720, 'uuid' => 'piv-720', 'team_id' => self::TEAM, 'interview_type_id' => 81,
             'rec_position_id' => 71, 'rec_posting_id' => 710,
             'title' => 'Schulung August', 'location' => 'Bahnhof Duisburg, Gleis 3',
             'starts_at' => '2026-08-10 10:00:00', 'max_participants' => 6,
             'is_active' => 1, 'created_at' => $now, 'updated_at' => $now],
        ]);

        Capsule::table('rec_applicants')->insert([
            // 1001: Essen, online, geparkt -> Block „Ausgeschieden" hat Inhalt
            ['id' => 1001, 'uuid' => 'papp-1001', 'team_id' => self::TEAM, 'applied_at' => '2026-07-01',
             'rec_phase_id' => 102, 'is_parked' => 1, 'is_test' => 0,
             'created_at' => $now, 'updated_at' => $now],
            ['id' => 1002, 'uuid' => 'papp-1002', 'team_id' => self::TEAM, 'applied_at' => '2026-07-02',
             'rec_phase_id' => 101, 'is_parked' => 0, 'is_test' => 0, 'created_at' => $now, 'updated_at' => $now],
            ['id' => 1003, 'uuid' => 'papp-1003', 'team_id' => self::TEAM, 'applied_at' => '2026-07-03',
             'rec_phase_id' => 103, 'is_parked' => 0, 'is_test' => 0, 'created_at' => $now, 'updated_at' => $now],
            // ohne Ausschreibung (kein Pivot)
            ['id' => 1004, 'uuid' => 'papp-1004', 'team_id' => self::TEAM, 'applied_at' => '2026-07-04',
             'rec_phase_id' => 101, 'is_parked' => 0, 'is_test' => 0, 'created_at' => $now, 'updated_at' => $now],
            ['id' => 1005, 'uuid' => 'papp-1005', 'team_id' => self::TEAM, 'applied_at' => '2026-07-05',
             'rec_phase_id' => 104, 'is_parked' => 0, 'is_test' => 0, 'created_at' => $now, 'updated_at' => $now],
            // 1006 und 1007 sitzen im Termin, und zwar aus ZWEI verschiedenen
            // Ausschreibungen -> zwei Herkunfts-Unterzeilen
            ['id' => 1006, 'uuid' => 'papp-1006', 'team_id' => self::TEAM, 'applied_at' => '2026-07-06',
             'rec_phase_id' => 101, 'is_parked' => 0, 'is_test' => 0, 'created_at' => $now, 'updated_at' => $now],
            ['id' => 1007, 'uuid' => 'papp-1007', 'team_id' => self::TEAM, 'applied_at' => '2026-07-07',
             'rec_phase_id' => 101, 'is_parked' => 0, 'is_test' => 0, 'created_at' => $now, 'updated_at' => $now],
        ]);

        Capsule::table('rec_applicant_posting')->insert([
            ['rec_applicant_id' => 1001, 'rec_posting_id' => 710, 'created_at' => $now, 'updated_at' => $now],
            ['rec_applicant_id' => 1002, 'rec_posting_id' => 711, 'created_at' => $now, 'updated_at' => $now],
            ['rec_applicant_id' => 1003, 'rec_posting_id' => 712, 'created_at' => $now, 'updated_at' => $now],
            ['rec_applicant_id' => 1005, 'rec_posting_id' => 713, 'created_at' => $now, 'updated_at' => $now],
            ['rec_applicant_id' => 1006, 'rec_posting_id' => 710, 'created_at' => $now, 'updated_at' => $now],
            ['rec_applicant_id' => 1007, 'rec_posting_id' => 714, 'created_at' => $now, 'updated_at' => $now],
        ]);

        // Zwei belegte Plaetze von sechs -> Belegungsbalken 33 %
        Capsule::table('rec_interview_bookings')->insert([
            ['id' => 901, 'uuid' => 'pivb-901', 'team_id' => self::TEAM, 'rec_interview_id' => 720,
             'rec_applicant_id' => 1006, 'status' => 'confirmed', 'seat_released_at' => null,
             'is_active' => 1, 'created_at' => $now, 'updated_at' => $now],
            ['id' => 902, 'uuid' => 'pivb-902', 'team_id' => self::TEAM, 'rec_interview_id' => 720,
             'rec_applicant_id' => 1007, 'status' => 'attended', 'seat_released_at' => null,
             'is_active' => 1, 'created_at' => $now, 'updated_at' => $now],
        ]);
    }
}
