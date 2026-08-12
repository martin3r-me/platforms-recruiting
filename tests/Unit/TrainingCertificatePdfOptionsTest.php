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

    private static function sortedKeys(array $a): array
    {
        $k = array_keys($a);
        sort($k);

        return $k;
    }
}
