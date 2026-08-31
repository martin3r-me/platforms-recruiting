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
use Illuminate\View\Engines\CompilerEngine;
use Illuminate\View\Engines\EngineResolver;
use Illuminate\View\Factory as ViewFactory;
use Illuminate\View\FileViewFinder;
use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Livewire\Statistics\Index;

/**
 * Die beiden neuen Tabellen ECHT GERENDERT — nicht nach Stichworten durchsucht.
 *
 * Warum es diesen Test gibt: bis Task 10 hatte keine Zeile dieser beiden Blades je
 * einen Renderer gesehen. Die Spaltenzahl einer Tabelle ist genau die Sorte
 * Fehler, die im Code unsichtbar und im Browser sofort sichtbar ist: die
 * Gruppenkoepfe stehen mit `colspan` in einem Array, die Zellen entstehen in vier
 * verschiedenen Schleifen und Partials (cells, conversion, meter). Verrutscht eine,
 * kippt die ganze Tabelle — und kein Unit-Test der Zahlen merkt es.
 *
 * Geprueft wird deshalb die INVARIANTE der Tabelle: die Summe der `colspan`s der
 * Gruppenzeile ist die Zellenzahl JEDER anderen Zeile — Kopfzeile, Datenzeile,
 * Unterzeile (Herkunft) und Summenzeile, in beiden Tabellen.
 *
 * WIE gerendert wird: mit dem ECHTEN BladeCompiler und einer eigenen View-Factory
 * (Namespace `recruiting::` auf resources/views), damit die @include-Partials
 * mitlaufen. Zwei Eingriffe, beide minimal und beide gepruefte Textersetzungen:
 *  - der `<x-ui-panel>`-Rahmen wird entfernt. Die UI-Komponenten liegen in einem
 *    Fremdpaket, ihre Aufloesung braucht die Host-App — und getestet wird hier die
 *    Tabelle, nicht der Rahmen. Dass die Ersetzung greift, sichert je eine
 *    Zusicherung ab (genau ein Vorkommen);
 *  - `$this` im Blade zeigt auf die Livewire-Komponente. Dafuer bindet die
 *    Engine die kompilierte View an die Komponenten-Instanz (BoundCompilerEngine)
 *    — genau das macht Livewire in Produktion auch.
 *
 * Computed Properties (`$this->cohort`, `$this->postingGroups`, …) gibt es nur im
 * Livewire-Lebenszyklus; die Probe unten reicht sie als Methoden-Aufrufe durch und
 * merkt sie sich, damit eine Tabelle nicht zehnmal dieselbe Query feuert.
 */
class StatisticsTablesRenderTest extends TestCase
{
    private const TEAM = 5;

    /** Ausschreibung mit gepflegtem Ziel (Bedarf + Faktor + Laufzeit). */
    private const POSTING_KELLNER = 510;

    /** Ausschreibung OHNE Ziel — graue Ampeln, „–" in der Bedarfs-Spalte. */
    private const POSTING_BANKETT = 511;

    /** Nicht online (draft): Grundlage des Blocks „Geschlossene Ausschreibungen". */
    private const POSTING_ALT = 512;

    /** Termin mit Teilnehmern aus ZWEI Ausschreibungen -> zwei Herkunfts-Unterzeilen. */
    private const INTERVIEW_AUGUST = 520;

    /** Termin OHNE Platzbegrenzung und ohne Kohorten-Teilnehmer -> Zeile ohne Belegung. */
    private const INTERVIEW_JULI = 521;

    /** Inaktiver Termin mit einem Teilnehmer -> Grundlage der outside-Fussnote. */
    private const INTERVIEW_INAKTIV = 522;

    private const HEUTE = '2026-08-17 10:00:00';

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

        // Feste Uhr: die Pipeline-Ampel rechnet gegen heute, und ein Test, der an
        // einem Stichtag kippt, ist keiner.
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
        $this->cacheDir = sys_get_temp_dir() . '/statistik-render-' . getmypid() . '-' . uniqid();
        @mkdir($this->cacheDir . '/views', 0777, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->cacheDir . '/views/*') ?: [] as $file) {
            @unlink($file);
        }
        foreach (glob($this->cacheDir . '/*') ?: [] as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
        @rmdir($this->cacheDir . '/views');
        @rmdir($this->cacheDir);
    }

    // -----------------------------------------------------------------
    // Gate: Spaltenzahl je Zeilentyp gegen die colspans der Gruppenkoepfe
    // -----------------------------------------------------------------

    public function test_ausschreibungs_tabelle_haelt_ihre_spaltenzahl_in_jeder_zeile(): void
    {
        $html = $this->render('postings-table', 'Essen');

        // Die Tabelle hat ueberhaupt Zeilen — sonst pruefte alles unten die
        // Leer-Meldung statt der Tabelle.
        $this->assertStringContainsString('Kellner (m/w/d)', $html);
        $this->assertStringNotContainsString('Keine Bewerbungen in dieser Auswahl', $html);

        $counts = $this->columnCounts($html);

        // Die Phasen-Spalten sind wirklich dabei: Trichter = 4 feste Stufen + 3
        // Phasen der Filiale Essen. Ohne diese Zusicherung koennte die
        // Spalten-Invariante auch mit NULL Phasen-Spalten „stimmen".
        $this->assertSame(7, $counts['groups_by_label']['Trichter'], 'vier Stufen + drei Phasen');
        $this->assertSame(3, $counts['groups_by_label']['Abzweige'], 'Standby + Nicht erschienen + Vor Ort aussortiert');
        $this->assertSame(16 + 3, $counts['group_sum'], 'Gruppenköpfe der Ausschreibungs-Tabelle');

        // „Bestätigt" ist raus (Kunden-Entscheidung 27.08.2026): die Spalte zaehlte
        // confirmed/attended/no_show — also auch Nicht-Erschienene ohne jede
        // Reaktion — und wurde als „hat den Reminder bestaetigt" gelesen.
        $this->assertStringNotContainsString('Bestätigt', $html);
        $this->assertStringContainsString('Teilgenommen', $html, 'Gegenprobe: die Nachbarspalte steht');
        $this->assertStringContainsString('Vor Ort aussortiert', $html);

        $this->assertRowsMatchGroups($counts, 'Ausschreibungs-Tabelle');

        // Datenzeilen: zwei Ausschreibungen der Filiale (die geschlossene ist bei
        // Status „online" nicht dabei)
        $this->assertCount(2, $counts['body'], 'eine Zeile je Ausschreibung');
        $this->assertCount(1, $counts['foot'], 'genau eine Gesamt-Zeile');
        $this->assertStringNotContainsString('Alt-Ausschreibung', $html, 'draft ist nicht online');

        // PIPELINE-ZAHLEN, an der festen Uhr nachgerechnet: Bedarf 10 × Faktor 8 =
        // Ziel 80. Laufzeit 01.07.–30.09. sind 91 Tage, am 17.08. davon 47 vergangen.
        // An der Ausschreibung „Kellner" hängen DREI Bewerbungen (601, 603, 605 —
        // der Testbewerber 606 zählt nie mit), hochgerechnet round(3 / 47 * 91) = 6.
        //
        // Ohne diese Zahlen war die Pipeline-Quote die einzige der Seite, die
        // niemand prüfen konnte: der FAKTOR — ihr Nenner — stand nirgends.
        $this->assertStringContainsString('6 von 80', $html, 'hochgerechnete Bewerbungen gegen Ziel');
        $this->assertStringContainsString('Ziel: 10 × 8', $html, 'das Ziel als Bedarf × Faktor');

        // Die Gesamt-Zeile nennt ihre Bezugsgrößen ebenfalls und rechnet ABSOLUT
        // (3 von 80), die Zeile darüber hochgerechnet (6 von 80). Genau deshalb steht
        // beides an der Zahl und nicht nur in der Spalten-Überschrift — 8 % in der
        // Summe neben 8 % in der Zeile ist Zufall, die Rechnungen sind zwei.
        $this->assertStringContainsString('3 von 80', $html, 'Gesamt-Zeile ohne Hochrechnung');
        $this->assertStringContainsString('hochgerechnet', $html, 'die Zeile sagt, welche Rechnung gilt');
        $this->assertStringContainsString(
            'IN EINER ZEILE MIT GEPFLEGTER LAUFZEIT hochgerechnet',
            $html,
            'die Überschrift gilt für alle drei Zeilenarten (mit Laufzeit, ohne Laufzeit, Gesamt)',
        );

        // Freier Nutzertext im Spaltenkopf: der Phasenname mit Apostroph erscheint
        // NUR kodiert — weder im wire:click noch in einem title-Attribut steht er
        // roh. Roh waere er dort ein zerlegter JS-Ausdruck bzw. ein abgeschnittenes
        // Attribut.
        $this->assertStringContainsString('Telefonat &#039;kurz&#039;', $html, 'Name sichtbar, aber kodiert');
        $this->assertStringNotContainsString("Telefonat 'kurz'", $html, 'nirgends roh');
    }

    public function test_pipeline_ohne_gepflegte_laufzeit_nennt_ihre_zahlen(): void
    {
        // DER HAEUFIGERE Zeilenzustand, und bis zum Abschluss-Review von KEINEM Test
        // gerendert: `closes_at` ist optional und publish() setzt nur `published_at`.
        // TargetLight vergleicht dann ABSOLUT und liefert `projected = null` — die
        // Zelle verlangte aber genau diesen Wert und zeigte deshalb eine nackte
        // Prozentzahl ohne jede Bezugsgroesse. Genau diese Zahl hat der Kunde
        // reklamiert.
        Capsule::table('rec_postings')->where('id', self::POSTING_KELLNER)->update(['closes_at' => null]);

        try {
            $html = $this->render('postings-table', 'Essen');

            // 3 Bewerbungen gegen das ganze Ziel 80 = 4 % — ohne Hochrechnung
            $this->assertStringContainsString('3 von 80', $html, 'Bezugsgrößen auch im Absolut-Zweig');
            $this->assertStringContainsString('Ziel: 10 × 8', $html, 'das Ziel bleibt benannt');
            $this->assertStringContainsString('absolut', $html, 'die Zeile sagt, dass NICHT hochgerechnet wird');
            $this->assertStringContainsString(
                'Ohne Hochrechnung, weil an dieser Ausschreibung kein Start oder kein Laufzeitende gepflegt ist',
                $html,
                'und der Tooltip sagt, warum',
            );

            // Die Spalte verspricht hier keine Hochrechnung mehr
            $this->assertStringContainsString('OHNE gepflegten Start oder Laufzeitende', $html);

            // Und die Spaltenzahl bleibt in diesem Zweig ebenfalls heil
            $this->assertRowsMatchGroups($this->columnCounts($html), 'Ausschreibungs-Tabelle ohne Laufzeit');
        } finally {
            Capsule::table('rec_postings')->where('id', self::POSTING_KELLNER)
                ->update(['closes_at' => '2026-09-30 23:59:59']);
        }
    }

    public function test_termin_tabelle_haelt_ihre_spaltenzahl_auch_in_den_unterzeilen(): void
    {
        $html = $this->render('interviews-table', 'Essen');

        $this->assertStringNotContainsString('Keine Schulungstermine in dieser Auswahl', $html);

        $counts = $this->columnCounts($html);

        $this->assertSame(7, $counts['groups_by_label']['Trichter'], 'vier Stufen + drei Phasen');
        $this->assertSame(2, $counts['groups_by_label']['Abzweige'], 'Nicht erschienen + Vor Ort aussortiert');
        $this->assertSame(15 + 3, $counts['group_sum'], 'Gruppenköpfe der Termin-Tabelle');
        $this->assertStringNotContainsString('Bestätigt', $html, 'gleiche Spaltenlogik wie Tabelle 1');

        $this->assertRowsMatchGroups($counts, 'Termin-Tabelle');

        // Vier Zeilen im Rumpf, und die Mischung ist der Punkt dieses Tests:
        //  - August-Termin (Belegung 1 von 8) + seine ZWEI Herkunfts-Unterzeilen
        //    (colspan=3 in der ersten Zelle, Belegung „–")
        //  - Juli-Termin OHNE Kohorten-Teilnehmer und ohne Platzbegrenzung
        // Unterzeilen und Zeilen ohne Belegung sind damit mitgeprueft.
        $this->assertCount(4, $counts['body']);
        $this->assertStringContainsString('Herkunft:', $html, 'die Unterzeilen sind gerendert');
        $this->assertStringContainsString('/&nbsp;∞', $html, 'Termin ohne Platzbegrenzung');
        $this->assertCount(1, $counts['foot']);
    }

    public function test_fussnote_der_termin_tabelle_steht_auch_bei_leerer_tabelle(): void
    {
        // Der Fall, in dem die Erklaerung am noetigsten ist: der Zeitraum trifft
        // KEINEN Termin. Die Teilnehmer stecken trotzdem irgendwo — stuende die
        // Fussnote im @else-Zweig, verschwiege die Seite sie genau hier.
        $html = $this->render('interviews-table', 'Essen', '2027-01-01', '2027-01-31');

        $this->assertStringContainsString('Keine Schulungstermine in dieser Auswahl', $html);
        $this->assertStringContainsString('Nicht in dieser Tabelle', $html);
        $this->assertStringContainsString('In der Ausschreibungs-Tabelle sind sie enthalten', $html);

        // Und die genannten Zahlen sind die echten: zwei Termine mit
        // Kohorten-Teilnehmern liegen ausserhalb der Auswahl (der August-Termin
        // wegen des Zeitraums, der inaktive ohnehin), zusammen drei Bewerbungen —
        // 601 und 602 am August-Termin, 605 am inaktiven. Der Juli-Termin zaehlt
        // NICHT mit: dort sitzt nur ein Testbewerber, also keine Kohorten-Zeile.
        $this->assertMatchesRegularExpression('/3\s+Bewerbungen/', $html);
        $this->assertMatchesRegularExpression('/an\s+2\s+Terminen/', $html);

        // Kein Tabellen-Rumpf, also auch keine Zeile, die eine Spalte verrutschen
        // koennte — geprueft, damit der Test nicht versehentlich die Tabelle rendert.
        $this->assertSame([], $this->columnCounts($html)['body']);
    }

    // -----------------------------------------------------------------
    // Render-Werkzeug
    // -----------------------------------------------------------------

    /**
     * Rendert eines der beiden Tabellen-Partials mit gesetzten Filtern.
     */
    private function render(
        string $partial,
        ?string $ort = null,
        ?string $interviewFrom = null,
        ?string $interviewTo = null,
    ): string {
        $component = new StatisticsRenderProbe();
        $component->ortFilter = $ort;
        $component->interviewFrom = $interviewFrom;
        $component->interviewTo = $interviewTo;

        $viewsRoot = dirname(__DIR__, 2) . '/resources/views';
        $source = (string) file_get_contents($viewsRoot . '/livewire/statistics/' . $partial . '.blade.php');

        // Panel-Rahmen entfernen (Begruendung im Klassen-Kommentar). Beide
        // Ersetzungen werden GEZAEHLT: greift eine nicht, waere das Ergebnis ein
        // stiller Compile-Fehler statt einer Aussage ueber die Tabelle.
        $this->assertSame(1, substr_count($source, '</x-ui-panel>'));
        $source = str_replace('</x-ui-panel>', '', $source);
        $source = preg_replace('/<x-ui-panel\b[^>]*>/', '', $source, 1, $replaced);
        $this->assertSame(1, $replaced, 'genau ein öffnendes Panel-Tag erwartet');

        $probeView = $this->cacheDir . '/views/probe-' . $partial . '.blade.php';
        file_put_contents($probeView, (string) $source);

        $files = new Filesystem();
        $compiler = new BladeCompiler($files, $this->cacheDir);

        $resolver = new EngineResolver();
        $resolver->register('blade', fn () => new BoundCompilerEngine($compiler, $files, $component));

        $finder = new FileViewFinder($files, [$this->cacheDir . '/views']);
        $finder->addNamespace('recruiting', $viewsRoot);

        $factory = new ViewFactory($resolver, $finder, new Dispatcher(new Container()));

        return $factory->make('probe-' . $partial)->render();
    }

    /**
     * Zellenzahlen einer gerenderten Tabelle:
     *   group_sum        Summe der colspans der GRUPPEN-Zeile (erste thead-Zeile)
     *   groups_by_label  Label => colspan derselben Zeile
     *   head             Summe der colspans der zweiten thead-Zeile
     *   body             je tbody-Zeile die Summe ihrer colspans
     *   foot             je tfoot-Zeile die Summe ihrer colspans
     *
     * @return array{group_sum:int, groups_by_label:array<string,int>, head:?int, body:list<int>, foot:list<int>}
     */
    private function columnCounts(string $html): array
    {
        $dom = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        // Die Blades liefern ein Fragment; der Meta-Hinweis haelt DOMDocument von
        // der latin1-Annahme ab (sonst zerfallen die Umlaute und „ⓘ").
        $dom->loadHTML('<meta http-equiv="Content-Type" content="text/html; charset=utf-8">' . $html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $xpath = new \DOMXPath($dom);

        $rowSum = function (\DOMNode $row): int {
            $sum = 0;
            foreach ($row->childNodes as $cell) {
                if (!$cell instanceof \DOMElement) {
                    continue;
                }
                if (!in_array(strtolower($cell->tagName), ['td', 'th'], true)) {
                    continue;
                }
                $span = $cell->getAttribute('colspan');
                $sum += $span === '' ? 1 : (int) $span;
            }

            return $sum;
        };

        $groupRow = $xpath->query('//thead/tr[1]')->item(0);
        $headRow = $xpath->query('//thead/tr[2]')->item(0);

        $groupSum = 0;
        $groupsByLabel = [];
        if ($groupRow instanceof \DOMElement) {
            $groupSum = $rowSum($groupRow);
            foreach ($groupRow->childNodes as $cell) {
                if (!$cell instanceof \DOMElement || strtolower($cell->tagName) !== 'th') {
                    continue;
                }
                $label = trim($cell->textContent);
                $span = $cell->getAttribute('colspan');
                $groupsByLabel[$label] = $span === '' ? 1 : (int) $span;
            }
        }

        $body = [];
        foreach ($xpath->query('//tbody/tr') as $row) {
            $body[] = $rowSum($row);
        }

        $foot = [];
        foreach ($xpath->query('//tfoot/tr') as $row) {
            $foot[] = $rowSum($row);
        }

        return [
            'group_sum' => $groupSum,
            'groups_by_label' => $groupsByLabel,
            'head' => $headRow instanceof \DOMElement ? $rowSum($headRow) : null,
            'body' => $body,
            'foot' => $foot,
        ];
    }

    /**
     * DIE Invariante: jede Zeile hat so viele Zellen (colspan gewichtet), wie die
     * Gruppenzeile Spalten ankuendigt.
     *
     * @param  array{group_sum:int, head:?int, body:list<int>, foot:list<int>}  $counts
     */
    private function assertRowsMatchGroups(array $counts, string $label): void
    {
        $this->assertGreaterThan(0, $counts['group_sum'], "{$label}: keine Gruppenzeile gefunden");
        $this->assertSame($counts['group_sum'], $counts['head'], "{$label}: Kopfzeile");

        foreach ($counts['body'] as $index => $sum) {
            $this->assertSame($counts['group_sum'], $sum, "{$label}: Datenzeile #{$index}");
        }
        $this->assertNotSame([], $counts['body'], "{$label}: keine Datenzeile gerendert");

        foreach ($counts['foot'] as $index => $sum) {
            $this->assertSame($counts['group_sum'], $sum, "{$label}: Summenzeile #{$index}");
        }
        $this->assertNotSame([], $counts['foot'], "{$label}: keine Summenzeile gerendert");
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
            ['id' => 51, 'uuid' => 'rpos-51', 'team_id' => self::TEAM, 'title' => 'Kellner',
             'location' => 'Essen', 'is_active' => 1, 'created_at' => $now, 'updated_at' => $now],
            ['id' => 52, 'uuid' => 'rpos-52', 'team_id' => self::TEAM, 'title' => 'Küche',
             'location' => 'Wuppertal', 'is_active' => 1, 'created_at' => $now, 'updated_at' => $now],
        ]);

        // DREI aktive Phasen in Essen -> drei Phasen-Spalten im Trichter
        Capsule::table('rec_phases')->insert([
            ['id' => 71, 'uuid' => 'rph-71', 'team_id' => self::TEAM, 'rec_position_id' => 51,
             'name' => 'Eingang', 'order' => 1, 'is_active' => 1, 'created_at' => $now, 'updated_at' => $now],
            // APOSTROPH im Phasennamen, und zwar mit Absicht: der Name wandert in
            // den wire:click-Ausdruck der Spalte (Drill-down) und in mehrere
            // title-Attribute. Unescaped zerlegte er den JS-Ausdruck — der
            // Drill-Button dieser Spalte waere in ALLEN Zeilen tot. Phasennamen sind
            // freier Nutzertext, das ist also kein konstruierter Fall.
            ['id' => 72, 'uuid' => 'rph-72', 'team_id' => self::TEAM, 'rec_position_id' => 51,
             'name' => "Telefonat 'kurz'", 'order' => 2, 'is_active' => 1,
             'created_at' => $now, 'updated_at' => $now],
            ['id' => 73, 'uuid' => 'rph-73', 'team_id' => self::TEAM, 'rec_position_id' => 51,
             'name' => 'Schulung', 'order' => 3, 'is_active' => 1, 'created_at' => $now, 'updated_at' => $now],
            ['id' => 74, 'uuid' => 'rph-74', 'team_id' => self::TEAM, 'rec_position_id' => 52,
             'name' => 'Eingang', 'order' => 1, 'is_active' => 1, 'created_at' => $now, 'updated_at' => $now],
        ]);

        Capsule::table('rec_postings')->insert([
            ['id' => self::POSTING_KELLNER, 'uuid' => 'rpost-510', 'team_id' => self::TEAM, 'rec_position_id' => 51,
             'title' => 'Kellner (m/w/d)', 'activity' => 'Service', 'status' => 'published', 'is_active' => 1,
             'published_at' => '2026-07-01 08:00:00', 'closes_at' => '2026-09-30 23:59:59',
             'bedarf' => 10, 'bewerbungs_faktor' => 8.0, 'created_at' => $now, 'updated_at' => $now],
            ['id' => self::POSTING_BANKETT, 'uuid' => 'rpost-511', 'team_id' => self::TEAM, 'rec_position_id' => 51,
             'title' => 'Aushilfe Bankett', 'activity' => 'Bankett', 'status' => 'published', 'is_active' => 1,
             'published_at' => null, 'closes_at' => null,
             'bedarf' => null, 'bewerbungs_faktor' => null, 'created_at' => $now, 'updated_at' => $now],
            // draft = nicht online: gehoert in den Block, nicht in die Tabelle
            ['id' => self::POSTING_ALT, 'uuid' => 'rpost-512', 'team_id' => self::TEAM, 'rec_position_id' => 51,
             'title' => 'Alt-Ausschreibung', 'activity' => 'Service', 'status' => 'draft', 'is_active' => 1,
             'published_at' => null, 'closes_at' => null,
             'bedarf' => null, 'bewerbungs_faktor' => null, 'created_at' => $now, 'updated_at' => $now],
        ]);

        Capsule::table('rec_interview_types')->insert([
            ['id' => 61, 'uuid' => 'rtype-61', 'team_id' => self::TEAM, 'name' => 'Schulung',
             'is_active' => 1, 'created_at' => $now, 'updated_at' => $now],
        ]);

        Capsule::table('rec_interviews')->insert([
            ['id' => self::INTERVIEW_AUGUST, 'uuid' => 'riv-520', 'team_id' => self::TEAM,
             'interview_type_id' => 61, 'rec_position_id' => 51, 'rec_posting_id' => self::POSTING_KELLNER,
             'title' => 'Schulung August', 'location' => 'Bahnhof Duisburg, Gleis 3',
             'starts_at' => '2026-08-10 10:00:00', 'max_participants' => 8,
             'is_active' => 1, 'created_at' => $now, 'updated_at' => $now],
            // OHNE Platzbegrenzung und ohne Kohorten-Teilnehmer: „1 / ∞"
            ['id' => self::INTERVIEW_JULI, 'uuid' => 'riv-521', 'team_id' => self::TEAM,
             'interview_type_id' => 61, 'rec_position_id' => 51, 'rec_posting_id' => null,
             'title' => 'Nachschulung Juli', 'location' => null,
             'starts_at' => '2026-07-05 09:00:00', 'max_participants' => null,
             'is_active' => 1, 'created_at' => $now, 'updated_at' => $now],
            ['id' => self::INTERVIEW_INAKTIV, 'uuid' => 'riv-522', 'team_id' => self::TEAM,
             'interview_type_id' => 61, 'rec_position_id' => 51, 'rec_posting_id' => self::POSTING_KELLNER,
             'title' => 'Testtermin', 'location' => 'Essen, Zentrale',
             'starts_at' => '2026-08-14 10:00:00', 'max_participants' => 6,
             'is_active' => 0, 'created_at' => $now, 'updated_at' => $now],
        ]);

        Capsule::table('rec_applicants')->insert([
            // 601: gebucht + bestaetigt + Vertrag unterschrieben, Phase 3
            ['id' => 601, 'uuid' => 'rapp-601', 'team_id' => self::TEAM, 'applied_at' => '2026-07-01',
             'rec_phase_id' => 73, 'is_test' => 0, 'created_at' => $now, 'updated_at' => $now],
            // 602: Standby (booked + Platz freigegeben), zweite Ausschreibung
            //      -> zweite Herkunfts-Unterzeile am August-Termin
            ['id' => 602, 'uuid' => 'rapp-602', 'team_id' => self::TEAM, 'applied_at' => '2026-07-02',
             'rec_phase_id' => 71, 'is_test' => 0, 'created_at' => $now, 'updated_at' => $now],
            // 603: ohne Termin -> Phasen-Spalten in Tabelle 1
            ['id' => 603, 'uuid' => 'rapp-603', 'team_id' => self::TEAM, 'applied_at' => '2026-07-03',
             'rec_phase_id' => 72, 'is_test' => 0, 'created_at' => $now, 'updated_at' => $now],
            // 604: geschlossene Ausschreibung
            ['id' => 604, 'uuid' => 'rapp-604', 'team_id' => self::TEAM, 'applied_at' => '2026-07-04',
             'rec_phase_id' => 71, 'is_test' => 0, 'created_at' => $now, 'updated_at' => $now],
            // 605: haengt am INAKTIVEN Termin -> outside-Fussnote
            ['id' => 605, 'uuid' => 'rapp-605', 'team_id' => self::TEAM, 'applied_at' => '2026-07-05',
             'rec_phase_id' => 71, 'is_test' => 0, 'created_at' => $now, 'updated_at' => $now],
            // 606: TESTBEWERBER auf dem Juli-Termin — belegt dort einen Platz,
            //      steckt in keiner Kohorte. Genau der Fall, in dem Belegung und
            //      Trichter auseinandergehen.
            ['id' => 606, 'uuid' => 'rapp-606', 'team_id' => self::TEAM, 'applied_at' => '2026-07-06',
             'rec_phase_id' => 71, 'is_test' => 1, 'created_at' => $now, 'updated_at' => $now],
        ]);

        Capsule::table('rec_applicant_posting')->insert([
            ['rec_applicant_id' => 601, 'rec_posting_id' => self::POSTING_KELLNER, 'created_at' => $now, 'updated_at' => $now],
            ['rec_applicant_id' => 602, 'rec_posting_id' => self::POSTING_BANKETT, 'created_at' => $now, 'updated_at' => $now],
            ['rec_applicant_id' => 603, 'rec_posting_id' => self::POSTING_KELLNER, 'created_at' => $now, 'updated_at' => $now],
            ['rec_applicant_id' => 604, 'rec_posting_id' => self::POSTING_ALT, 'created_at' => $now, 'updated_at' => $now],
            ['rec_applicant_id' => 605, 'rec_posting_id' => self::POSTING_KELLNER, 'created_at' => $now, 'updated_at' => $now],
            ['rec_applicant_id' => 606, 'rec_posting_id' => self::POSTING_KELLNER, 'created_at' => $now, 'updated_at' => $now],
        ]);

        Capsule::table('rec_interview_bookings')->insert([
            ['id' => 801, 'uuid' => 'rivb-801', 'team_id' => self::TEAM, 'rec_interview_id' => self::INTERVIEW_AUGUST,
             'rec_applicant_id' => 601, 'status' => 'attended', 'seat_released_at' => null, 'is_active' => 1,
             'created_at' => $now, 'updated_at' => $now],
            ['id' => 802, 'uuid' => 'rivb-802', 'team_id' => self::TEAM, 'rec_interview_id' => self::INTERVIEW_AUGUST,
             'rec_applicant_id' => 602, 'status' => 'booked', 'seat_released_at' => '2026-08-01 09:00:00',
             'is_active' => 1, 'created_at' => $now, 'updated_at' => $now],
            ['id' => 803, 'uuid' => 'rivb-803', 'team_id' => self::TEAM, 'rec_interview_id' => self::INTERVIEW_INAKTIV,
             'rec_applicant_id' => 605, 'status' => 'confirmed', 'seat_released_at' => null, 'is_active' => 1,
             'created_at' => $now, 'updated_at' => $now],
            ['id' => 804, 'uuid' => 'rivb-804', 'team_id' => self::TEAM, 'rec_interview_id' => self::INTERVIEW_JULI,
             'rec_applicant_id' => 606, 'status' => 'confirmed', 'seat_released_at' => null, 'is_active' => 1,
             'created_at' => $now, 'updated_at' => $now],
        ]);

        Capsule::table('rec_contracts')->insert([
            ['uuid' => 'rcon-1', 'team_id' => self::TEAM, 'rec_applicant_id' => 601,
             'rec_contract_template_id' => 1,
             'status' => 'signed', 'sent_at' => '2026-07-20 10:00:00', 'signed_at' => '2026-07-21 10:00:00',
             'created_at' => $now, 'updated_at' => $now],
        ]);
    }
}

/**
 * Probe fuer den Render-Test: reicht die Computed Properties als
 * Methoden-Aufrufe durch.
 *
 * Warum das noetig ist: `$this->cohort` und Geschwister sind #[Computed] und
 * werden von Livewire ueber __get aufgeloest — das braucht den
 * Livewire-Lebenszyklus, den es hier nicht gibt. Gerechnet wird derselbe Code,
 * nur der Weg dorthin ist ein direkter Aufruf.
 *
 * Gemerkt werden die Ergebnisse, weil Livewire das auch tut: ohne Memoisierung
 * feuerte eine Tabelle ihre Queries pro Zelle neu, und der Test saehe eine
 * Query-Last, die es in Produktion nicht gibt.
 */
final class StatisticsRenderProbe extends Index
{
    /** @var array<string,mixed> */
    private array $memo = [];

    public function __get($property)
    {
        if (method_exists($this, $property)) {
            if (!array_key_exists($property, $this->memo)) {
                $this->memo[$property] = $this->{$property}();
            }

            return $this->memo[$property];
        }

        return parent::__get($property);
    }
}

/**
 * Blade-Engine, die die kompilierte View an die Livewire-Komponente bindet —
 * damit `$this->countIn(...)` in einem Partial dasselbe bedeutet wie in
 * Produktion.
 *
 * Ohne diese Bindung zeigt `$this` in einer kompilierten Blade auf das
 * Filesystem-Objekt (PhpEngine include-t ueber files->getRequire), und jeder
 * `$this`-Aufruf der Statistik-Partials waere ein Fehler — der Test koennte die
 * Tabellen dann gar nicht rendern.
 */
final class BoundCompilerEngine extends CompilerEngine
{
    public function __construct(BladeCompiler $compiler, Filesystem $files, private object $component)
    {
        parent::__construct($compiler, $files);
    }

    protected function evaluatePath($path, $data)
    {
        $obLevel = ob_get_level();
        ob_start();

        try {
            $render = \Closure::bind(
                function (string $__probePath, array $__probeData) {
                    extract($__probeData, EXTR_SKIP);
                    include $__probePath;
                },
                $this->component,
                $this->component::class,
            );
            $render($path, $data);
        } catch (\Throwable $e) {
            while (ob_get_level() > $obLevel) {
                ob_end_clean();
            }

            throw $e;
        }

        return ltrim((string) ob_get_clean());
    }
}
