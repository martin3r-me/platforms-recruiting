<?php

namespace Platform\Recruiting\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Support\FontGlyphCoverage;
use Platform\Recruiting\Support\FontGlyphReport;

class FontGlyphCoverageTest extends TestCase
{
    private ?string $tempDir = null;

    protected function tearDown(): void
    {
        if ($this->tempDir !== null && is_dir($this->tempDir)) {
            foreach (glob($this->tempDir . '/*') ?: [] as $file) {
                unlink($file);
            }
            rmdir($this->tempDir);
        }
        $this->tempDir = null;

        parent::tearDown();
    }

    private function font(): string
    {
        return __DIR__ . '/../../resources/fonts/Oswald-SemiBold.ttf';
    }

    /**
     * Beschaedigte Kopie der echten Schrift in einem temporaeren Verzeichnis.
     * resources/fonts bleibt unangetastet.
     */
    private function damagedFont(string $name, int $keepBytes): string
    {
        if ($this->tempDir === null) {
            $this->tempDir = sys_get_temp_dir() . '/rec-glyph-' . getmypid() . '-' . bin2hex(random_bytes(4));
            mkdir($this->tempDir, 0700, true);
        }

        $path = $this->tempDir . '/' . $name . '.ttf';
        file_put_contents($path, substr((string) file_get_contents($this->font()), 0, $keepBytes));

        return $path;
    }

    /**
     * FontLib schreibt beim Parsen einer abgeschnittenen Datei hunderte
     * PHP-Warnungen (unpack(): not enough input values). Gemessen: 568 bei
     * 40 %, 652 bei 5 %, keine bei 3 Byte / 0 Byte / intakt.
     *
     * phpunit.xml hat failOnWarning="true" — diese Fremdcode-Diagnosen wuerden
     * die Suite rot machen, obwohl der Prueflauf tut, was er soll. Sie werden
     * deshalb HIER geschluckt, nicht im Produktivcode: dort sind sie das
     * einzige Signal, dass an der Datei etwas faul ist (siehe den Test zum
     * Fehlerhandler weiter unten).
     *
     * @param  int|null  $diagnostics  Anzahl geschluckter Diagnosen, per Referenz
     */
    private function inspectSwallowingFontLibNoise(string $content, string $fontPath, ?int &$diagnostics = null): FontGlyphReport
    {
        $seen = 0;
        set_error_handler(static function () use (&$seen): bool {
            $seen++;

            return true;
        });

        try {
            return FontGlyphCoverage::inspect($content, $fontPath);
        } finally {
            restore_error_handler();
            $diagnostics = $seen;
        }
    }

    // --- Bestandsfaelle aus Task 4, auf inspect() umgestellt ---------------

    public function testLatinUndUmlauteSindAbgedeckt(): void
    {
        $report = FontGlyphCoverage::inspect(
            'GÄSTEBETREUUNG UND KOMMUNIKATION – 3-Gang-Menü, 12 €',
            $this->font()
        );

        $this->assertTrue($report->checkable);
        $this->assertSame([], $report->missing);
        // Der EINZIGE Fall ohne Warnung. Ohne diese Zeile waere ein
        // hasWarning(), das immer true liefert, gruen.
        $this->assertFalse($report->hasWarning());
    }

    public function testSternFehlt(): void
    {
        $report = FontGlyphCoverage::inspect('STEHEMPFANG ★ FLYING BUFFET', $this->font());

        $this->assertTrue($report->checkable);
        $this->assertSame(['★'], $report->missing);
        $this->assertTrue($report->hasWarning());
    }

    public function testJedesFehlendeZeichenNurEinmalUndInReihenfolge(): void
    {
        $report = FontGlyphCoverage::inspect('★ A ☂ B ★', $this->font());

        $this->assertTrue($report->checkable);
        $this->assertSame(['★', '☂'], $report->missing);
    }

    public function testHtmlTagsWerdenNichtGepruefft(): void
    {
        // Der Vorlageninhalt ist HTML. Spitze Klammern und Attributnamen
        // stehen nie im gerenderten Text und duerfen nicht gemeldet werden.
        $report = FontGlyphCoverage::inspect('<div class="skill">A ★ B</div>', $this->font());

        $this->assertTrue($report->checkable);
        $this->assertSame(['★'], $report->missing);
    }

    public function testHtmlEntitiesWerdenDekodiert(): void
    {
        // Die einzige ausgelieferte Vorlage schreibt den Stern als &#9733;.
        // Ohne Dekodierung wuerde die Pruefung "&", "#", "9" ... pruefen und
        // den Stern nie finden. Nicht wegoptimieren.
        $report = FontGlyphCoverage::inspect('A &#9733; B', $this->font());

        $this->assertTrue($report->checkable);
        $this->assertSame(['★'], $report->missing);
        $this->assertTrue($report->hasWarning());
    }

    public function testLeererInhalt(): void
    {
        $report = FontGlyphCoverage::inspect('', $this->font());

        $this->assertTrue($report->checkable);
        $this->assertSame([], $report->missing);
        $this->assertFalse($report->hasWarning());
    }

    // --- Die fuenf Beschaedigungsstufen der Messtabelle --------------------

    public function testStufeIntakt(): void
    {
        $report = FontGlyphCoverage::inspect('STEHEMPFANG ★', $this->font());

        $this->assertTrue($report->checkable);
        $this->assertSame(['★'], $report->missing);
        $this->assertTrue($report->hasWarning());
    }

    public function testStufeAbgeschnitten40Prozent(): void
    {
        // 43 648 von 109 120 Byte. Der cmap-Table liegt noch im erhaltenen
        // Kopf: die Pruefung sieht die volle Zeichentabelle (737 Eintraege,
        // wie intakt), das PDF fiele trotzdem still auf Helvetica zurueck.
        // Deshalb ist /BaseFont der einzige Waechter, der jede Stufe rot
        // macht (Task 9, Assertion 2) — dieser Task ersetzt ihn nicht.
        $report = $this->inspectSwallowingFontLibNoise(
            'STEHEMPFANG ★',
            $this->damagedFont('trunc40', 43648),
            $diagnostics
        );

        $this->assertTrue($report->checkable);
        $this->assertSame(['★'], $report->missing);
        $this->assertTrue($report->hasWarning());
        // Die Beschaedigung ist echt und FontLib merkt sie — die Glyph-Pruefung
        // allein sieht sie nicht.
        $this->assertGreaterThan(0, $diagnostics);
    }

    public function testStufeAbgeschnitten5Prozent(): void
    {
        $report = $this->inspectSwallowingFontLibNoise(
            'STEHEMPFANG ★',
            $this->damagedFont('trunc05', 5456),
            $diagnostics
        );

        $this->assertTrue($report->checkable);
        $this->assertSame(['★'], $report->missing);
        $this->assertTrue($report->hasWarning());
        $this->assertGreaterThan(0, $diagnostics);
    }

    public function testAbgeschnitteneSchriftIstNichtPruefbarWennWarnungenZuExceptionsWerden(): void
    {
        // So laeuft es in der Host-App: Laravels HandleExceptions::handleError
        // wirft aus jeder PHP-Warnung eine ErrorException. FontLibs Parse-Noise
        // wird dort also zur Exception — charMap() faengt sie (\Throwable) und
        // meldet "nicht pruefbar". Das ist ein BESSERES Ergebnis als der
        // handlerlose Lauf oben, und es beweist zugleich: nichts entkommt nach
        // draussen, die Pruefung bleibt Warnung und wird nie zum Gate.
        $path = $this->damagedFont('trunc40-laravel', 43648);

        set_error_handler(static function (int $number, string $message, string $file = '', int $line = 0): bool {
            throw new \ErrorException($message, 0, $number, $file, $line);
        });

        try {
            $report = FontGlyphCoverage::inspect('STEHEMPFANG ★', $path);
        } finally {
            restore_error_handler();
        }

        $this->assertFalse($report->checkable);
        $this->assertSame([], $report->missing);
        $this->assertTrue($report->hasWarning());
    }

    public function testStufeDreiByte(): void
    {
        // Die interessante Stufe: hier schwiegen vor Task 4a BEIDE Wege.
        $report = FontGlyphCoverage::inspect('STEHEMPFANG ★', $this->damagedFont('drei-byte', 3));

        $this->assertFalse($report->checkable);
        $this->assertSame([], $report->missing);
        $this->assertTrue($report->hasWarning());
    }

    public function testStufeNullByte(): void
    {
        $report = FontGlyphCoverage::inspect('STEHEMPFANG ★', $this->damagedFont('null-byte', 0));

        $this->assertFalse($report->checkable);
        $this->assertSame([], $report->missing);
        $this->assertTrue($report->hasWarning());
    }

    public function testFehlendeFontdateiIstNichtPruefbarUndBlockiertNicht(): void
    {
        $report = FontGlyphCoverage::inspect('★', '/gibt/es/nicht.ttf');

        $this->assertFalse($report->checkable);
        $this->assertSame([], $report->missing);
        $this->assertTrue($report->hasWarning());
    }

    // --- Der Rueckgabewert selbst -----------------------------------------

    public function testInspectGibtImmerEinenReportZurueck(): void
    {
        $this->assertInstanceOf(FontGlyphReport::class, FontGlyphCoverage::inspect('A', $this->font()));
        $this->assertInstanceOf(FontGlyphReport::class, FontGlyphCoverage::inspect('A', '/gibt/es/nicht.ttf'));
    }

    public function testMissingIstEntferntDamitEinLeeresArrayNurNochNichtsFehltBedeutet(): void
    {
        $this->assertFalse(
            method_exists(FontGlyphCoverage::class, 'missing'),
            'missing() muss entfallen: sein leeres Array bedeutete beides.'
        );
    }
}
