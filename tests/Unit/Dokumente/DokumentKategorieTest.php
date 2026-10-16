<?php

namespace Platform\Recruiting\Tests\Unit\Dokumente;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Support\DokumentKategorie;

final class DokumentKategorieTest extends TestCase
{
    public function test_sieben_kategorien_mit_label(): void
    {
        $this->assertSame(['contract', 'instruction', 'form', 'payslip', 'certificate', 'other', 'info'], DokumentKategorie::codes());
        $this->assertSame('Vertrag / Zusatzvereinbarung', DokumentKategorie::label('contract'));
        $this->assertSame('Belehrung / Unterweisung', DokumentKategorie::label('instruction'));
        $this->assertCount(7, DokumentKategorie::labels());
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

    public function test_datei_formulare_bieten_keine_text_kategorie_an(): void
    {
        $datei = DokumentKategorie::labelsFuerDatei();
        $this->assertSame(['contract', 'instruction', 'form', 'payslip', 'certificate', 'other'], array_keys($datei));
        $this->assertSame('Vertrag / Zusatzvereinbarung', $datei['contract']);
        foreach (array_keys($datei) as $code) {
            $this->assertFalse(DokumentKategorie::istText($code));
        }

        // Alle drei PDF-Formulare (Dokumente-Seite, Akte, Veranstaltung) nutzen die gefilterte Liste (Schlussreview M-2).
        $views = dirname(__DIR__, 3) . '/resources/views/livewire/';
        foreach (['employees/documents.blade.php', 'employees/show.blade.php', 'dispo/events/show.blade.php'] as $datei) {
            $blade = (string) file_get_contents($views . $datei);
            $this->assertStringContainsString('DokumentKategorie::labelsFuerDatei()', $blade, $datei);
            $this->assertStringNotContainsString('DokumentKategorie::labels()', $blade, $datei);
        }
    }
}
