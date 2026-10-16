<?php

namespace Platform\Recruiting\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Models\RecDocumentRecipient;
use Platform\Recruiting\Services\DokumentService;

/**
 * Infos als Text (Spec 2026-10-16 §3.1): keine Datei, Pruefsumme ueber den
 * Text, Empfaenger optional nur mit Automatik-Haken.
 */
final class DokumentInfoBereitstellenTest extends TestCase
{
    use DokumenteHarness;

    private int $maId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->harnessAufsetzen();
        $this->maId = (int) $this->anstellung()->id;
    }

    protected function tearDown(): void
    {
        $this->harnessAbbauen();
        parent::tearDown();
    }

    public function test_info_wird_ohne_datei_mit_textpruefsumme_angelegt(): void
    {
        $service = new DokumentService($this->speicher);
        $r = $service->bereitstellenText(3, ['title' => 'Willkommen', 'auto_neue_mitarbeiter' => false], "# Hallo\n\nText", [$this->maId], 7);

        $d = $r['dokument'];
        $this->assertSame('info', $d->category);
        $this->assertSame('none', $d->action);
        $this->assertSame("# Hallo\n\nText", $d->content_markdown);
        $this->assertSame(hash('sha256', "# Hallo\n\nText"), $d->file_sha256);
        $this->assertNull($d->stored_path);
        $this->assertNull($d->original_filename);
        $this->assertNull($d->file_size);
        $this->assertNotNull($d->published_at);
        $this->assertFalse($d->auto_neue_mitarbeiter);
        $this->assertSame(1, $r['empfaenger']);
        $this->assertFalse($r['versand_noetig'], 'Aktion none: keine WhatsApp-Pflicht');
        $this->assertSame(1, RecDocumentRecipient::where('rec_document_id', $d->id)->count());
    }

    public function test_leerer_text_wird_abgelehnt(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new DokumentService($this->speicher))->bereitstellenText(3, ['title' => 'X'], "  \n ", [$this->maId], 7);
    }

    public function test_ohne_empfaenger_nur_mit_automatik_haken_erlaubt(): void
    {
        $service = new DokumentService($this->speicher);
        try {
            $service->bereitstellenText(3, ['title' => 'X', 'auto_neue_mitarbeiter' => false], 'Text', [], 7);
            $this->fail('ohne Empfaenger und ohne Haken muss abgelehnt werden');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('Empfänger', $e->getMessage());
        }
        $r = $service->bereitstellenText(3, ['title' => 'X', 'auto_neue_mitarbeiter' => true], 'Text', [], 7);
        $this->assertSame(0, $r['empfaenger']);
        $this->assertTrue($r['dokument']->auto_neue_mitarbeiter);
    }

    public function test_text_laenger_als_20000_zeichen_wird_abgelehnt(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new DokumentService($this->speicher))->bereitstellenText(3, ['title' => 'X'], str_repeat('a', 20001), [$this->maId], 7);
    }

    public function test_fremde_und_unbekannte_ids_werden_stumm_uebergangen(): void
    {
        $r = (new DokumentService($this->speicher))->bereitstellenText(3, ['title' => 'X'], 'Text', [$this->maId, 999999], 7);
        $this->assertSame(1, $r['empfaenger']);
    }

    public function test_pdf_weg_lehnt_die_text_kategorie_ab(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new DokumentService($this->speicher))->bereitstellen(3, ['title' => 'X', 'category' => 'info', 'action' => 'none'], "%PDF-1.4\n%%EOF", 'a.pdf', [$this->maId], null, 7);
    }
}
