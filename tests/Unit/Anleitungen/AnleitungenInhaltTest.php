<?php

namespace Platform\Recruiting\Tests\Unit\Anleitungen;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Support\Anleitungen\AnleitungKatalog;

/** Waechter ueber die echten Dateien in docs/anleitungen/ — faengt Tippfehler vor dem Merge. */
class AnleitungenInhaltTest extends TestCase
{
    protected function setUp(): void
    {
        AnleitungKatalog::vergessen();
    }

    public function test_es_gibt_die_ersten_drei_anleitungen(): void
    {
        $slugs = array_map(fn ($a) => $a->slug, AnleitungKatalog::standard()->alle());

        foreach (['schulungstermine-umplanen', 'schulungsnachbereitung-vertragsdaten', 'dispo-absage-erkennung'] as $slug) {
            $this->assertContains($slug, $slugs);
        }
    }

    public function test_alle_dateien_sind_lesbar(): void
    {
        foreach (AnleitungKatalog::standard()->alle() as $a) {
            $this->assertNull($a->fehler, "{$a->slug}: {$a->fehler}");
        }
    }

    public function test_jede_genannte_seite_ist_eine_echte_route(): void
    {
        $routen = (string) file_get_contents(dirname(__DIR__, 3) . '/routes/web.php');

        foreach (AnleitungKatalog::standard()->alle() as $a) {
            foreach ($a->seiten as $route) {
                $this->assertStringContainsString("->name('{$route}')", $routen, "{$a->slug}: Route {$route} gibt es nicht");
            }
        }
    }

    public function test_jedes_bild_hat_eine_datei_oder_ein_rezept(): void
    {
        $k = AnleitungKatalog::standard();
        foreach ($k->alle() as $a) {
            foreach ($a->folien as $f) {
                if ($f->bild === null) {
                    continue;
                }
                $this->assertTrue(
                    $k->bildPfad($a->slug, $f->bild->datei) !== null || isset($f->bild->rezept['seite']) || isset($f->bild->rezept['manuell']),
                    "{$a->slug} Folie {$f->nr}: {$f->bild->datei} hat weder Datei noch Rezept"
                );
            }
        }
    }
}
