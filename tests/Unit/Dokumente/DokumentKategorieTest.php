<?php

namespace Platform\Recruiting\Tests\Unit\Dokumente;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Support\DokumentKategorie;

final class DokumentKategorieTest extends TestCase
{
    public function test_sechs_kategorien_mit_label(): void
    {
        $this->assertSame(['contract', 'instruction', 'form', 'payslip', 'certificate', 'other'], DokumentKategorie::codes());
        $this->assertSame('Vertrag / Zusatzvereinbarung', DokumentKategorie::label('contract'));
        $this->assertSame('Belehrung / Unterweisung', DokumentKategorie::label('instruction'));
        $this->assertCount(6, DokumentKategorie::labels());
    }

    public function test_default_aktion_je_kategorie(): void
    {
        $this->assertSame('sign', DokumentKategorie::defaultAktion('contract'));
        $this->assertSame('acknowledge', DokumentKategorie::defaultAktion('instruction'));
        $this->assertSame('acknowledge', DokumentKategorie::defaultAktion('form'));
        $this->assertSame('none', DokumentKategorie::defaultAktion('payslip'));
        $this->assertSame('none', DokumentKategorie::defaultAktion('certificate'));
        $this->assertSame('none', DokumentKategorie::defaultAktion('other'));
    }

    public function test_unbekannte_kategorie_wirft(): void
    {
        $this->assertFalse(DokumentKategorie::exists('lohn'));
        $this->expectException(\InvalidArgumentException::class);
        DokumentKategorie::defaultAktion('lohn');
    }

    public function test_aktionen_und_handlungsbedarf(): void
    {
        $this->assertSame(['none', 'acknowledge', 'sign'], array_keys(DokumentKategorie::aktionen()));
        $this->assertTrue(DokumentKategorie::aktionExists('sign'));
        $this->assertFalse(DokumentKategorie::aktionExists('read'));
        $this->assertFalse(DokumentKategorie::brauchtHandlung('none'));
        $this->assertTrue(DokumentKategorie::brauchtHandlung('acknowledge'));
        $this->assertTrue(DokumentKategorie::brauchtHandlung('sign'));
    }
}
