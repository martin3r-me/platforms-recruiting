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

    // --- Das Dateihandle --------------------------------------------------

    /**
     * Registriert den zaehlenden Wrapper und legt die Nutzdaten fest.
     *
     * @param  int  $failOpenAttempt  Nummer des fopen()-Versuchs, der scheitern
     *                                soll. Font::load() liest den 4-Byte-Header
     *                                per file_get_contents() (Versuch 1) und
     *                                oeffnet die Datei danach per
     *                                BinaryStream::open() (Versuch 2). 0 = alle
     *                                Versuche gelingen.
     */
    private function countingFont(string $data, int $failOpenAttempt = 0): string
    {
        if (!in_array(CountingFontStream::SCHEME, stream_get_wrappers(), true)) {
            stream_wrapper_register(CountingFontStream::SCHEME, CountingFontStream::class);
        }
        CountingFontStream::reset($data, $failOpenAttempt);

        return CountingFontStream::SCHEME . '://font';
    }

    /**
     * Das Dateihandle muss auch dann zugehen, wenn parse() wirft.
     *
     * Gemessen am Stand vor dieser Aenderung (close() als letzte Zeile im
     * try-Block): Erfolgspfad 2 geoeffnet / 2 geschlossen, Fehlerpfad 2
     * geoeffnet / 1 geschlossen — pro Aufruf ein Handle offen. Ueber die
     * Prozess-Dateideskriptoren gegengemessen: /dev/fd wuchs im Fehlerpfad um
     * genau 1 pro Aufruf, im Erfolgspfad um 0.
     *
     * Der Fehlerpfad ist hier kein Sonderfall, sondern der Regelfall der
     * Host-App: Laravels HandleExceptions::handleError macht aus FontLibs
     * Parse-Warnungen ErrorExceptions (siehe
     * testAbgeschnitteneSchriftIstNichtPruefbarWennWarnungenZuExceptionsWerden).
     * Genau die beschaedigte Schrift, fuer die diese Klasse gebaut ist, ist
     * also die, bei der das Handle offen blieb.
     */
    public function testDateihandleWirdAuchImFehlerpfadGeschlossen(): void
    {
        $truncated = substr((string) file_get_contents($this->font()), 0, 43648);
        $path = $this->countingFont($truncated);

        set_error_handler(static function (int $number, string $message, string $file = '', int $line = 0): bool {
            throw new \ErrorException($message, 0, $number, $file, $line);
        });

        try {
            $report = FontGlyphCoverage::inspect('STEHEMPFANG ★', $path);
        } finally {
            restore_error_handler();
        }

        // Erst belegen, dass wirklich der Fehlerpfad gelaufen ist. Ohne diese
        // Zeile waere die Handle-Assertion auch dann gruen, wenn die Pruefung
        // sauber durchgelaufen ist und es nichts zu retten gab.
        $this->assertFalse($report->checkable, 'Erwartet war der Fehlerpfad (parse() wirft).');
        // Und dass der Wrapper ueberhaupt benutzt wurde: 0 === 0 waere sonst
        // eine Assertion ohne Gegenstand.
        $this->assertGreaterThan(0, CountingFontStream::$opened, 'Der Wrapper wurde nicht benutzt.');
        $this->assertSame(
            CountingFontStream::$opened,
            CountingFontStream::$closed,
            'Jedes geoeffnete Dateihandle muss wieder geschlossen werden — auch wenn '
            . 'parse() oder getUnicodeCharMap() wirft. Bleibt eines offen, laeuft close() '
            . 'nur im Erfolgspfad und gehoert in einen finally-Block.'
        );
    }

    public function testDateihandleWirdImErfolgspfadGeschlossen(): void
    {
        $path = $this->countingFont((string) file_get_contents($this->font()));

        $report = FontGlyphCoverage::inspect('STEHEMPFANG ★', $path);

        $this->assertTrue($report->checkable);
        $this->assertSame(['★'], $report->missing);
        $this->assertGreaterThan(0, CountingFontStream::$opened, 'Der Wrapper wurde nicht benutzt.');
        $this->assertSame(
            CountingFontStream::$opened,
            CountingFontStream::$closed,
            'Auch im Erfolgspfad muss jedes Handle wieder zugehen.'
        );
    }

    /**
     * Eine Schrift, die sich statten laesst, aber nicht oeffnen — der Fall, den
     * die is_file()/is_readable()-Pruefung nicht abfangen kann, weil zwischen
     * Pruefung und fopen() Zeit liegt (Rechte geaendert, Deskriptoren erschoepft,
     * Netzlaufwerk weg).
     *
     * FontLibs Font::load() wertet den Rueckgabewert von BinaryStream::load()
     * nicht aus und liefert das Objekt auch dann, wenn fopen() false ergab.
     * close() ruft dann fclose(false) und wirft einen TypeError. Passiert das im
     * finally-Block ungeschuetzt, verlaesst der TypeError inspect() am eigenen
     * catch vorbei. Gemessen mit entferntem innerem try/catch:
     * "TypeError: fclose(): Argument #1 ($stream) must be of type resource,
     * false given" entkam nach draussen.
     */
    public function testNichtOeffenbareSchriftBleibtEineWarnungUndWirdKeineException(): void
    {
        $path = $this->countingFont((string) file_get_contents($this->font()), failOpenAttempt: 2);

        // Ohne werfenden Error-Handler: FontLibs eigene Diagnosen auf dem
        // toten Handle wuerden unter failOnWarning="true" die Suite rot machen.
        $report = @FontGlyphCoverage::inspect('STEHEMPFANG ★', $path);

        $this->assertFalse($report->checkable);
        $this->assertSame([], $report->missing);
        $this->assertTrue($report->hasWarning());
    }
}

/**
 * Stream-Wrapper, der mitzaehlt, wie oft geoeffnet und wie oft geschlossen
 * wurde. Damit ist "das Handle bleibt offen" direkt messbar, statt ueber die
 * Prozess-Dateideskriptoren geschaetzt: die haengen daran, wann PHPs
 * Zyklen-Sammler laeuft (FontLibs Tabellen halten Rueckverweise auf die Datei),
 * und wuerden den Test von der Speicherverwaltung abhaengig machen.
 *
 * Liegt in dieser Datei und nicht unter tests/Support/, weil nur dieser Test
 * ihn braucht.
 */
final class CountingFontStream
{
    public const SCHEME = 'reccountingfont';

    public static string $data = '';
    public static int $opened = 0;
    public static int $closed = 0;
    public static int $failOpenAttempt = 0;

    private static int $attempts = 0;

    /** @var resource|null */
    public $context;

    private int $position = 0;

    public static function reset(string $data, int $failOpenAttempt = 0): void
    {
        self::$data = $data;
        self::$opened = 0;
        self::$closed = 0;
        self::$attempts = 0;
        self::$failOpenAttempt = $failOpenAttempt;
    }

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        self::$attempts++;
        if (self::$failOpenAttempt !== 0 && self::$attempts === self::$failOpenAttempt) {
            // PHP ruft stream_close() nach einem gescheiterten stream_open()
            // nicht auf — dieser Versuch zaehlt deshalb auch nicht als geoeffnet.
            return false;
        }

        $this->position = 0;
        self::$opened++;

        return true;
    }

    public function stream_close(): void
    {
        self::$closed++;
    }

    public function stream_read(int $count): string
    {
        $chunk = substr(self::$data, $this->position, $count);
        $this->position += strlen($chunk);

        return $chunk;
    }

    public function stream_eof(): bool
    {
        return $this->position >= strlen(self::$data);
    }

    public function stream_seek(int $offset, int $whence = SEEK_SET): bool
    {
        $target = match ($whence) {
            SEEK_SET => $offset,
            SEEK_CUR => $this->position + $offset,
            SEEK_END => strlen(self::$data) + $offset,
            default => -1,
        };
        if ($target < 0) {
            return false;
        }
        $this->position = $target;

        return true;
    }

    public function stream_tell(): int
    {
        return $this->position;
    }

    /** @return array<string,int> */
    public function stream_stat(): array
    {
        return ['mode' => 0100444, 'size' => strlen(self::$data)];
    }

    /** @return array<string,int> Regulaere, lesbare Datei — is_file() und is_readable() sollen zustimmen. */
    public function url_stat(string $path, int $flags): array
    {
        return ['mode' => 0100444, 'size' => strlen(self::$data)];
    }
}
