<?php

namespace Platform\Recruiting\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Livewire\Public\PortalShell;
use Platform\Recruiting\Models\RecDocument;
use Platform\Recruiting\Models\RecDocumentRecipient;
use Platform\Recruiting\Services\OffenePunkte;
use Platform\Recruiting\Support\TriggerRegeln;

/**
 * Ein offenes Dokument ist ein offener Punkt (Spec §5.2, §6) — und alle
 * Verbraucher der Punkteliste kommen mit einem Code ohne Katalogeintrag aus.
 */
final class OffenePunkteDokumenteTest extends TestCase
{
    use DokumenteHarness;

    protected function setUp(): void
    {
        parent::setUp();
        $this->harnessAufsetzen();
    }

    protected function tearDown(): void
    {
        $this->harnessAbbauen();
        parent::tearDown();
    }

    public function test_offenes_dokument_ist_genau_ein_punkt_ohne_sperre(): void
    {
        $e = $this->anstellung(['is_eu_citizen' => true]);
        $d = RecDocument::create([
            'team_id' => 3, 'title' => 'Hausordnung', 'category' => 'instruction', 'action' => 'acknowledge',
            'disk' => 'test-local', 'stored_path' => 'x.pdf', 'original_filename' => 'x.pdf',
            'file_sha256' => str_repeat('a', 64), 'file_size' => 1,
        ]);
        $z = RecDocumentRecipient::create(['team_id' => 3, 'rec_document_id' => $d->id, 'rec_employee_id' => $e->id]);

        $stand = (new OffenePunkte())->fuer($e, '2026-10-09');

        $dokPunkte = array_values(array_filter($stand['punkte'], fn ($p) => str_starts_with($p['code'], 'dokument:')));
        $this->assertCount(1, $dokPunkte);
        $this->assertSame('dokument:' . $z->id, $dokPunkte[0]['code']);
        $this->assertFalse($dokPunkte[0]['ko']);
        $this->assertFalse($stand['gesperrt']);

        // Verbraucher: Signatur (Einsatz-Pruefung) und Dekoration (Portal) tragen den Code.
        $this->assertSame(64, strlen(TriggerRegeln::signatur($stand['punkte'])));
        $mitSaetzen = PortalShell::punkteMitSaetzen($dokPunkte, []);
        $this->assertSame('Lesen und bestätigen', $mitSaetzen[0]['text']);
        $this->assertSame('crit', $mitSaetzen[0]['punkt']);
    }

    public function test_fuer_trigger_laesst_frisch_gemeldete_aus_und_ist_sonst_gleich(): void
    {
        $e = $this->anstellung(['is_eu_citizen' => true]);
        $d = RecDocument::create([
            'team_id' => 3, 'title' => 'Hausordnung', 'category' => 'instruction', 'action' => 'acknowledge',
            'disk' => 'test-local', 'stored_path' => 'x.pdf', 'original_filename' => 'x.pdf',
            'file_sha256' => str_repeat('a', 64), 'file_size' => 1,
        ]);
        $z = RecDocumentRecipient::create(['team_id' => 3, 'rec_document_id' => $d->id, 'rec_employee_id' => $e->id]);
        \Illuminate\Support\Facades\DB::table('rec_document_recipients')->where('id', $z->id)->update(['notified_at' => '2026-10-09 08:00:00']);

        $portal = (new OffenePunkte())->fuer($e, '2026-10-09');
        $trigger = (new OffenePunkte())->fuerTrigger($e, '2026-10-09');

        $this->assertContains('dokument:' . $z->id, array_column($portal['punkte'], 'code'));
        $this->assertNotContains('dokument:' . $z->id, array_column($trigger['punkte'], 'code'));
        $this->assertSame($portal['einsatz'], $trigger['einsatz']);
        $this->assertSame($portal['gesperrt'], $trigger['gesperrt']);

        \Illuminate\Support\Facades\DB::table('rec_document_recipients')->where('id', $z->id)->update(['notified_at' => '2026-10-01 08:00:00']);
        $this->assertContains('dokument:' . $z->id, array_column((new OffenePunkte())->fuerTrigger($e, '2026-10-09')['punkte'], 'code'));
    }

    public function test_nur_ablegen_erzeugt_keinen_punkt(): void
    {
        $e = $this->anstellung(['is_eu_citizen' => true]);
        $d = RecDocument::create([
            'team_id' => 3, 'title' => 'Lohn', 'category' => 'payslip', 'action' => 'none',
            'disk' => 'test-local', 'stored_path' => 'x.pdf', 'original_filename' => 'x.pdf',
            'file_sha256' => str_repeat('a', 64), 'file_size' => 1,
        ]);
        RecDocumentRecipient::create(['team_id' => 3, 'rec_document_id' => $d->id, 'rec_employee_id' => $e->id]);

        $stand = (new OffenePunkte())->fuer($e, '2026-10-09');

        $this->assertSame([], array_filter($stand['punkte'], fn ($p) => str_starts_with($p['code'], 'dokument:')));
    }
}
