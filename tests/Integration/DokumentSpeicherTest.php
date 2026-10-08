<?php

namespace Platform\Recruiting\Tests\Integration;

use Illuminate\Filesystem\FilesystemAdapter;
use League\Flysystem\Filesystem as Flysystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Services\DokumentSpeicher;

final class DokumentSpeicherTest extends TestCase
{
    private string $root;
    private DokumentSpeicher $speicher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir() . '/rec-dokumente-' . bin2hex(random_bytes(4));
        mkdir($this->root, 0777, true);
        $adapter = new LocalFilesystemAdapter($this->root);
        $files = new FilesystemAdapter(new Flysystem($adapter), $adapter, ['root' => $this->root]);
        $this->speicher = new DokumentSpeicher($files, 'test-local');
    }

    protected function tearDown(): void
    {
        foreach (glob($this->root . '/recruiting/dokumente/*/*') ?: [] as $f) {
            unlink($f);
        }
        @rmdir($this->root . '/recruiting/dokumente/3');
        @rmdir($this->root . '/recruiting/dokumente');
        @rmdir($this->root . '/recruiting');
        @rmdir($this->root);
        parent::tearDown();
    }

    public function test_ablegen_schreibt_datei_und_pruefsumme(): void
    {
        $inhalt = "%PDF-1.4\nHallo";
        $e = $this->speicher->ablegen(3, 'abc-123', $inhalt);

        $this->assertSame('test-local', $e['disk']);
        $this->assertSame('recruiting/dokumente/3/abc-123.pdf', $e['stored_path']);
        $this->assertSame(hash('sha256', $inhalt), $e['sha256']);
        $this->assertSame(strlen($inhalt), $e['size']);
        $this->assertFileExists($this->root . '/' . $e['stored_path']);
        $this->assertSame($inhalt, $this->speicher->inhalt($e['stored_path']));
    }

    public function test_pruefsumme_erkennt_veraenderte_bytes(): void
    {
        $e = $this->speicher->ablegen(3, 'abc-124', "%PDF-1.4\nOriginal");
        file_put_contents($this->root . '/' . $e['stored_path'], "%PDF-1.4\nManipuliert");

        $this->assertNotSame($e['sha256'], $this->speicher->pruefsumme($e['stored_path']));
    }

    public function test_fehlende_datei_liefert_null(): void
    {
        $this->assertNull($this->speicher->inhalt('recruiting/dokumente/3/gibt-es-nicht.pdf'));
        $this->assertNull($this->speicher->pruefsumme('recruiting/dokumente/3/gibt-es-nicht.pdf'));
    }
}
