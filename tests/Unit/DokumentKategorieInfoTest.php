<?php

namespace Platform\Recruiting\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Support\DokumentKategorie;

final class DokumentKategorieInfoTest extends TestCase
{
    public function test_info_ist_eine_kategorie_mit_aktion_none_und_textinhalt(): void
    {
        $this->assertTrue(DokumentKategorie::exists(DokumentKategorie::INFO));
        $this->assertSame('Info / News', DokumentKategorie::label(DokumentKategorie::INFO));
        $this->assertSame(DokumentKategorie::AKTION_NONE, DokumentKategorie::defaultAktion(DokumentKategorie::INFO));
        $this->assertTrue(DokumentKategorie::istText(DokumentKategorie::INFO));
        foreach ([DokumentKategorie::CONTRACT, DokumentKategorie::INSTRUCTION, DokumentKategorie::OTHER] as $c) {
            $this->assertFalse(DokumentKategorie::istText($c), $c);
        }
    }
}
