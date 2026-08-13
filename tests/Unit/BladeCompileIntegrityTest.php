<?php

namespace Platform\Recruiting\Tests\Unit;

use Illuminate\Filesystem\Filesystem;
use Illuminate\View\Compilers\BladeCompiler;
use PHPUnit\Framework\TestCase;

/**
 * Ueberlebt der Inhalt einer Blade-Datei die ECHTE Kompilierung?
 *
 * Entstanden am 13.08.2026: Ein Blade-Kommentar im Einstellungs-Modal enthielt
 * das Direktiven-Wort fuer PHP-Bloecke als Text ("...verschluckt den naechsten
 * ...-Block..."). Blades storePhpBlocks() laeuft VOR dem Kommentar-Stripper,
 * kennt keine Wortgrenze und paarte das Wort im Kommentar mit dem Ende eines
 * echten Blocks 120 Zeilen weiter unten. Weil das Kommentar-Ende mit im
 * Fressbereich lag, entfernte der Kommentar-Stripper anschliessend auch den
 * Raw-Block-Platzhalter: der halbe General-Tab verschwand spurlos aus dem
 * Kompilat — gueltiges PHP, kein Fehler, nur fehlende Ausgabe. Auf der
 * Bewerberliste kollabierten die dadurch unbalancierten Divs die Flex-Kette,
 * und 1544 serverseitig gerenderte Bewerber waren unsichtbar.
 *
 * Kein statischer Check hat das gesehen (blade-check, Tag-Balance,
 * Direktiven-Paarung): sie alle pruefen die QUELLE. Der Riss entsteht erst im
 * Kompilat — also wird hier das Kompilat geprueft. Die Komponenten-
 * Kompilierung ist ueberbrueckt (braucht den Container, ist am Fehlerbild
 * unbeteiligt); alles davor — Raw-Block-Extraktion, Kommentar-Stripping,
 * Direktiven — laeuft echt.
 */
class BladeCompileIntegrityTest extends TestCase
{
    /**
     * Kein Blade-Kommentar im Modul darf die Woerter fuer Block-Direktiven
     * enthalten — als Text reichen sie, um den Raw-Block-Regex scharfzumachen.
     * Die Tokens sind hier zusammengesetzt, damit dieser Test nicht selbst
     * zur Mine wird, falls ihn jemand in eine Blade-Datei zitiert.
     */
    public function testKeinBladeKommentarEnthaeltBlockDirektivenWoerter(): void
    {
        $tokens = array_map(
            fn (string $w) => '@' . $w,
            ['php', 'endphp', 'verbatim', 'endverbatim']
        );

        $funde = [];
        foreach ($this->alleBladeDateien() as $datei) {
            $quelle = (string) file_get_contents($datei);
            preg_match_all('/\{\{--(.*?)--\}\}/s', $quelle, $kommentare, PREG_OFFSET_CAPTURE);
            foreach ($kommentare[1] as [$text, $offset]) {
                foreach ($tokens as $token) {
                    if (str_contains($text, $token)) {
                        $zeile = substr_count($quelle, "\n", 0, $offset) + 1;
                        $funde[] = $this->relativ($datei) . ":{$zeile} enthaelt {$token}";
                    }
                }
            }
        }

        $this->assertSame(
            [],
            $funde,
            "Blade-Kommentare mit Block-Direktiven-Woertern (loeschen Abschnitte spurlos aus dem Kompilat):\n  "
                . implode("\n  ", $funde)
        );
    }

    /**
     * Der konkrete Unfall, nachkompiliert: die Sektionen des Einstellungs-
     * Modals muessen das echte Kompilat erreichen. Am 13.08. fielen genau
     * diese Anker heraus, waehrend die Quelle alle statischen Pruefungen
     * bestand.
     */
    public function testDasEinstellungsModalUeberlebtDieKompilierung(): void
    {
        $kompilat = $this->kompiliere(
            dirname(__DIR__, 2) . '/resources/views/livewire/applicant/applicant-settings-modal.blade.php'
        );

        foreach ([
            'Schulungszertifikate ausstellen',
            'training_certificate_wa_template_id',
            'Standard-Ansprechpartner',
            'auto_assign_owner',
            'Mindestlohn',
            'Noch keine Service Hours',
            'auto_pilot_wa_initial_template_id',
        ] as $anker) {
            $this->assertStringContainsString(
                $anker,
                $kompilat,
                "'{$anker}' erreicht das Kompilat nicht — ein Abschnitt wird bei der Kompilierung verschluckt"
            );
        }
    }

    /**
     * Dasselbe als Fluchtwegsicherung fuer ALLE Blades des Moduls, ueber eine
     * Invariante statt handgepflegter Anker: jedes wire:model der Quelle muss
     * auch im Kompilat stehen. wire:model-Attribute ueberleben die
     * Kompilierung woertlich (auf HTML-Elementen wie auf x-Komponenten, deren
     * Tags dieser Test unangetastet laesst) — fehlt eines, wurde sein
     * Abschnitt verschluckt.
     */
    public function testJedesWireModelDerQuelleErreichtDasKompilat(): void
    {
        $risse = [];
        foreach ($this->alleBladeDateien() as $datei) {
            $quelle = (string) file_get_contents($datei);
            // Kommentare vorab entfernen: ein wire:model IM Kommentar soll
            // (und wird) das Kompilat nicht erreichen.
            $quelle = preg_replace('/\{\{--(.*?)--\}\}/s', '', $quelle);
            // Nur statische Bindungen: enthaelt der Attributwert ein Blade-Echo
            // ({{ $index }} o.ae.), wird er kompiliert und steht woertlich nie
            // im Kompilat — solche Bindungen kann die Invariante nicht tragen.
            preg_match_all('/wire:model[\w.]*\s*=\s*"[^"{]+"/', $quelle, $bindungen);
            if ($bindungen[0] === []) {
                continue;
            }

            $kompilat = $this->kompiliere($datei);
            foreach (array_unique($bindungen[0]) as $bindung) {
                if (!str_contains($kompilat, $bindung)) {
                    $risse[] = $this->relativ($datei) . " verliert {$bindung}";
                }
            }
        }

        $this->assertSame(
            [],
            $risse,
            "Bindungen, die die Kompilierung nicht ueberleben (verschluckter Abschnitt):\n  "
                . implode("\n  ", $risse)
        );
    }

    /**
     * Echte Kompilierung, nur die Komponenten-Tag-Stufe ist neutralisiert:
     * sie braucht den Laravel-Container (Klassen-Aufloesung der x-Tags) und
     * liegt HINTER den Stufen, um die es hier geht.
     */
    private function kompiliere(string $datei): string
    {
        $compiler = new class(new Filesystem(), sys_get_temp_dir()) extends BladeCompiler {
            protected function compileComponentTags($value)
            {
                return $value;
            }
        };

        return $compiler->compileString((string) file_get_contents($datei));
    }

    /** @return list<string> */
    private function alleBladeDateien(): array
    {
        $wurzel = dirname(__DIR__, 2) . '/resources/views';
        $dateien = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($wurzel));
        foreach ($iterator as $eintrag) {
            if ($eintrag->isFile() && str_ends_with($eintrag->getFilename(), '.blade.php')) {
                $dateien[] = $eintrag->getPathname();
            }
        }
        sort($dateien);

        $this->assertNotEmpty($dateien, "Keine Blade-Dateien unter {$wurzel} gefunden");

        return $dateien;
    }

    private function relativ(string $datei): string
    {
        return ltrim(str_replace(dirname(__DIR__, 2), '', $datei), '/');
    }
}
