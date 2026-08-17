<?php

namespace Platform\Recruiting\Tests\Integration;

use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Support\DomPdfFontDir;
use Platform\Recruiting\Support\FontGlyphCoverage;
use Platform\Recruiting\Support\ResttagePlaceholder;
use Platform\Recruiting\Support\TrainingCertificateAssets;
use Platform\Recruiting\Support\TrainingCertificateContent;
use Platform\Recruiting\Support\TrainingCertificateHtml;
use Platform\Recruiting\Support\TrainingCertificatePdfOptions;

/**
 * Erstnachweis, dass das Zertifikat-PDF stimmt.
 *
 * ERSTNACHWEIS, KEINE ABSICHERUNG: der Prototyp, an dem das Layout entstanden
 * ist, ist kein Code. Bis diese Klasse gruen ist, ist NICHT belegt, dass die
 * eine Seite und die eingebettete Schrift auch aus dem gebauten Pfad
 * herauskommen. Schlaegt hier etwas fehl, ist das ein Befund ueber den Pfad —
 * die Erwartung wird nicht angepasst, sondern die Ursache gesucht (meist
 * chroot, Asset-Pfad oder ein Abstand im Fliessteil).
 *
 * DER INHALT KOMMT AUS TrainingCertificateContent, nicht aus einer Kopie in
 * dieser Datei. Eine Kopie waere der teuerste Fehler, den dieser Test machen
 * kann: er bewachte dann einen Text, der nicht ausgestellt wird, und Zertifikat
 * und Nachweis drifteten auseinander, ohne dass etwas rot wird.
 *
 * DIESELBEN OPTIONEN WIE DER CONTROLLER (TrainingCertificatePdfOptions). Mit
 * selbst gesetzten Optionen pruefte der Test eine anders konfigurierte Engine
 * als die ausgelieferte und waere gruen ohne Aussage. Die EINZIGE Abweichung
 * sind fontDir/fontCache (siehe render()) — beides betrifft nur, wohin DomPDF
 * seine Font-Metriken schreibt, nicht wie es rendert.
 *
 * UND GENAU DARIN LIEGT DIE BLINDSTELLE DIESER KLASSE, benannt statt behauptet:
 * weil render() fontDir/fontCache auf einen eigenen Temp-Ordner umbiegt und
 * diesen vorher anlegt (prepareFontCacheDir()), kann dieser Test den
 * Fehlerfall der AUSLIEFERUNG prinzipiell nicht finden — dort kommt der Pfad
 * aus config('dompdf.options.font_dir') und zeigte auf ein Verzeichnis, das
 * nicht existiert (storage/fonts), was einen TypeError mitten in render()
 * ergibt: 500 auf 100 % der Aufrufe. Der Test war dagegen gruen. Wer ihn also
 * fuer den vollstaendigen Nachweis dieses Wegs haelt, irrt in genau dieser
 * Richtung. Die Absicherung liegt an zwei anderen Stellen: die Zusicherung des
 * Verzeichnisses in DomPdfFontDir (mit zwei roten Faellen in
 * DomPdfFontDirTest) und ihr Aufruf im Controller. Diese Blindstelle bleibt
 * bestehen, und sie ist der Preis fuer die Isolation — sie hier zu schliessen
 * hiesse, in den geteilten vendor-Fontordner der Host-App zu rendern.
 *
 * Pdf::loadHTML() (die Facade, die der Controller benutzt) ist hier nicht
 * aufrufbar: sie braucht den App-Container, den diese Suite nicht bootet
 * (tests/bootstrap.php ist ein reiner Autoloader). Deshalb Dompbf\Dompdf direkt
 * — und deshalb ist die gemeinsame Options-Quelle load-bearing.
 *
 * ASSERTIONS AUSSCHLIESSLICH PER PCRE MIT \s*, und die Begruendung dafuer stand
 * hier zwei Commits lang FALSCH. Behauptet war "grep findet die Marker nicht,
 * weil sie ueber Zeilenumbrueche verteilt sind". Sie sind es nicht. Am
 * gerenderten, einseitigen Zertifikat (315786 Byte) gemessen:
 *
 *   /usr/bin/grep -ao "/Type /Pages\?" zert.pdf | sort | uniq -c
 *      1 /Type /Page          <- die eine Seite
 *      1 /Type /Pages         <- der Seitenbaum
 *   /usr/bin/grep -c  "/Type /Page" zert.pdf   -> 2   (rc=0)
 *   /usr/bin/grep -c  "/BaseFont"   zert.pdf   -> 4   (rc=0), bei ZWEI Schriften
 *
 * Die echten Gruende, warum Literalzaehlen hier nichts belegt, sind andere drei:
 *
 *  1. PRAEFIX. "/Type /Page" ist Praefix von "/Type /Pages". Ein Literalzaehler
 *     liefert auf dem einseitigen Dokument 2 statt 1 — er zaehlt den Seitenbaum
 *     mit. Deshalb ist das [^s] in pageCount() load-bearing, siehe dort.
 *  2. ZEILEN, NICHT TREFFER. `grep -c` zaehlt Zeilen mit Treffer. Auf dieser
 *     Datei stimmen beide Zahlen zufaellig ueberein (2 Marker auf 2 Zeilen,
 *     4 /BaseFont auf 4 Zeilen) — die Zahl sieht also richtig aus, ohne dass sie
 *     die gefragte Groesse messen wuerde. Und 4 /BaseFont sind zwei Schriften,
 *     je einmal im Font- und im CID-Objekt: auch ohne -c waere die Trefferzahl
 *     nicht die Zahl der Schriften (baseFonts() macht deshalb array_unique).
 *  3. BINAERBEHANDLUNG, und sie ist werkzeugabhaengig. Das erste NUL-Byte liegt
 *     bei Offset 469. BSD grep 2.6.0 zaehlt trotzdem (2 bzw. 4, rc=0); das
 *     `grep` im Shell-Wrapper dieser Werkzeugkette ist ugrep 7.5.0 mit -I und
 *     ueberspringt Binaerdateien ganz — keine Ausgabe, rc=1. Ein Nachweis, der
 *     je nach grep auf PATH 2, 4 oder gar nichts liefert, ist kein Nachweis.
 *
 * Wer trotzdem literal zaehlen will, braucht also -a, ein Muster mit
 * Wortgrenze und einen Zaehler fuer Treffer statt Zeilen — oder eben
 * preg_match_all mit \s*, wie hier.
 */
class TrainingCertificateRenderTest extends TestCase
{
    private const MODULE_ROOT = __DIR__ . '/../..';

    /**
     * Prozessweite Hygiene, ehrlich eingeordnet: diese Klasse bootet KEIN
     * Eloquent-Model und loest KEINE Facade auf (nur laravel-freie
     * Support-Klassen und Dompdf), die beiden Aufrufe tragen heute also nichts.
     * Sie stehen als Vorsorge, weil beide Zustaende prozessweit statisch sind
     * und der Schaden NUR im Gesamtlauf auftritt, nie im gefilterten: wer hier
     * spaeter einen Model- oder Facade-Zugriff ergaenzt, hat die Hygiene schon
     * an der richtigen Stelle. Muster und Messwerte:
     * PlaceholderResolutionPinTest und ContractPdfRegressionTest.
     */
    public static function tearDownAfterClass(): void
    {
        Model::clearBootedModels();
        Facade::clearResolvedInstances();
        self::removeFontCache();
    }

    // -----------------------------------------------------------------
    // Rendern
    // -----------------------------------------------------------------

    /** Derselbe Resolver, den Controller und Editor-Vorschau benutzen. */
    private function assets(): array
    {
        return TrainingCertificateAssets::resolve(
            (string) realpath(self::MODULE_ROOT . '/resources')
        );
    }

    private function fontPath(): string
    {
        return $this->assets()['font'];
    }

    /**
     * Der Inhalt, wie ihn die Ausstellung anlegt — aus der einen Quelle.
     *
     * Keine Kopie des Textes hier, auch nicht "nur fuer den Test": der Test
     * soll ueber das ausgestellte Dokument etwas aussagen, nicht ueber einen
     * aehnlichen String.
     */
    private function inhalt(
        string $vorname = 'Erika',
        string $nachname = 'Mustermann'
    ): string {
        return TrainingCertificateContent::render([
            'kontakt_vorname' => $vorname,
            'kontakt_nachname' => $nachname,
            'schulung_datum' => '24.07.2026',
            'datum_heute' => '05.08.2026',
        ]);
    }

    /**
     * Denselben Inhalt mit einer Kenntnisliste von $anzahl Zeilen.
     *
     * Die Zeilen werden aus den ECHTEN .skill-Divs des Inhalts vervielfacht,
     * nicht neu getippt: die Zeile behaelt damit Klassen, Sterne und Laenge des
     * Originals, und der Test misst die Umbruchgrenze des ausgelieferten
     * Layouts statt die eines selbst erfundenen.
     */
    private function inhaltMitKenntnisZeilen(int $anzahl): string
    {
        $content = $this->inhalt();

        if (!preg_match_all('#<div class="skill">.*?</div>#s', $content, $m)) {
            self::fail(
                'Keine .skill-Zeile im Inhalt gefunden — die Kenntnisliste in '
                . TrainingCertificateContent::class . ' benutzt offenbar ein anderes '
                . 'Markup. Dieser Helfer muss dann mitgezogen werden; ohne .skill-Zeilen '
                . 'wuerde er lautlos den unveraenderten Inhalt liefern und die '
                . 'Umbruchgrenze gar nicht mehr pruefen.'
            );
        }

        $zeilen = $m[0];
        $neu = '';
        for ($i = 0; $i < $anzahl; $i++) {
            $neu .= $zeilen[$i % count($zeilen)] . "\n";
        }

        $erste = (int) strpos($content, $zeilen[0]);
        $letzteZeile = $zeilen[count($zeilen) - 1];
        $ende = (int) strrpos($content, $letzteZeile) + strlen($letzteZeile);

        return substr($content, 0, $erste) . $neu . substr($content, $ende);
    }

    private function render(string $content): string
    {
        $assets = $this->assets();
        $html = TrainingCertificateHtml::build($content, $assets);

        $options = new Options();
        foreach (TrainingCertificatePdfOptions::for($assets['font'], (string) realpath(self::MODULE_ROOT)) as $k => $v) {
            $options->set($k, $v);
        }

        // Eigener Font-Ordner pro Lauf, damit der Test nicht in den geteilten
        // vendor-Fontordner der Host-App schreibt und sein Ergebnis nicht vom
        // dortigen, veraenderlichen Zustand abhaengt. Gemessen: DejaVu Sans wird
        // trotzdem gefunden und eingebettet — die gebuendelten Fonts sucht
        // DomPDF ueber sein rootDir, nicht ueber fontDir.
        // Aufgeraeumt wird in tearDownAfterClass(), siehe removeFontCache().
        $fontCache = $this->prepareFontCacheDir();
        $options->set('fontDir', $fontCache);
        $options->set('fontCache', $fontCache);

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        return $dompdf->output();
    }

    // -----------------------------------------------------------------
    // PDF lesen
    // -----------------------------------------------------------------

    /**
     * Die Zahl der Seiten-Objekte im PDF.
     *
     * [^s] IST DER LOAD-BEARING TEIL DIESES MUSTERS, und ohne diesen Absatz
     * sieht es wie ein Tippfehler aus: "/Type /Page" ist Praefix von
     * "/Type /Pages", dem Seitenbaum, den DomPDF genau einmal schreibt. Das
     * [^s] schliesst den Baum aus, indem es ein Zeichen VERLANGT, das kein "s"
     * ist (auf ein Page-Objekt folgt dort ein Zeilenumbruch oder "/"). Gemessen
     * am gebauten Pfad:
     *
     *                                        1 Seite   2 Seiten
     *   /\/Type\s*\/Page[^s]/  (dieses)          1         2
     *   /\/Type\s*\/Page/      (ohne [^s])       2         3
     *   /\/Type\s*\/Pages/     (nur der Baum)    1         1
     *
     * Ohne [^s] ist jede Zahl um genau 1 zu hoch — und die Folge waere nicht
     * bloss "ein Test rot": testNormalfallIstEineSeiteMitEingebetteterSchrift
     * waere rot aus dem falschen Grund (2 statt 1, obwohl das Dokument stimmt),
     * und testZwoelfKenntnisZeilenErzeugenEineZweiteSeite waere gruen aus dem
     * falschen Grund (assertGreaterThan(1) ist auch bei EINER Seite erfuellt) —
     * also genau die Negativkontrolle, die den Seitenzaehler falsifizieren soll,
     * verliert ihre Aussage.
     */
    private function pageCount(string $pdf): int
    {
        preg_match_all('/\/Type\s*\/Page[^s]/', $pdf, $m);

        return count($m[0]);
    }

    /** @return list<string> Fontnamen ohne den Subset-Praefix ("SUBAAB+"). */
    private function baseFonts(string $pdf): array
    {
        preg_match_all('/\/BaseFont\s*\/([A-Za-z0-9+\-]+)/', $pdf, $m);

        $ohnePraefix = array_map(
            fn (string $f) => (string) preg_replace('/^[A-Z]+\+/', '', $f),
            $m[1]
        );

        return array_values(array_unique($ohnePraefix));
    }

    /**
     * Die entpackten Content-Streams der Seiten.
     *
     * Aufgeloest ueber /Contents des Page-Objekts und BEWUSST NICHT ueber "jeder
     * Stream, der sich entpacken laesst": die drei Bilder liegen ebenfalls als
     * FlateDecode-Stream im PDF, und in deren entpackten Pixelbytes kann ein
     * Operator-Muster zufaellig vorkommen. Der Weg ueber die Objektnummer trifft
     * genau die Streams, die DomPDF gezeichnet hat.
     *
     * @return list<string>
     */
    private function contentStreams(string $pdf): array
    {
        // Der (?!endobj)-Guard verhindert, dass die Suche nach /Contents ueber
        // die Objektgrenze hinaus in ein FOLGENDES Objekt laeuft, falls ein
        // Page-Objekt einmal keins hat. Ohne ihn holte der Test die Streams
        // einer anderen Seite und meldete das nicht.
        preg_match_all(
            '/\/Type\s*\/Page[^s](?:(?!endobj).){0,600}?\/Contents\s+(\d+)\s+0\s+R/s',
            $pdf,
            $seiten
        );

        if ($seiten[1] === []) {
            self::fail(
                'Kein /Contents-Verweis an einem Page-Objekt gefunden. Ohne '
                . 'Content-Stream kann dieser Test nichts ueber Geometrie oder '
                . 'Textlage aussagen — er bricht hier ab statt eine leere Liste '
                . 'zu liefern, aus der jede Zaehlung 0 waere und jede '
                . 'Suchassertion stumm gruen bliebe.'
            );
        }

        $streams = [];
        foreach ($seiten[1] as $nummer) {
            $streams[] = $this->objektStream($pdf, (int) $nummer);
        }

        return $streams;
    }

    /** Der entpackte Inhalt des Streams von Objekt $nummer. */
    private function objektStream(string $pdf, int $nummer): string
    {
        // Zeilenanfang als Anker: DomPDF schreibt jedes Objekt auf eine eigene
        // Zeile. Trifft das Muster MEHRFACH, liegt derselbe Objektkopf auch in
        // Binaerdaten — dann wird abgebrochen statt geraten, welcher gemeint ist.
        $treffer = preg_match_all(
            '/(?:^|[\r\n])' . $nummer . '\s+0\s+obj\s*<<(?:(?!>>).)*>>\s*stream\r?\n(.*?)\r?\nendstream/s',
            $pdf,
            $m,
            PREG_SET_ORDER
        );

        if ($treffer !== 1) {
            self::fail(sprintf(
                'Objekt %d 0 obj mit Stream: %d Treffer, erwartet genau 1. '
                . 'Bei 0 hat DomPDF das Objekt anders geschrieben, bei mehr als 1 '
                . 'ist der Objektkopf auch in Binaerdaten aufgetaucht. Beides muss '
                . 'auffallen, weil der Test sonst einen fremden Stream ausliest.',
                $nummer,
                $treffer
            ));
        }

        $roh = $m[0][1];
        $entpackt = @gzuncompress($roh);

        // Ohne zlib-Kompression liefert DomPDF den Stream im Klartext. Beides ist
        // gueltig, deshalb hier kein fail(), sondern der Rohwert.
        return $entpackt === false ? $roh : $entpackt;
    }

    /**
     * Die im PDF PLATZIERTEN Bilder mit ihrer tatsaechlichen Groesse.
     *
     * Gelesen wird die cm-Matrix vor dem Do-Operator, nicht /Width des XObjects:
     * /Width ist die Pixelbreite der Quelldatei und aendert sich nicht, wenn das
     * CSS das Bild auf ein Zehnfaches skaliert. Gemessene Rohzeile:
     *
     *   q
     *   113.386 0 0 59.677 240.947 739.694 cm /I2 Do
     *   Q
     *
     * a=113.386 pt = 40 mm ist die Breite, d=59.677 pt die Hoehe, y=739.694 pt
     * die UNTERkante (PDF-Koordinaten, y waechst nach oben).
     *
     * @return list<array{breiteMm: float, hoeheMm: float, unterkantePt: float, oberkantePt: float}>
     */
    private function platzierteBilder(string $pdf): array
    {
        $bilder = [];

        foreach ($this->contentStreams($pdf) as $stream) {
            preg_match_all(
                '/(-?[\d.]+)\s+(-?[\d.]+)\s+(-?[\d.]+)\s+(-?[\d.]+)\s+(-?[\d.]+)\s+(-?[\d.]+)\s+cm\s*\/\w+\s+Do\b/',
                $stream,
                $m,
                PREG_SET_ORDER
            );

            foreach ($m as $t) {
                [$a, $b, $c, $d, , $y] = array_map('floatval', array_slice($t, 1));

                if ($b !== 0.0 || $c !== 0.0) {
                    self::fail(sprintf(
                        'Bildmatrix ist gedreht oder geschert (b=%.3f c=%.3f). Dann ist '
                        . 'a NICHT mehr die Breite, und jede Breitenassertion darauf waere '
                        . 'falsch — auch wenn sie gruen ist. Die Matrix muss hier '
                        . 'ausgewertet werden, bevor sie als Breite gilt.',
                        $b,
                        $c
                    ));
                }

                $bilder[] = [
                    'breiteMm' => $a * 25.4 / 72,
                    'hoeheMm' => $d * 25.4 / 72,
                    'unterkantePt' => $y,
                    'oberkantePt' => $y + $d,
                ];
            }
        }

        return $bilder;
    }

    /** @return list<float> Breiten der platzierten Bilder in mm. */
    private function bildBreitenInMm(string $pdf): array
    {
        return array_column($this->platzierteBilder($pdf), 'breiteMm');
    }

    /**
     * Die gesetzten Textzeilen mit Lage und WIRKSAMEN Schrifteigenschaften.
     *
     * Gemessene Rohzeilen (entpackter Content-Stream):
     *
     *   BT 258.513 640.650 Td 1.875 Tc /F1 11.0 Tf  [( H E R R   /   F R A U)] TJ 0.000 Tc ET
     *   BT 267.625 155.550 Td 1.125 Tc /F1 11.5 Tf  [( E r s t e   Z e i l e)] TJ 0.000 Tc ET
     *
     * Der Tc-Operator (Zeichenabstand) ist OPTIONAL: bei letter-spacing 0 laesst
     * DomPDF ihn ganz weg. Gemessen mit leergeraeumter p-Regel:
     *
     *   BT 272.776 151.271 Td /F1 12.0 Tf  [( E r s t e   Z e i l e)] TJ ET
     *
     * Deshalb ist die Tc-Gruppe im Muster optional und ein fehlender Tc wird als
     * 0.0 gefuehrt. Ein Muster, das Tc VERLANGT, faende genau die Zeilen nicht,
     * die den Fehlerfall zeigen — die Suche liefe leer und der Test blieb gruen.
     *
     * @return list<array{yPt: float, groessePt: float, zeichenabstandPt: float}>
     */
    private function textZeilen(string $pdf): array
    {
        $zeilen = [];

        foreach ($this->contentStreams($pdf) as $stream) {
            preg_match_all(
                '/BT\s+(-?[\d.]+)\s+(-?[\d.]+)\s+Td\s+(?:(-?[\d.]+)\s+Tc\s+)?\/\w+\s+([\d.]+)\s+Tf/',
                $stream,
                $m,
                PREG_SET_ORDER
            );

            foreach ($m as $t) {
                $zeilen[] = [
                    'yPt' => (float) $t[2],
                    'groessePt' => (float) $t[4],
                    'zeichenabstandPt' => ($t[3] ?? '') === '' ? 0.0 : (float) $t[3],
                ];
            }
        }

        return $zeilen;
    }

    /** Oberkante des hoechsten platzierten Bildes, in PDF-Punkten. */
    private function obersteBildY(string $pdf): float
    {
        $bilder = $this->platzierteBilder($pdf);

        if ($bilder === []) {
            self::fail(
                'Kein platziertes Bild im PDF. Die Reihenfolge-Assertion braucht '
                . 'mindestens eins; ohne Bild waere ihr Vergleichswert erfunden.'
            );
        }

        return max(array_column($bilder, 'oberkantePt'));
    }

    /** Y-Lage der obersten Textzeile, in PDF-Punkten (oben = groesserer Wert). */
    private function obersteTextZeileY(string $pdf): float
    {
        $zeilen = $this->textZeilen($pdf);

        if ($zeilen === []) {
            self::fail(
                'Keine Textzeile im PDF gefunden. Das ist selbst der Befund: das '
                . 'Zertifikat ohne Text ist kaputt, und eine leere Liste darf hier '
                . 'nicht als "nichts zu pruefen" durchgehen.'
            );
        }

        return max(array_column($zeilen, 'yPt'));
    }

    // -----------------------------------------------------------------
    // Kriterium 0: die Assets liegen im Repo
    // -----------------------------------------------------------------

    public function testAlleAssetsSindImRepoVorhanden(): void
    {
        // Faellt hier etwas auf, fehlt es auch bei der Ausstellung — derselbe
        // Resolver.
        $this->assertSame([], $this->assets()['missing']);
    }

    // -----------------------------------------------------------------
    // Kriterium 1: genau eine Seite. Kriterium 2: Schrift eingebettet.
    // -----------------------------------------------------------------

    public function testNormalfallIstEineSeiteMitEingebetteterSchrift(): void
    {
        $pdf = $this->render($this->inhalt());

        $this->assertSame(1, $this->pageCount($pdf), 'Das Zertifikat ist ein einseitiges Dokument.');

        $fonts = $this->baseFonts($pdf);
        sort($fonts);

        // Eingefrorene Fontliste statt nur assertContains: DejaVuSans traegt die
        // Sterne, Oswald-SemiBold alles andere. Taucht hier "Helvetica" auf oder
        // fehlt Oswald, hat DomPDF das @font-face STUMM verworfen (falscher
        // chroot, Font-Pfad ausserhalb, oder eine font-weight-Deklaration im
        // @font-face, die der body nicht anfordert) und das PDF sieht falsch aus,
        // ohne dass irgendwo eine Meldung entstanden waere. Diese Assertion ist
        // der EINZIGE Waechter des Pakets, der jede Stufe einer beschaedigten
        // Schriftdatei rot macht — FontGlyphCoverage kann das nicht (siehe
        // testKeineFehlendenGlyphenImInhalt).
        $this->assertSame(
            ['DejaVuSans', 'Oswald-SemiBold'],
            $fonts,
            'Das PDF muss Oswald-SemiBold (Grundschrift) und DejaVuSans (Sterne) einbetten.'
        );
    }

    public function testWorstCaseBleibtEineSeite(): void
    {
        // Langer Doppelname und zwei Schulungsleiter. Die Kursbezeichnung ist seit
        // Zuschnitt v3 KEINE Dimension mehr: sie steht als Literal in
        // TrainingCertificateContent::COURSE und kann pro Ausstellung nicht
        // variieren.
        $pdf = $this->render($this->inhalt(
            'Maximiliane Charlotte',
            'von Hohenberg-Lichtenstein',
            'Michel Zimmer, Anna Bergmann'
        ));

        $this->assertSame(1, $this->pageCount($pdf));
    }

    /**
     * Die Listenlaenge ist die Dimension, die tatsaechlich umbricht — nicht die
     * Namenslaenge. Dieser Test nagelt die obere Grenze fest, die noch traegt.
     * Er belegt zugleich, dass die Fuss-Verankerung die Einzelseitigkeit NICHT
     * strukturell erzwingt.
     *
     * ELF, nicht zwoelf. Gemessen am gebauten Pfad ueber alle Zwischenwerte:
     *
     *    4 -> 1 Seite     11 -> 1 Seite     20 -> 2 Seiten
     *    6 -> 1 Seite     12 -> 2 SEITEN    24 -> 2 Seiten
     *   10 -> 1 Seite
     *
     * Der Task-Brief nennt 12 als tragende Obergrenze; das war aus dem Intervall
     * (10, 20] der Task-6-Messung interpoliert und ist um genau eine Zeile zu
     * hoch. Der Docblock von TrainingCertificateHtml (Task 6, gemessen) behauptet
     * 12 nirgends. Kein Befund am Pfad: der ausgelieferte Inhalt hat SECHS
     * Zeilen, der Abstand zur Umbruchgrenze sind also fuenf Zeilen.
     *
     * Zusammen mit dem Test darunter ist die Grenze auf einen einzelnen
     * Zeilenschritt festgenagelt statt auf ein Intervall von zehn.
     */
    public function testElfKenntnisZeilenBleibenEineSeite(): void
    {
        $pdf = $this->render($this->inhaltMitKenntnisZeilen(11));

        $this->assertSame(1, $this->pageCount($pdf));
    }

    /**
     * Negativkontrolle, ohne die der Test darueber wertlos ist: die
     * Seitenzahl-Assertion muss ueberhaupt ausloesen koennen. Wuerde
     * pageCount() immer 1 liefern (falsches Muster, siehe Klassen-Docblock),
     * bliebe dieser Test gruen und der darueber ebenfalls.
     *
     * ZWOELF und nicht 24: die erste Zeilenzahl, die wirklich umbricht. Eine
     * Negativkontrolle weit jenseits der Grenze belegt nur, dass irgendwo
     * umgebrochen wird; diese hier belegt WO.
     */
    public function testZwoelfKenntnisZeilenErzeugenEineZweiteSeite(): void
    {
        $pdf = $this->render($this->inhaltMitKenntnisZeilen(12));

        $this->assertGreaterThan(1, $this->pageCount($pdf));
    }

    // -----------------------------------------------------------------
    // Kriterium 3: keine fehlenden Glyphen
    // -----------------------------------------------------------------

    public function testKeineFehlendenGlyphenImInhalt(): void
    {
        // Geprueft wird der ROHE INHALT, NIE die Ausgabe von
        // TrainingCertificateHtml::build(). Grund, gemessen: FontGlyphCoverage
        // benutzt strip_tags(), und strip_tags() entfernt den <style>-Tag, nicht
        // dessen INHALT. Im Stylesheet der Huelle steht ein CSS-Kommentar mit
        // einem ★ darin, und der landete dann als "fehlendes Zeichen" im
        // Bericht — eine Phantom-Meldung fuer ein Zertifikat, in dem an dieser
        // Stelle kein Stern vorkommt:
        //     Inhalt allein:  array ()
        //     Huelle+Inhalt:  array ( 0 => '★' )
        // Der sichere Weg ist deshalb genau die Zeile unten: den Inhalt pruefen.
        //
        // Die echten ★ des Inhalts stehen in <span class="zeichen"> und laufen
        // per CSS in DejaVu Sans; gegen Oswald geprueft wird der Rest.
        $ohneZeichenSpans = (string) preg_replace(
            '#<span class="zeichen">.*?</span>#',
            '',
            $this->inhalt()
        );

        $report = FontGlyphCoverage::inspect($ohneZeichenSpans, $this->fontPath());

        // BEIDE Haelften pruefen. Nur missing === [] waere der alte Fehler in
        // neuer Form: ein nicht pruefbarer Font liefert ebenfalls [], und der
        // Test haette dann bloss belegt, dass die Schrift unlesbar ist.
        // Umgekehrt ist diese Assertion KEIN Schutz gegen eine kaputte
        // Schriftdatei — eine abgeschnittene Datei parst weiter und meldet
        // dieselben Zeichen wie die intakte. Das leistet nur die Fontliste in
        // testNormalfallIstEineSeiteMitEingebetteterSchrift.
        $this->assertTrue($report->checkable, 'Die echte Schrift muss pruefbar sein.');
        $this->assertSame([], $report->missing);
        $this->assertFalse($report->hasWarning());
    }

    // -----------------------------------------------------------------
    // Kriterium 4: keine unaufgeloesten Platzhalter
    // -----------------------------------------------------------------

    public function testKeineUnaufgeloestenPlatzhalter(): void
    {
        $this->assertFalse(ResttagePlaceholder::hasUnresolvedPlaceholder($this->inhalt()));
    }

    public function testEinUebriggebliebenerPlatzhalterWirdErkannt(): void
    {
        // Negativkontrolle mit der ECHTEN Rohfassung: sie enthaelt alle fuenf
        // Platzhalter. Ein selbst getippter String haette belegt, dass der Guard
        // auf selbst getippte Strings anspringt.
        $this->assertTrue(
            ResttagePlaceholder::hasUnresolvedPlaceholder(TrainingCertificateContent::template())
        );
    }

    // -----------------------------------------------------------------
    // Drei Eigenschaften, die aus dem Task-6-Review hierher verschoben wurden.
    // In Task 6 waeren sie Assertions auf einen String gewesen; hier sind sie
    // Assertions auf ein wirklich gerendertes PDF. Gemessen war jeweils, dass
    // eine kaputte Ausgabe die Suite gruen liess: .zert-logo von 40mm auf
    // 400mm, eine leere p-Regel, und Inhalt vor Logo/Headline emittiert.
    // -----------------------------------------------------------------

    public function testGeometrieDerBilderStimmt(): void
    {
        // Logo 40mm, Leiter-Unterschrift 48mm, Unterschriftsblock 54mm,
        // Headline 116mm bei 96 dpi.
        // Geprueft wird die BREITE der platzierten Bildobjekte im PDF, nicht der
        // CSS-String — eine Assertion auf "width: 40mm" im HTML haette 400mm
        // nicht gefangen, weil DomPDF den Wert erst beim Rendern anwendet.
        // Die Toleranz ist bewusst grob (DomPDF rundet auf Punkte); sie muss eng
        // genug bleiben, dass ein Faktor 10 auffaellt.
        $pdf = $this->render($this->inhalt());

        $breiten = $this->bildBreitenInMm($pdf);
        sort($breiten);

        $this->assertCount(4, $breiten, 'Es muessen genau vier Bilder platziert sein.');
        $this->assertEqualsWithDelta(40.0, $breiten[0], 1.5, 'Logo');
        $this->assertEqualsWithDelta(48.0, $breiten[1], 1.5, 'Unterschrift Schulungsleiter');
        $this->assertEqualsWithDelta(54.0, $breiten[2], 1.5, 'Unterschriftsblock RheinGedeck');
        $this->assertEqualsWithDelta(116.0, $breiten[3], 1.5, 'Headline');
    }

    public function testBasisStylesWirkenUndSindNichtNurDeklariert(): void
    {
        // Ein nackter <p> muss die Basis-Regel der Huelle tragen: 11.5pt
        // Schriftgroesse, letter-spacing 1.5px (= 1.125pt Tc bei 96 dpi) und
        // eigener Abstand. Geprueft wird das WIRKSAME Ergebnis im PDF, nicht die
        // Existenz des Selektors — eine leere Regel `p { }` liess die Suite in
        // Task 6 gruen.
        //
        // Isoliert werden die beiden neuen Zeilen ueber den Vergleich mit einem
        // Render OHNE die <p>-Elemente. Ein Filter auf "Zeilen mit 11.5pt und
        // 1.125pt" waere zirkulaer: er fand im Fehlerfall gar keine Zeile und
        // haette dann ueber eine leere Menge assertiert.
        $ohne = $this->textZeilen($this->render($this->inhalt('A', 'B')));
        $mit = $this->textZeilen($this->render($this->inhalt('A', 'B') . '<p>Erste Zeile</p><p>Zweite Zeile</p>'));

        $neu = $this->nurNeueZeilen($ohne, $mit);

        $this->assertCount(2, $neu, 'Die beiden <p> muessen genau zwei neue Textzeilen erzeugen.');

        foreach ($neu as $zeile) {
            $this->assertEqualsWithDelta(
                11.5,
                $zeile['groessePt'],
                0.01,
                'Die p-Regel setzt font-size 11.5pt. Kommt hier 12pt heraus, ist die Regel '
                . 'unwirksam und der <p> erbt die Standardgroesse.'
            );
            $this->assertEqualsWithDelta(
                1.125,
                $zeile['zeichenabstandPt'],
                0.001,
                'Die p-Regel setzt letter-spacing 1.5px = 1.125pt. 0.0 heisst: kein Tc im '
                . 'Content-Stream, die Regel wirkt nicht.'
            );
        }

        // Und der eigene Abstand: margin 3mm oben und unten, in DomPDF NICHT
        // kollabierend. Gemessen 31.001 pt Grundlinienabstand; mit leerer
        // p-Regel sind es 35.475 pt (DomPDFs UA-Stylesheet setzt margin 1.12em
        // auf 12pt Grundschrift). Der Abstand wird deshalb auf den Messwert
        // festgenagelt und nicht als "groesser als" gefordert: der Fehlerfall ist
        // hier GROESSER, eine Untergrenze liesse ihn durch.
        $this->assertEqualsWithDelta(
            31.0,
            $neu[0]['yPt'] - $neu[1]['yPt'],
            1.5,
            'Grundlinienabstand zweier <p> aus der p-Regel (margin 3mm, Zeilenhoehe 11.5pt).'
        );
    }

    /**
     * Zeilen, die nur im zweiten Render vorkommen.
     *
     * @param list<array{yPt: float, groessePt: float, zeichenabstandPt: float}> $ohne
     * @param list<array{yPt: float, groessePt: float, zeichenabstandPt: float}> $mit
     * @return list<array{yPt: float, groessePt: float, zeichenabstandPt: float}>
     */
    private function nurNeueZeilen(array $ohne, array $mit): array
    {
        $schluessel = static fn (array $z): string => sprintf(
            '%.3f|%.3f|%.3f',
            $z['yPt'],
            $z['groessePt'],
            $z['zeichenabstandPt']
        );

        $bekannt = [];
        foreach ($ohne as $z) {
            $bekannt[$schluessel($z)] = true;
        }

        $neu = [];
        foreach ($mit as $z) {
            if (!isset($bekannt[$schluessel($z)])) {
                $neu[] = $z;
            }
        }

        return $neu;
    }

    public function testLogoUndHeadlineStehenVorDemInhalt(): void
    {
        // Reihenfolge im Fluss, nicht im Quelltext: Logo und Headline gehoeren
        // nach OBEN. Ein Tausch von Inhalt und Bildern liess die Suite in Task 6
        // gruen, weil dort nur geprueft wurde, DASS beide vorkommen.
        // Geprueft wird die Y-Position: im PDF-Koordinatensystem hat oben den
        // GROESSEREN Wert.
        $pdf = $this->render($this->inhalt());

        $this->assertGreaterThan(
            $this->obersteTextZeileY($pdf),
            $this->obersteBildY($pdf),
            'Logo/Headline muessen ueber der ersten Textzeile liegen.'
        );
    }

    // -----------------------------------------------------------------
    // Font-Ordner: anlegen und wieder wegraeumen
    // -----------------------------------------------------------------

    private static function fontCacheDir(): string
    {
        return sys_get_temp_dir() . '/zert-render-fontcache-' . getmypid();
    }

    /**
     * Legt den Font-Ordner an und bricht mit Grund ab, wenn das nicht geht.
     *
     * Die Mechanik (mkdir pruefen, Schreibbarkeit pruefen, Grund aus
     * error_get_last() in die Meldung heben) steht seit dieser Runde in
     * DomPdfFontDir und wird von dort benutzt statt hier ein zweites Mal
     * geschrieben: dieselbe Zusicherung zweimal zu pflegen war schon bei den
     * DomPDF-Optionen fast der Fehler, und die Support-Klasse hat als einzige
     * einen Falsifikator fuer den nicht beschreibbaren Fall.
     *
     * Die KONSEQUENZ ist hier eine andere als in der Auslieferung, deshalb der
     * eigene Text im catch: die Auslieferung faellt aus, dieser Test wuerde
     * still ungenau. Der Rueckgabewert von mkdir() MUSS geprueft werden —
     * gemessen an ContractPdfRegressionTest mit nicht beschreibbarem
     * Elternverzeichnis: ein ungeprueftes @mkdir scheiterte nicht spaeter,
     * sondern liess die Klasse GRUEN, weil DomPDF still auf den geteilten
     * Fontordner der Host-App zurueckfaellt
     * (meingedeck/vendor/dompdf/dompdf/lib/fonts). Genau die Isolation, die
     * render() zusichert, waere dann lautlos weg.
     */
    private function prepareFontCacheDir(): string
    {
        try {
            return DomPdfFontDir::ensureWritable(self::fontCacheDir());
        } catch (\RuntimeException $e) {
            self::fail(sprintf(
                '%s Der Test bricht hier ab statt weiterzulaufen: ohne eigenen Ordner '
                . 'benutzt DomPDF still den geteilten Fontordner der Host-App, und dann '
                . 'haengt das Ergebnis vom dortigen, veraenderlichen Zustand ab — der Lauf '
                . 'waere gruen, aber die zugesicherte Isolation weg. Pruefen: Schreibrechte '
                . 'auf %s.',
                $e->getMessage(),
                dirname(self::fontCacheDir())
            ));
        }
    }

    /**
     * Den eigenen Font-Ordner wieder wegraeumen.
     *
     * Bleibt etwas liegen, geht die Meldung auf STDERR und der Lauf bleibt
     * gruen: diese Methode laeuft NACH allen Assertionen, und ein fail() von
     * hier faerbte eine Aussage ueber das Zertifikat rot, obwohl nur ein
     * Verzeichnis klemmt (Rechte, offene Handles, gemountetes Laufwerk).
     * Nichts bleibt dabei unbemerkt liegen — genau das war der Befund, der zu
     * dieser Methode gefuehrt hat.
     */
    private static function removeFontCache(): void
    {
        $dir = self::fontCacheDir();
        if (!is_dir($dir)) {
            return;
        }

        $reste = self::deleteRecursively($dir);
        if ($reste === []) {
            return;
        }

        fwrite(STDERR, PHP_EOL . sprintf(
            '[%s] Font-Ordner nicht vollstaendig entfernt (%d Eintrag/Eintraege bleiben liegen):%s  %s%s',
            self::class,
            count($reste),
            PHP_EOL,
            implode(PHP_EOL . '  ', $reste),
            PHP_EOL
        ));
    }

    /**
     * Loescht $dir samt Inhalt und liefert zurueck, was NICHT wegzuraeumen war.
     *
     * scandir() statt glob(): glob() liefert Dotfiles nicht (ein .DS_Store blieb
     * damit liegen) und liest *, ?, [ ] im Pfad als Muster — ein
     * Verzeichnisname mit eckigen Klammern liess glob() auf ALLES leer laufen.
     * Der sichere Weg ist scandir(); Messwerte in
     * ContractPdfRegressionTest::deleteRecursively().
     *
     * Das @ vor unlink()/rmdir() unterdrueckt nur die PHP-Diagnose, weil
     * failOnWarning="true" den Lauf sonst hier rot faerbt. Das Signal laeuft
     * ueber den Rueckgabewert; wer das @ entfernt, muss es dabei belassen.
     *
     * @return list<string> Pfade, die stehen geblieben sind; leer = sauber
     */
    private static function deleteRecursively(string $dir): array
    {
        $entries = scandir($dir);
        if ($entries === false) {
            return [$dir];
        }

        $failed = [];
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $dir . '/' . $entry;

            // is_dir() folgt Symlinks. Ein Symlink auf ein Verzeichnis wird
            // deshalb per unlink() entfernt (loescht den Link) und NICHT
            // rekursiv ausgeraeumt — sonst loeschte das Aufraeumen fremde
            // Dateien am Linkziel.
            if (!is_link($path) && is_dir($path)) {
                $failed = array_merge($failed, self::deleteRecursively($path));
                continue;
            }

            if (!@unlink($path)) {
                $failed[] = $path;
            }
        }

        if (!@rmdir($dir)) {
            $failed[] = $dir;
        }

        return $failed;
    }
}
