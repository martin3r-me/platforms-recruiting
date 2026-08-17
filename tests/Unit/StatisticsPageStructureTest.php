<?php

namespace Platform\Recruiting\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Der FILIAL-GUARD der Statistik-Seite als Struktur-Zusicherung.
 *
 * Warum als Textprüfung und nicht gerendert: die Seite selbst (index.blade.php)
 * bringt die UI-Komponenten des Fremdpakets und `route()` mit — sie zu rendern
 * hiesse, die Host-App zu booten, und dieses Modul testet bewusst ohne sie (siehe
 * tools/blade-check.php, gleicher Grund). Die beiden TABELLEN werden dagegen echt
 * gerendert (StatisticsTablesRenderTest); was hier fehlt, ist also nur die
 * Reihenfolge der Abschnitte auf der Seite — und genau die ist die Zusicherung,
 * die dieser Test festnagelt.
 *
 * Was daran haengt: `phaseLabels()` liest den Phasensatz der GEFILTERTEN Filiale,
 * und `where('location', null)` macht Laravel zu einem `whereNull`. Ohne gewaehlte
 * Filiale kaemen die Spaltenkoepfe des Trichters aus dem Phasensatz ORTLOSER
 * Stellen, waehrend die Zahlen darunter alle Orte enthalten — Ueberschriften einer
 * Filiale ueber den Zahlen aller. Rutscht ein Tabellen-Include beim naechsten
 * Umbau vor den Guard, ist genau dieser Zustand wieder erreichbar, ohne dass ein
 * Zahlen-Test etwas merkt.
 */
final class StatisticsPageStructureTest extends TestCase
{
    private function page(): string
    {
        $path = dirname(__DIR__, 2) . '/resources/views/livewire/statistics/index.blade.php';
        $this->assertFileExists($path);

        return (string) file_get_contents($path);
    }

    public function test_die_tabellen_stehen_hinter_dem_filial_guard(): void
    {
        $page = $this->page();

        $guard = '@if (!$this->hasOrt())';
        // Das ERSTE Vorkommen ist der Seiten-Guard. Weitere sind erlaubt und
        // erwartet: der Block „Ausgeschieden" formuliert seinen Hinweis anders,
        // wenn keine Filiale gewaehlt ist (dann ist die Auswahl das ganze Team).
        $this->assertGreaterThanOrEqual(1, substr_count($page, $guard), 'Filial-Guard vorhanden');

        $guardPos = strpos($page, $guard);
        $elsePos = strpos($page, '@else', $guardPos);
        $this->assertNotFalse($elsePos, 'der Guard hat einen @else-Zweig (dort stehen die Zahlen)');

        foreach (['postings-table', 'interviews-table'] as $table) {
            $include = "@include('recruiting::livewire.statistics." . $table . "')";
            $this->assertSame(1, substr_count($page, $include), "{$table} genau einmal eingebunden");
            $this->assertGreaterThan(
                $elsePos,
                strpos($page, $include),
                "{$table} steht hinter dem Filial-Guard",
            );
        }
    }

    public function test_die_kacheln_stehen_hinter_dem_guard_die_bloecke_nicht(): void
    {
        // Die KACHELN gehoeren zur Filial-Ansicht (sie zeigen die Auswahl), die
        // BLOECKE nicht: ohne gewaehlte Filiale steckt jede Bewerbung in Block 2
        // oder 3, dort ist die Erklaerung am noetigsten. Frueher stand hier die
        // Zusicherung „ohne Filiale wird nichts gerechnet" — sie war zweifach
        // falsch: cohort() laedt die Bewerber des Teams ohnehin (der Filial-Filter
        // greift danach in PHP), und die Bloecke MUESSEN rechnen.
        //
        // Dass die Bloecke in BEIDEN Zustaenden rendern und die richtigen Zahlen
        // tragen, prueft StatisticsPageRenderTest an der gerenderten Seite —
        // inklusive $this->closedPostingGroups und $this->unreachablePostingGroups.
        $page = $this->page();
        $elsePos = strpos($page, '@else', (int) strpos($page, '@if (!$this->hasOrt())'));
        $this->assertNotFalse($elsePos);

        $tiles = strpos($page, '$this->tiles');
        $this->assertNotFalse($tiles);
        $this->assertGreaterThan($elsePos, $tiles, 'die KPI-Kacheln stehen hinter dem Guard');

        // Beide Ablage-Bloecke lesen ihre eigenen Computed Properties — und zwar
        // dort, wo der Guard sie nicht abschneidet.
        foreach (['$this->closedPostingGroups', '$this->unreachablePostingGroups'] as $needle) {
            $this->assertStringContainsString($needle, $page);
        }
    }

    public function test_die_vier_bloecke_stehen_unter_den_tabellen(): void
    {
        $page = $this->page();

        $afterTables = strpos($page, "@include('recruiting::livewire.statistics.interviews-table')");
        $this->assertNotFalse($afterTables);

        // Reihenfolge: Ausgeschieden, Geschlossene Ausschreibungen, Ohne
        // Filial-Zuordnung, Rekonziliation.
        $ausgeschieden = strpos($page, '>Ausgeschieden<');
        $geschlossen = strpos($page, "'title' => 'Geschlossene Ausschreibungen'");
        $ohneFiliale = strpos($page, "'title' => 'Ohne Filial-Zuordnung'");
        $rekonziliation = strpos($page, 'Rekonziliation verletzt:');

        $this->assertNotFalse($ausgeschieden, 'Block „Ausgeschieden“');
        $this->assertNotFalse($geschlossen, 'Block „Geschlossene Ausschreibungen“');
        $this->assertNotFalse($ohneFiliale, 'Block „Ohne Filial-Zuordnung“');
        $this->assertNotFalse($rekonziliation, 'Block „Rekonziliation“');

        $this->assertGreaterThan($afterTables, $ausgeschieden);
        $this->assertGreaterThan($ausgeschieden, $geschlossen);
        $this->assertGreaterThan($geschlossen, $ohneFiliale);
        $this->assertGreaterThan($ohneFiliale, $rekonziliation);
    }

    public function test_anfuehrungszeichen_in_den_views_sind_paarweise(): void
    {
        // QUELLTEXT-HYGIENE, ausdruecklich NICHT der Waechter gegen abbrechende
        // Attribute — der steht in StatisticsPageRenderTest und prueft am
        // gerenderten DOM.
        //
        // Warum die Trennung: ein Regex ueber den Quelltext schneidet Attributwerte
        // am ersten ASCII-" ab, also VOR dem Schaden. Genau darauf war die fruehere
        // Fassung dieses Tests gebaut — sie sah die Variante mit ZWEI ASCII-Quotes
        // nicht (das title brach im DOM auf 35 statt 155 Zeichen, der Test blieb
        // gruen) und taeuschte damit Sicherheit vor. Was hier bleibt, ist der
        // billige Teil: gemischte Paare („ … ") in Blade-Texten und PHP-Strings
        // finden, bevor sie in ein Attribut wandern.
        foreach ($this->statisticsViews() as $path) {
            $src = (string) file_get_contents($path);
            $name = basename($path);

            $offset = 0;
            while (($open = mb_strpos($src, '„', $offset)) !== false) {
                $rest = mb_substr($src, $open + 1, 400);
                $closeTypo = mb_strpos($rest, '“');
                $closeAscii = mb_strpos($rest, '"');
                $line = substr_count(mb_substr($src, 0, $open), "\n") + 1;

                $mixed = $closeAscii !== false && ($closeTypo === false || $closeAscii < $closeTypo);
                $this->assertFalse(
                    $mixed,
                    "{$name}:{$line}: gemischtes Anführungszeichen-Paar — geöffnet mit „, geschlossen mit \" "
                        . 'statt “. In einem Attributwert endet das Attribut damit mitten im Satz: '
                        . mb_substr($rest, 0, 60),
                );

                $offset = $open + 1;
            }
        }
    }

    /** @return list<string> */
    private function statisticsViews(): array
    {
        $views = glob(dirname(__DIR__, 2) . '/resources/views/livewire/statistics/*.blade.php') ?: [];
        $this->assertNotEmpty($views);

        return $views;
    }

    public function test_der_zeitraum_ist_das_termindatum(): void
    {
        $page = $this->page();

        // Die beiden Eingabefelder gehoeren dem TERMIN-Zeitraum und binden
        // Y-m-d-String-Properties (nie ein datetime-Cast — bekannte Falle dieses
        // Projekts).
        $this->assertStringContainsString('wire:model.live="interviewFrom"', $page);
        $this->assertStringContainsString('wire:model.live="interviewTo"', $page);

        // Der Bewerbungs-Zeitraum ist ENTFALLEN, nicht umbenannt: bliebe hier ein
        // Feld stehen, band es an eine Property, die es nicht mehr gibt.
        $this->assertStringNotContainsString('filterFrom', $page);
        $this->assertStringNotContainsString('filterTo', $page);
    }

    public function test_der_ort_ist_pflicht_und_kennt_kein_alle(): void
    {
        $page = $this->page();

        // Kein nullable am Filial-Select — „alle Orte" gibt es nicht mehr
        // (Kunden-Entscheidung), und ein nullLabel waere der Weg zurueck.
        $selectStart = strpos($page, 'name="ortFilter"');
        $this->assertNotFalse($selectStart);
        $selectEnd = strpos($page, '/>', $selectStart);
        $this->assertNotFalse($selectEnd);

        $select = substr($page, $selectStart, $selectEnd - $selectStart);
        $this->assertStringContainsString(':required="true"', $select);
        // Kein nullLabel und kein nullable=true: „alle Orte" gibt es nicht mehr.
        // Ein ausdrueckliches :nullable="false" braucht es nicht — false ist der
        // Standard der Komponente.
        $this->assertStringNotContainsString('nullLabel', $select);
        $this->assertStringNotContainsString(':nullable="true"', $select);
    }
}
