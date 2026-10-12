<?php

namespace Platform\Recruiting\Tests\Unit\Anleitungen;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Support\Anleitungen\AnleitungKatalog;

class AnleitungKatalogTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/anleitungen-' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/bilder/dispo-a', 0777, true);
        $this->datei('dispo-a', 'Dispo', 'Zeta', '[recruiting.dispo.settings]');
        $this->datei('schulung-b', 'Schulungen', 'Beta', '[recruiting.dispo.settings, recruiting.interview-schedule.index]');
        $this->datei('dispo-c', 'Dispo', 'Alpha', '');
        file_put_contents($this->dir . '/kaputt.md', "kein kopf\n");
        file_put_contents($this->dir . '/README.md', "# Readme\n");
        file_put_contents($this->dir . '/Falscher_Name.md', "---\ntitel: x\n---\n");
        file_put_contents($this->dir . '/bilder/dispo-a/A_liste.png', 'png');
        file_put_contents($this->dir . '/geheim.png', 'geheim');
        AnleitungKatalog::vergessen();
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dir));
        AnleitungKatalog::vergessen();
    }

    private function datei(string $slug, string $bereich, string $titel, string $seiten): void
    {
        file_put_contents($this->dir . "/{$slug}.md", "---\ntitel: {$titel}\nbereich: {$bereich}\nstand: 2026-10-12\nseiten: {$seiten}\n---\n## K | T\n\n1. a\n");
    }

    public function test_readme_wird_ignoriert_und_kaputte_stehen_am_ende(): void
    {
        $k = new AnleitungKatalog($this->dir);

        $this->assertSame(
            ['schulung-b', 'dispo-c', 'dispo-a', 'Falscher_Name', 'kaputt'],
            array_map(fn ($a) => $a->slug, $k->alle())
        );
    }

    public function test_nach_bereich_in_fester_reihenfolge_ohne_kaputte(): void
    {
        $g = (new AnleitungKatalog($this->dir))->nachBereich();

        $this->assertSame(['Schulungen', 'Dispo'], array_keys($g));
        $this->assertSame(['dispo-c', 'dispo-a'], array_map(fn ($a) => $a->slug, $g['Dispo']));
    }

    public function test_kaputte_kippen_nichts_und_nennen_den_grund(): void
    {
        $f = (new AnleitungKatalog($this->dir))->fehlerhaft();

        $this->assertSame(['Falscher_Name', 'kaputt'], array_map(fn ($a) => $a->slug, $f));
        $this->assertStringContainsString('Dateiname', $f[0]->fehler);
        $this->assertStringContainsString('Kopf fehlt', $f[1]->fehler);
    }

    public function test_finde_nur_mit_gueltigem_slug(): void
    {
        $k = new AnleitungKatalog($this->dir);

        $this->assertSame('Zeta', $k->finde('dispo-a')?->titel);
        $this->assertNull($k->finde('gibts-nicht'));
        $this->assertNull($k->finde('../dispo-a'));
        $this->assertNull($k->finde('README'));
    }

    public function test_fuer_route(): void
    {
        $k = new AnleitungKatalog($this->dir);

        $this->assertSame(['schulung-b', 'dispo-a'], array_map(fn ($a) => $a->slug, $k->fuerRoute('recruiting.dispo.settings')));
        $this->assertSame([], $k->fuerRoute('recruiting.dashboard'));
    }

    public function test_bildpfad_liefert_nur_dateien_aus_dem_eigenen_ordner(): void
    {
        $k = new AnleitungKatalog($this->dir);

        $this->assertSame(realpath($this->dir . '/bilder/dispo-a/A_liste.png'), $k->bildPfad('dispo-a', 'A_liste.png'));
        $this->assertNull($k->bildPfad('dispo-a', 'fehlt.png'));
        $this->assertNull($k->bildPfad('dispo-a', '../../geheim.png'));
        $this->assertNull($k->bildPfad('dispo-a', '..%2F..%2Fgeheim.png'));
        $this->assertNull($k->bildPfad('dispo-a', 'A_liste.php'));
        $this->assertNull($k->bildPfad('../bilder', 'A_liste.png'));
        $this->assertNull($k->bildPfad('schulung-b', 'A_liste.png'));
    }

    public function test_bildpfad_folgt_keinem_symlink_nach_draussen(): void
    {
        symlink($this->dir . '/geheim.png', $this->dir . '/bilder/dispo-a/link.png');

        $this->assertNull((new AnleitungKatalog($this->dir))->bildPfad('dispo-a', 'link.png'));
    }
}
