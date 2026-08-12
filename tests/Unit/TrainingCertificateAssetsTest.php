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

    public function testLeeresBildWirdAlsFehlendGemeldet(): void
    {
        // Ein lesbares 0-Byte-Bild (abgebrochener Kopiervorgang, fehlgeschlagener
        // Deploy, voller Datentraeger, nicht geholtes LFS-Objekt) besteht den
        // is_file/is_readable-Guard, ergaebe aber einen leeren Data-URI, der im
        // PDF nichts rendert -- und "missing" ist der einzige Kanal, ueber den
        // der Controller (Logging) und der Editor (Anzeige) davon erfahren.
        // Deshalb muss ein 0-Byte-Bild wie eine fehlende Datei behandelt werden.
        $this->writeAll();
        $this->write('images/certificates/logo.png', '');

        $a = TrainingCertificateAssets::resolve($this->tmp);

        $this->assertNull($a['logo'], 'Ein 0-Byte-Bild darf keinen (leeren) Data-URI liefern.');
        $this->assertSame(
            ['images/certificates/logo.png'],
            $a['missing'],
            'Ein 0-Byte-Bild muss in "missing" auftauchen -- sonst erfaehrt niemand davon.'
        );
    }

    public function testLeereSchriftWirdGemeldetAberDerPfadBleibt(): void
    {
        // Dieselbe Fehlerklasse wie beim Bild, aber fuer die Schrift: eine
        // lesbare 0-Byte-TTF-Datei besteht is_file/is_readable, ist aber keine
        // brauchbare Schrift. Anders als beim Bild bleibt der Pfad trotzdem
        // gesetzt -- das @font-face braucht ihn, und TrainingCertificatePdfOptions
        // prueft ihn gegen den chroot. Diese Asymmetrie (Pfad bleibt, Bild wird
        // null) ist Absicht und darf hier nicht verschwinden.
        $this->writeAll();
        $this->write('fonts/Oswald-SemiBold.ttf', '');

        $a = TrainingCertificateAssets::resolve($this->tmp);

        $this->assertSame(
            $this->tmp . '/fonts/Oswald-SemiBold.ttf',
            $a['font'],
            'Der Font-Pfad muss trotz 0-Byte-Datei zurueckkommen (Asymmetrie zu Bildern ist Absicht).'
        );
        $this->assertSame(
            ['fonts/Oswald-SemiBold.ttf'],
            $a['missing'],
            'Eine 0-Byte-Schriftdatei muss in "missing" auftauchen -- sonst erfaehrt niemand davon.'
        );
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

    public function testAllesLeerErgibtVierMeldungenInDerselbenReihenfolge(): void
    {
        // Der Test darueber laeuft gegen ein leeres Verzeichnis; dort greift in
        // jedem Guard schon der erste Teilausdruck (!is_file). Die Reihenfolge
        // waere damit nur fuer den Absent-Fall festgenagelt. Wer kuenftig den
        // Font-Check hinter die IMAGES-Schleife zieht, braeche dann nur den
        // Absent-Fall rot -- der Leer-Fall bliebe gruen, obwohl "missing" auch
        // dort in falscher Reihenfolge kaeme. Deshalb dieselbe Assertion noch
        // einmal fuer vier vorhandene, aber leere Dateien: hier laufen die
        // Guards in ihren zweiten Zweig (filesize()===0 bzw. $binary===''),
        // und die Reihenfolge muss trotzdem identisch sein.
        $this->write('fonts/Oswald-SemiBold.ttf', '');
        $this->write('images/certificates/logo.png', '');
        $this->write('images/certificates/headline-zertifikat.png', '');
        $this->write('images/certificates/signature-block.png', '');

        $a = TrainingCertificateAssets::resolve($this->tmp);

        $this->assertSame([
            'fonts/Oswald-SemiBold.ttf',
            'images/certificates/logo.png',
            'images/certificates/headline-zertifikat.png',
            'images/certificates/signature-block.png',
        ], $a['missing'], 'Die Reihenfolge in "missing" muss im Leer-Fall dieselbe sein wie im Absent-Fall.');
    }

    public function testKeysSindImmerVollstaendig(): void
    {
        $a = TrainingCertificateAssets::resolve($this->tmp);
        $keys = array_keys($a);
        sort($keys);

        $this->assertSame(['font', 'headline', 'logo', 'missing', 'signature'], $keys);
    }
}
