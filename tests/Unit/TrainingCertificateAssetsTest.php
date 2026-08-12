<?php

namespace Platform\Recruiting\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Support\TrainingCertificateAssets;

class TrainingCertificateAssetsTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/zert-assets-' . getmypid() . '-' . uniqid();
        mkdir($this->tmp . '/fonts', 0777, true);
        mkdir($this->tmp . '/images/certificates', 0777, true);
    }

    protected function tearDown(): void
    {
        // Aufraeumen, damit Testlaeufe sich nicht gegenseitig sehen.
        foreach (['fonts/Oswald-SemiBold.ttf', 'images/certificates/logo.png',
                  'images/certificates/headline-zertifikat.png',
                  'images/certificates/signature-block.png'] as $f) {
            @unlink($this->tmp . '/' . $f);
        }
        @rmdir($this->tmp . '/images/certificates');
        @rmdir($this->tmp . '/images');
        @rmdir($this->tmp . '/fonts');
        @rmdir($this->tmp);
    }

    private function write(string $relative, string $bytes = 'PNGDATA'): void
    {
        file_put_contents($this->tmp . '/' . $relative, $bytes);
    }

    private function writeAll(): void
    {
        $this->write('fonts/Oswald-SemiBold.ttf', 'TTF');
        $this->write('images/certificates/logo.png');
        $this->write('images/certificates/headline-zertifikat.png');
        $this->write('images/certificates/signature-block.png');
    }

    public function testAlleAssetsVorhanden(): void
    {
        $this->writeAll();

        $a = TrainingCertificateAssets::resolve($this->tmp);

        $this->assertSame([], $a['missing']);
        $this->assertSame($this->tmp . '/fonts/Oswald-SemiBold.ttf', $a['font']);
        $this->assertSame('data:image/png;base64,' . base64_encode('PNGDATA'), $a['logo']);
        $this->assertNotNull($a['headline']);
        $this->assertNotNull($a['signature']);
    }

    public function testFehlendesBildWirdNullUndGemeldet(): void
    {
        $this->writeAll();
        unlink($this->tmp . '/images/certificates/headline-zertifikat.png');

        $a = TrainingCertificateAssets::resolve($this->tmp);

        $this->assertNull($a['headline']);
        $this->assertSame(['images/certificates/headline-zertifikat.png'], $a['missing']);
        // Die uebrigen bleiben unberuehrt — ein fehlendes Bild ist kein Absturz.
        $this->assertNotNull($a['logo']);
    }

    public function testFehlendeSchriftWirdGemeldetAberDerPfadBleibt(): void
    {
        $this->writeAll();
        unlink($this->tmp . '/fonts/Oswald-SemiBold.ttf');

        $a = TrainingCertificateAssets::resolve($this->tmp);

        // Der Pfad muss trotzdem kommen: das @font-face braucht ihn, und der
        // chroot-Check in TrainingCertificatePdfOptions prueft ihn.
        $this->assertSame($this->tmp . '/fonts/Oswald-SemiBold.ttf', $a['font']);
        $this->assertSame(['fonts/Oswald-SemiBold.ttf'], $a['missing']);
    }

    public function testAllesFehltErgibtVierMeldungenInFesterReihenfolge(): void
    {
        $a = TrainingCertificateAssets::resolve($this->tmp);

        $this->assertSame([
            'fonts/Oswald-SemiBold.ttf',
            'images/certificates/logo.png',
            'images/certificates/headline-zertifikat.png',
            'images/certificates/signature-block.png',
        ], $a['missing']);
    }

    public function testKeysSindImmerVollstaendig(): void
    {
        $a = TrainingCertificateAssets::resolve($this->tmp);
        $keys = array_keys($a);
        sort($keys);

        $this->assertSame(['font', 'headline', 'logo', 'missing', 'signature'], $keys);
    }
}
