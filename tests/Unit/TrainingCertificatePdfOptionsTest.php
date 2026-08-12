<?php

namespace Platform\Recruiting\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Support\TrainingCertificatePdfOptions;

class TrainingCertificatePdfOptionsTest extends TestCase
{
    public function testEnthaeltAlleFuenfSchluessel(): void
    {
        $opts = TrainingCertificatePdfOptions::for('/app/resources/fonts/X.ttf', '/app');

        $this->assertSame(
            ['chroot', 'defaultFont', 'dpi', 'isHtml5ParserEnabled', 'isRemoteEnabled'],
            self::sortedKeys($opts)
        );
    }

    public function testRemoteBleibtAusWieLive(): void
    {
        $opts = TrainingCertificatePdfOptions::for('/app/resources/fonts/X.ttf', '/app');

        $this->assertFalse($opts['isRemoteEnabled']);
    }

    public function testFontPfadLiegtUnterChroot(): void
    {
        // Genau diese Bedingung entscheidet, ob DomPDF die Schrift einbettet
        // oder stumm auf Helvetica zurueckfaellt.
        $opts = TrainingCertificatePdfOptions::for('/app/resources/fonts/X.ttf', '/app');

        $this->assertStringStartsWith($opts['chroot'], '/app/resources/fonts/X.ttf');
    }

    public function testFontPfadAusserhalbChrootWirft(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        TrainingCertificatePdfOptions::for('/anderswo/X.ttf', '/app');
    }

    /**
     * Regressionstest: eine naive Praefix-Pruefung (str_starts_with ohne
     * Trennzeichen) haelt "/apply/x.ttf" faelschlich fuer "innerhalb von
     * /app", weil der String "/app" ein Zeichen-Praefix von "/apply" ist.
     * "/apply" ist aber ein NACHBAR-Verzeichnis von "/app", nicht darunter.
     */
    public function testFontPfadInNachbarverzeichnisWirft(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        TrainingCertificatePdfOptions::for('/apply/x.ttf', '/app');
    }

    /**
     * Wie oben, mit einem noch laengeren Nachbar-Verzeichnisnamen — deckt ab,
     * dass die Praefix-Kollision nicht nur beim naechsten Buchstaben passiert.
     */
    public function testFontPfadInLaengeremNachbarverzeichnisWirft(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        TrainingCertificatePdfOptions::for('/appliance/fonts/x.ttf', '/app');
    }

    public function testChrootMitTrailingSlashLaesstFontPfadDarunterDurch(): void
    {
        $opts = TrainingCertificatePdfOptions::for('/app/x.ttf', '/app/');

        $this->assertSame(
            '/app',
            $opts['chroot'],
            'Ein trailing Slash im chroot darf das Ergebnis nicht veraendern.'
        );
    }

    public function testDefaultFontUndParserWieImVertragsweg(): void
    {
        $opts = TrainingCertificatePdfOptions::for('/app/resources/fonts/X.ttf', '/app');

        $this->assertTrue($opts['isHtml5ParserEnabled']);
        $this->assertSame('DejaVu Sans', $opts['defaultFont']);
    }

    public function testDpiIst96(): void
    {
        // dpi hat keinen Abgleichspunkt im Vertragsweg (ContractPdfController
        // setzt dpi gar nicht) — deshalb eigener Test statt "wie im
        // Vertragsweg"-Behauptung.
        $opts = TrainingCertificatePdfOptions::for('/app/resources/fonts/X.ttf', '/app');

        $this->assertSame(96, $opts['dpi']);
    }

    // -----------------------------------------------------------------
    // applyTo(): die Schleife, die vorher ohne Falsifikator im Controller stand
    // -----------------------------------------------------------------

    /**
     * Ein Doppelgaenger, der nur mitschreibt.
     *
     * Kein Mock mit Erwartungen, sondern ein Mitschreiber: geprueft wird
     * hinterher der TATSAECHLICHE Zustand. Ein Mock haette hier dieselbe
     * Reihenfolge behauptet, die er selbst vorgibt.
     */
    private function mitschreiber(): object
    {
        return new class {
            /** @var array<string,mixed> */
            public array $gesetzt = [];

            public int $aufrufe = 0;

            public function setOption(string $key, mixed $value): void
            {
                $this->gesetzt[$key] = $value;
                $this->aufrufe++;
            }
        };
    }

    public function testApplyToSchiebtAlleSiebenOptionenDurchSetOption(): void
    {
        $ziel = $this->mitschreiber();

        TrainingCertificatePdfOptions::applyTo(
            $ziel,
            '/app/resources/fonts/X.ttf',
            '/app',
            '/var/www/storage/fonts',
            '/var/www/storage/fonts'
        );

        // Die Zahl ist Teil der Aussage: waere die Schleife abgebrochen oder
        // durch einen einzelnen setOption-Aufruf ersetzt, stimmte sie nicht mehr.
        $this->assertSame(7, $ziel->aufrufe);
        $this->assertSame(
            ['chroot', 'defaultFont', 'dpi', 'fontCache', 'fontDir', 'isHtml5ParserEnabled', 'isRemoteEnabled'],
            self::sortedKeys($ziel->gesetzt)
        );
    }

    public function testApplyToSetztGenauDieUebergebenenFontVerzeichnisse(): void
    {
        // Nicht redundant zum Test darueber: dieser faengt den Fall, dass for()
        // spaeter selbst ein fontDir liefert. Die Vereinigung in applyTo() gibt
        // for() den Vorrang — der uebergebene, zugesicherte Pfad waere dann still
        // wirkungslos, und DomPDF schriebe wieder irgendwohin.
        $ziel = $this->mitschreiber();

        TrainingCertificatePdfOptions::applyTo(
            $ziel,
            '/app/resources/fonts/X.ttf',
            '/app',
            '/app/storage/fonts',
            '/app/storage/font-cache'
        );

        $this->assertSame('/app/storage/fonts', $ziel->gesetzt['fontDir']);
        $this->assertSame('/app/storage/font-cache', $ziel->gesetzt['fontCache']);
    }

    public function testApplyToLiefertGENAUDasZurueckWasEsGesetztHat(): void
    {
        // Reihenfolge und Werte, nicht nur die Schluessel: der Rueckgabewert ist
        // das, was ein Aufrufer loggen oder im Report zeigen wuerde. Wer hier
        // etwas anderes zurueckgibt als er setzt, erzeugt einen Bericht, der die
        // ausgelieferte Engine falsch beschreibt.
        $ziel = $this->mitschreiber();

        $gesetzt = TrainingCertificatePdfOptions::applyTo(
            $ziel,
            '/app/resources/fonts/X.ttf',
            '/app',
            '/app/storage/fonts',
            '/app/storage/fonts'
        );

        $this->assertSame($gesetzt, $ziel->gesetzt);
    }

    public function testApplyToSetztNICHTSWennDerFontPfadAusserhalbDesChrootLiegt(): void
    {
        // Halb konfiguriert waere schlimmer als gar nicht: die Meldung aus for()
        // ist der laute Pfad, aber wenn vorher schon Optionen im Objekt landen,
        // haengt das Ergebnis eines Aufrufers, der die Exception faengt, von der
        // Schleifenreihenfolge ab.
        $ziel = $this->mitschreiber();

        try {
            TrainingCertificatePdfOptions::applyTo(
                $ziel,
                '/anderswo/X.ttf',
                '/app',
                '/app/storage/fonts',
                '/app/storage/fonts'
            );
            self::fail('applyTo() muss den Font-Pfad ausserhalb des chroot melden.');
        } catch (\InvalidArgumentException) {
            $this->assertSame(0, $ziel->aufrufe);
            $this->assertSame([], $ziel->gesetzt);
        }
    }

    private static function sortedKeys(array $a): array
    {
        $k = array_keys($a);
        sort($k);

        return $k;
    }
}
