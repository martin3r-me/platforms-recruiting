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
        $this->assertSame(1, substr_count($page, $guard), 'genau ein Filial-Guard');

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

    public function test_ohne_filiale_wird_nichts_gerechnet(): void
    {
        // Nicht nur eine Frage der Anzeige: cohort() laedt ohne Ort-Filter die
        // Bewerber des ganzen Teams. Der Aufforderungs-Zweig darf die Kohorte
        // deshalb nicht anfassen — sonst kostet ein Seitenaufruf ohne Filiale die
        // volle Query-Last fuer eine Meldung.
        $page = $this->page();
        $elsePos = strpos($page, '@else', (int) strpos($page, '@if (!$this->hasOrt())'));

        foreach (['$this->cohort', '$this->tiles', '$this->closedPostingGroups', '$this->countIn('] as $needle) {
            $first = strpos($page, $needle);
            $this->assertNotFalse($first, "{$needle} kommt auf der Seite vor");
            $this->assertGreaterThan($elsePos, $first, "{$needle} erst hinter dem Guard");
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

    public function test_kein_attributwert_bricht_an_einem_anfuehrungszeichen_ab(): void
    {
        // Gefundene Fehlerklasse (Review Task 10): ein Attributwert oeffnet mit dem
        // typografischen „ und schliesst mit dem ASCII-" — damit endet das Attribut
        // MITTEN im Satz, und der Rest des Textes wird vom Parser zu einem Dutzend
        // Muell-Attributen. Betroffen waren genau die Texte, die eine Differenz
        // erklaeren sollen (DOM-Beleg: title=[… „Import] plus 14 weitere
        // Attribute). Im Code sieht man es nicht, im Browser fehlt der halbe Satz.
        //
        // Geprueft wird deshalb ueber ALLE Statistik-Views, nicht nur die Seite:
        // dieselbe Sorte Text steht in beiden Tabellen und in den Partials.
        foreach ($this->statisticsViews() as $path) {
            $src = (string) file_get_contents($path);
            $name = basename($path);

            preg_match_all('/="([^"]*)"/', $src, $matches, PREG_OFFSET_CAPTURE);
            foreach ($matches[1] as [$value, $offset]) {
                $line = substr_count(substr($src, 0, (int) $offset), "\n") + 1;
                $this->assertSame(
                    substr_count($value, '„'),
                    substr_count($value, '“'),
                    "{$name}:{$line}: Attributwert mit unbalanciertem Anführungszeichen — "
                        . 'öffnendes „ ohne schließendes “ (ein ASCII-" beendet das Attribut): '
                        . mb_substr($value, 0, 80),
                );

                // Und derselbe Befund noch einmal so, wie der BROWSER ihn sieht:
                // ein einzelnes Element mit diesem Wert muss GENAU EIN Attribut
                // haben. Im Fehlerfall zerfiel der Rest des Satzes in ein Dutzend
                // Müll-Attribute (gemessen 14, 16 und 17) — die Zusicherung hängt
                // damit nicht an einer Zeichen-Zählung, sondern am Parser.
                $dom = new \DOMDocument();
                $previous = libxml_use_internal_errors(true);
                $dom->loadHTML('<meta http-equiv="Content-Type" content="text/html; charset=utf-8">'
                    . '<span title="' . $value . '">x</span>');
                libxml_clear_errors();
                libxml_use_internal_errors($previous);

                $span = $dom->getElementsByTagName('span')->item(0);
                $this->assertNotNull($span, "{$name}:{$line}: Attributwert zerlegt das Element");
                $this->assertSame(
                    1,
                    $span->attributes->length,
                    "{$name}:{$line}: der Attributwert endet vorzeitig — der Parser sieht "
                        . $span->attributes->length . ' Attribute statt einem: ' . mb_substr($value, 0, 80),
                );
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
