<?php

namespace Platform\Recruiting\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Support\TrainingCertificateHtml;

class TrainingCertificateHtmlTest extends TestCase
{
    private function assets(): array
    {
        return [
            'font' => '/app/resources/fonts/Oswald-SemiBold.ttf',
            'logo' => 'data:image/png;base64,AAAA',
            'headline' => 'data:image/png;base64,BBBB',
            'signature' => 'data:image/png;base64,CCCC',
        ];
    }

    public function testSeitenSetupUndPapierfarbe(): void
    {
        $html = TrainingCertificateHtml::build('<p>X</p>', $this->assets());

        $this->assertStringContainsString('@page { margin: 0; size: A4; }', $html);
        $this->assertStringContainsString('background: #FDF3E0', $html);
        $this->assertStringContainsString('color: #3C4A63', $html);
    }

    /**
     * Ohne die Deklaration interpretiert DomPDF das Markup nicht als UTF-8 und
     * jeder Umlaut aus dem Vorlageninhalt (Kursnamen, "Schulungsleiter",
     * Ortsnamen) landet als Mojibake im PDF — wieder ohne Exception, ohne Log.
     */
    public function testCharsetIstDeklariert(): void
    {
        $html = TrainingCertificateHtml::build('<p>Prüfung in Köln</p>', $this->assets());

        $this->assertStringContainsString('<meta charset="UTF-8">', $html);
    }

    public function testFontWirdMitAbsolutemPfadEingebunden(): void
    {
        $html = TrainingCertificateHtml::build('', $this->assets());

        $this->assertStringContainsString(
            'src: url("/app/resources/fonts/Oswald-SemiBold.ttf") format("truetype")',
            $html
        );
    }

    /**
     * Der font-weight-Test unten prueft nur eine Feinheit der Bindung. Die
     * Bindung selbst ist der Name: heisst die Familie am body anders als im
     * @font-face — oder fordert der body ueberhaupt keine Familie an —, dann
     * faellt DomPDF STUMM auf Helvetica zurueck. Beide Mutationen waren
     * gemessen gruen:
     *   body: font-family: sans-serif        (Bindung ganz weg)
     *   @font-face: font-family: "Zertifikat" (Namen weichen ab)
     * Geprueft wird deshalb nicht das Literal "Zert", sondern die Invariante:
     * der body fordert genau die Familie an, die das @font-face definiert. Wer
     * die Schrift umbenennt, muss beide Stellen mitziehen.
     */
    public function testBodyFordertDieImFontFaceDefinierteFamilieAn(): void
    {
        $html = TrainingCertificateHtml::build('', $this->assets());

        $this->assertSame(
            1,
            preg_match('/@font-face\s*\{[^}]*font-family:\s*"([^"]+)"/', $html, $f),
            'Das @font-face definiert keine benannte Familie.'
        );

        // Vorne verankert wie im font-weight-Test, damit "body {" nicht mitten
        // in einem anderen Selektor trifft.
        $this->assertMatchesRegularExpression(
            '/(?:^|[\s;}])body\s*\{[^}]*font-family:\s*"' . preg_quote($f[1], '/') . '"/',
            $html,
            'body fordert die im @font-face definierte Familie ("' . $f[1] . '") nicht an '
            . '— DomPDF rendert STUMM in Helvetica.'
        );
    }

    /**
     * Die gemessene Falle: "Oswald-SemiBold.ttf" verleitet zu
     * font-weight: 600 im @font-face. Ohne font-weight: 600 am body faellt
     * DomPDF dann STUMM auf Helvetica zurueck — kein Fehler, kein Log.
     * Gemessen am /BaseFont des erzeugten PDF:
     *   @font-face normal + body (keine Angabe) -> Oswald-SemiBold
     *   @font-face 600    + body (keine Angabe) -> Helvetica
     *   @font-face 600    + body 600            -> Oswald-SemiBold
     *   @font-face bold   + body bold           -> Oswald-SemiBold
     * Geprueft wird deshalb nicht der Wert "normal", sondern die Invariante
     * dahinter: die beiden Angaben muessen zusammenpassen. Wer den
     * @font-face an den Dateinamen angleicht, muss den body mitziehen.
     */
    public function testFontWeightImFontFaceUndAmBodyPassenZusammen(): void
    {
        $html = TrainingCertificateHtml::build('', $this->assets());

        $this->assertSame(1, preg_match('/@font-face\s*\{([^}]*)\}/', $html, $face));
        $this->assertSame(1, preg_match('/(?:^|[\s;}])body\s*\{([^}]*)\}/', $html, $body));

        $weightOf = static function (string $block): string {
            // Keine Angabe = "normal": genau so behandelt es der Browser wie
            // DomPDF, und genau diese Zeile der Messtabelle wird ausgeliefert.
            return preg_match('/font-weight:\s*([^;}]+)/', $block, $m)
                ? trim($m[1])
                : 'normal';
        };

        $this->assertSame(
            $weightOf($face[1]),
            $weightOf($body[1]),
            'font-weight im @font-face und am body weichen voneinander ab. '
            . 'DomPDF findet dann keine passende Schnittvariante von "Zert" '
            . 'und rendert STUMM in Helvetica. Beides angleichen oder beides '
            . 'auf normal lassen.'
        );
    }

    public function testFussKlassenSindBottomVerankertUndKeineTabelle(): void
    {
        $html = TrainingCertificateHtml::build('', $this->assets());

        // Je Klasse blockweise geprueft ([^}]* laeuft nicht ueber die
        // schliessende Klammer hinaus). Zwei getrennte Substrings wuerden
        // gruen bleiben, wenn 12mm und 10mm die Klassen tauschen oder
        // position: absolute verloren geht — und ohne absolute verankert
        // bottom gar nichts.
        foreach ([
            '.zert-datum' => '46mm',
            '.zert-fuss-links' => '12mm',
            '.zert-fuss-rechts' => '10mm',
        ] as $klasse => $bottom) {
            $this->assertMatchesRegularExpression(
                '/\\' . $klasse . '\s*\{[^}]*position:\s*absolute/',
                $html,
                $klasse . ' ist nicht absolut positioniert.'
            );
            $this->assertMatchesRegularExpression(
                '/\\' . $klasse . '\s*\{[^}]*bottom:\s*' . $bottom . '/',
                $html,
                $klasse . ' ist nicht auf bottom: ' . $bottom . ' verankert.'
            );
        }

        // Als <table> laeuft die bottom-Verankerung in DomPDF unten aus der
        // Seite. Die Huelle darf deshalb keine Tabelle emittieren.
        $this->assertStringNotContainsString('<table', $html);
    }

    public function testNackteElementeWerdenGestylt(): void
    {
        $html = TrainingCertificateHtml::build('', $this->assets());

        // Am Zeilenanfang verankert statt als Substring "p {": das
        // Stylesheet richtet die Selektoren in Spalten aus (`p      {`), und
        // ein unverankertes Muster koennte ausserdem mitten in einem anderen
        // Selektor treffen.
        foreach (['p', 'h2', 'strong', 'li'] as $selector) {
            $this->assertMatchesRegularExpression(
                '/^\s*' . $selector . '\s*\{/m',
                $html,
                'Kein Basis-Style fuer <' . $selector . '>.'
            );
        }
    }

    public function testSonderzeichenKlasseSchaltetAufDejaVu(): void
    {
        $html = TrainingCertificateHtml::build('', $this->assets());

        $this->assertMatchesRegularExpression(
            '/\.zeichen\s*\{[^}]*DejaVu Sans/',
            $html
        );
    }

    public function testDreiBilderWerdenEmittiert(): void
    {
        $html = TrainingCertificateHtml::build('', $this->assets());

        $this->assertStringContainsString('data:image/png;base64,AAAA', $html);
        $this->assertStringContainsString('data:image/png;base64,BBBB', $html);
        $this->assertStringContainsString('data:image/png;base64,CCCC', $html);
    }

    /**
     * Der Bilder-Test oben prueft nur, DASS die Signatur emittiert wird, der
     * Fuss-Test nur, dass die CSS-Klasse bottom-verankert ist. Faellt die
     * Klasse am <div> weg, hing das Bild im normalen Fluss — beide Tests
     * blieben gruen, und die einzige Layout-Entscheidung dieser Huelle waere
     * verschwunden. Geprueft wird deshalb der Zusammenhang: das Bild steckt im
     * verankerten Container.
     */
    public function testSignaturbildStecktImVerankertenFussContainer(): void
    {
        $html = TrainingCertificateHtml::build('', $this->assets());

        $this->assertMatchesRegularExpression(
            '/<div class="zert-fuss-links">\s*<img class="zert-signatur"/',
            $html,
            'Das Unterschriftsbild steckt nicht in .zert-fuss-links und haengt '
            . 'damit im normalen Fluss statt am Seitenfuss.'
        );
    }

    public function testFehlendesBildWirdWeggelassenOhneFehler(): void
    {
        $assets = $this->assets();
        $assets['headline'] = null;

        $html = TrainingCertificateHtml::build('', $assets);

        // Auf das <img> geprueft, nicht auf den nackten Klassennamen: die
        // CSS-Regel .zert-headline steht immer im Stylesheet, unabhaengig
        // davon, ob das Bild vorhanden ist.
        $this->assertStringNotContainsString('<img class="zert-headline"', $html);
        $this->assertStringNotContainsString('data:image/png;base64,BBBB', $html);
        $this->assertStringContainsString('<img class="zert-logo"', $html);
    }

    /**
     * Ein leerer Font-Pfad wuerde wortlos zu src: url("") — und ein
     * @font-face mit url("") ignoriert DomPDF STUMM, das Zertifikat kaeme in
     * Helvetica heraus. Leerstring und null sind beide still (keine
     * PHP-Meldung), deshalb muss die Huelle selbst laut werden. Aus
     * TrainingCertificateAssets::resolve() kann der Pfad nie leer sein; der
     * Guard trifft handgebaute Asset-Arrays, etwa aus einem Test oder einem
     * kuenftigen zweiten Aufrufer.
     */
    public function testLeererFontPfadWirftStattStummZuRendern(): void
    {
        $assets = $this->assets();
        $assets['font'] = '';

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Leerer Font-Pfad');

        TrainingCertificateHtml::build('', $assets);
    }

    public function testFontPfadNullWirftStattStummZuRendern(): void
    {
        $assets = $this->assets();
        $assets['font'] = null;

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Leerer Font-Pfad');

        TrainingCertificateHtml::build('', $assets);
    }

    /**
     * Der fehlende Key war vorher laut (PHP-Warning, unter failOnWarning="true"
     * ein Testabbruch, unter Laravel eine ErrorException) — aber die Warning
     * kam VOR dem Rendern, das HTML wurde trotzdem mit url("") gebaut. Das
     * "?? ''" im Guard macht daraus dieselbe Exception wie bei Leerstring und
     * null: laut bleibt laut, und es entsteht kein halbfertiges HTML mehr.
     */
    public function testFehlenderFontKeyWirftEbenfalls(): void
    {
        $assets = $this->assets();
        unset($assets['font']);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Leerer Font-Pfad');

        TrainingCertificateHtml::build('', $assets);
    }

    public function testInhaltWirdUnveraendertEingesetzt(): void
    {
        $content = '<div class="val">ERIKA MUSTERMANN</div>';

        $this->assertStringContainsString(
            $content,
            TrainingCertificateHtml::build($content, $this->assets())
        );
    }

    /**
     * ALTBESTAND: ein vor dem 17.08.2026 ausgestellter Snapshot traegt den
     * rechten Fussblock selbst, mit dem NAMEN des Schulungsleiters. Die Huelle
     * baut ihn seither auch — beide sind absolut auf dieselbe Position gesetzt.
     *
     * GEMESSEN, bevor dieser Test entstand: das PDF zeigte "MICHEL ZIMMER" quer
     * ueber der neuen Unterschrift, mit zwei Linien und zwei Bildunterschriften.
     * Kein Fehler, kein Log — nur ein kaputtes Dokument beim Bewerber.
     */
    public function testAlterSnapshotBringtKeinenZweitenLeiterBlockMit(): void
    {
        $alt = <<<'SNAP'
<div class="val">Erika Mustermann</div>
<div class="zert-datum">Düsseldorf, den 05.08.2026</div>

<div class="zert-fuss-rechts">
  <div class="leiter">Michel Zimmer</div>
  <div class="linie"></div>
  <div class="cap">Schulungsleiter</div>
</div>
SNAP;

        $html = TrainingCertificateHtml::build($alt, $this->assets());

        $this->assertStringNotContainsString(
            'Michel Zimmer',
            $html,
            'Der Name aus dem alten Snapshot darf nicht mehr im Dokument stehen.'
        );
        $this->assertSame(
            1,
            substr_count($html, '<div class="zert-fuss-rechts">'),
            'Genau EIN rechter Fussblock — der aus der Huelle.'
        );
        // Und der Rest des Snapshots bleibt unberuehrt: aufgeraeumt wird nur,
        // was die Huelle selbst wieder anbaut.
        $this->assertStringContainsString('Erika Mustermann', $html);
        $this->assertStringContainsString('Düsseldorf, den 05.08.2026', $html);
    }

    /**
     * Ein NEUER Snapshot enthaelt den Block nicht — die Aufraeumzeile darf dort
     * nichts anfassen, und der Block der Huelle muss trotzdem genau einmal
     * stehen.
     */
    public function testNeuerSnapshotBekommtGenauEinenLeiterBlock(): void
    {
        $html = TrainingCertificateHtml::build('<div class="val">Erika</div>', $this->assets());

        $this->assertSame(1, substr_count($html, '<div class="zert-fuss-rechts">'));
        $this->assertStringContainsString('<div class="cap">Schulungsleiter</div>', $html);
    }
}
