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

    public function testFontWirdMitAbsolutemPfadEingebunden(): void
    {
        $html = TrainingCertificateHtml::build('', $this->assets());

        $this->assertStringContainsString(
            'src: url("/app/resources/fonts/Oswald-SemiBold.ttf") format("truetype")',
            $html
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

    public function testInhaltWirdUnveraendertEingesetzt(): void
    {
        $content = '<div class="val">ERIKA MUSTERMANN</div>';

        $this->assertStringContainsString(
            $content,
            TrainingCertificateHtml::build($content, $this->assets())
        );
    }
}
