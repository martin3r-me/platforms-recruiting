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

    public function testDpiUndParserWieImVertragsweg(): void
    {
        $opts = TrainingCertificatePdfOptions::for('/app/resources/fonts/X.ttf', '/app');

        $this->assertSame(96, $opts['dpi']);
        $this->assertTrue($opts['isHtml5ParserEnabled']);
        $this->assertSame('DejaVu Sans', $opts['defaultFont']);
    }

    private static function sortedKeys(array $a): array
    {
        $k = array_keys($a);
        sort($k);

        return $k;
    }
}
