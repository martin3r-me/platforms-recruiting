<?php

namespace Platform\Recruiting\Tests\Integration;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Models\RecDocument;
use Platform\Recruiting\Models\RecDocumentRecipient;
use Platform\Recruiting\Services\DokumentUnterschrift;

final class DokumentUnterschriftTest extends TestCase
{
    use DokumenteHarness;

    private const PDF = "%PDF-1.4\nHallo";
    private const SIG = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

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

    private function zustellung(string $aktion = 'sign'): RecDocumentRecipient
    {
        $e = $this->anstellung();
        $ablage = $this->speicher->ablegen(3, 'u-1', self::PDF);
        $d = RecDocument::create([
            'team_id' => 3, 'title' => 'T', 'category' => 'contract', 'action' => $aktion,
            'disk' => $ablage['disk'], 'stored_path' => $ablage['stored_path'], 'original_filename' => 'x.pdf',
            'file_sha256' => $ablage['sha256'], 'file_size' => $ablage['size'],
        ]);

        return RecDocumentRecipient::create(['team_id' => 3, 'rec_document_id' => $d->id, 'rec_employee_id' => $e->id]);
    }

    private function dienst(): DokumentUnterschrift
    {
        return new DokumentUnterschrift($this->speicher);
    }

    public function test_oeffnen_setzt_gesehen_genau_einmal(): void
    {
        $z = $this->zustellung();
        $this->dienst()->oeffnen($z);
        $erstes = $z->fresh()->first_viewed_at;
        $this->assertNotNull($erstes);

        DB::table('rec_document_recipients')->where('id', $z->id)->update(['first_viewed_at' => '2026-01-01 00:00:00']);
        $this->dienst()->oeffnen($z->fresh());

        $this->assertSame('2026-01-01 00:00:00', $z->fresh()->first_viewed_at->format('Y-m-d H:i:s'));
    }

    public function test_bestaetigen_braucht_oeffnen_und_haken(): void
    {
        $z = $this->zustellung('acknowledge');

        $this->assertSame('Bitte zuerst das Dokument öffnen.', $this->dienst()->bestaetigen($z, true, true));
        $this->dienst()->oeffnen($z);
        $this->assertSame('Bitte bestätige, dass du das Dokument gelesen hast.', $this->dienst()->bestaetigen($z->fresh(), false, true));
        $this->assertSame('Bitte bestätigen Sie, dass Sie das Dokument gelesen haben.', $this->dienst()->bestaetigen($z->fresh(), false, false));
        $this->assertNull($z->fresh()->acknowledged_at);

        $this->assertNull($this->dienst()->bestaetigen($z->fresh(), true, true));
        $this->assertNotNull($z->fresh()->acknowledged_at);
    }

    public function test_bestaetigen_ist_idempotent(): void
    {
        $z = $this->zustellung('acknowledge');
        $this->dienst()->oeffnen($z);
        $this->dienst()->bestaetigen($z->fresh(), true, true);
        DB::table('rec_document_recipients')->where('id', $z->id)->update(['acknowledged_at' => '2026-01-01 00:00:00']);

        $this->assertNull($this->dienst()->bestaetigen($z->fresh(), true, true));
        $this->assertSame('2026-01-01 00:00:00', $z->fresh()->acknowledged_at->format('Y-m-d H:i:s'));
    }

    public function test_unterschreiben_speichert_bild_und_zeitpunkte(): void
    {
        $z = $this->zustellung();
        $this->dienst()->oeffnen($z);

        $this->assertNull($this->dienst()->unterschreiben($z->fresh(), true, self::SIG, true));

        $z = $z->fresh();
        $this->assertNotNull($z->signed_at);
        $this->assertNotNull($z->acknowledged_at, 'Unterschrift schliesst Kenntnisnahme ein');
        $this->assertSame(self::SIG, $z->signature_data);
    }

    public function test_leere_unterschrift_wird_abgelehnt(): void
    {
        $z = $this->zustellung();
        $this->dienst()->oeffnen($z);

        $this->assertSame('Bitte unterschreibe im Feld.', $this->dienst()->unterschreiben($z->fresh(), true, '', true));
        $this->assertSame('Bitte unterschreibe im Feld.', $this->dienst()->unterschreiben($z->fresh(), true, 'data:text/plain;base64,QQ==', true));
        $this->assertSame('Bitte unterschreibe im Feld.', $this->dienst()->unterschreiben($z->fresh(), true, 'data:image/png;base64,', true));
        $this->assertNull($z->fresh()->signed_at);
    }

    public function test_manipulierte_datei_verhindert_unterschrift(): void
    {
        $z = $this->zustellung();
        $this->dienst()->oeffnen($z);
        file_put_contents($this->root . '/' . $z->document->stored_path, "%PDF-1.4\nManipuliert");

        $fehler = $this->dienst()->unterschreiben($z->fresh(), true, self::SIG, true);

        $this->assertSame('Das Dokument kann gerade nicht unterschrieben werden. Bitte melde dich bei uns.', $fehler);
        $this->assertNull($z->fresh()->signed_at);
        $this->assertNull($z->fresh()->signature_data);
    }

    public function test_fehlende_datei_verhindert_unterschrift(): void
    {
        $z = $this->zustellung();
        $this->dienst()->oeffnen($z);
        unlink($this->root . '/' . $z->document->stored_path);

        $this->assertNotNull($this->dienst()->unterschreiben($z->fresh(), true, self::SIG, true));
        $this->assertNull($z->fresh()->signed_at);
    }

    public function test_zweite_unterschrift_ueberschreibt_nicht(): void
    {
        $z = $this->zustellung();
        $this->dienst()->oeffnen($z);
        $this->dienst()->unterschreiben($z->fresh(), true, self::SIG, true);
        DB::table('rec_document_recipients')->where('id', $z->id)->update(['signed_at' => '2026-01-01 00:00:00', 'signature_data' => 'ERSTE']);

        $this->assertNull($this->dienst()->unterschreiben($z->fresh(), true, self::SIG, true));
        $z = $z->fresh();
        $this->assertSame('2026-01-01 00:00:00', $z->signed_at->format('Y-m-d H:i:s'));
        $this->assertSame('ERSTE', $z->signature_data);
    }

    public function test_zurueckgezogen_wird_freundlich_abgewiesen(): void
    {
        $z = $this->zustellung();
        $this->dienst()->oeffnen($z);
        DB::table('rec_document_recipients')->where('id', $z->id)->update(['withdrawn_at' => '2026-10-09 10:00:00']);

        $this->assertSame('Dieses Dokument wurde zurückgezogen.', $this->dienst()->unterschreiben($z->fresh(), true, self::SIG, true));
        $this->assertSame('Dieses Dokument wurde zurückgezogen.', $this->dienst()->bestaetigen($z->fresh(), true, true));
        $this->assertNull($z->fresh()->signed_at);
    }

    public function test_unterschrift_schreibt_nie_auf_rec_employees(): void
    {
        $z = $this->zustellung();
        $vorher = DB::table('rec_employees')->where('id', $z->rec_employee_id)->value('zas_changed_at');
        $this->dienst()->oeffnen($z);
        $this->dienst()->unterschreiben($z->fresh(), true, self::SIG, true);
        $this->assertSame($vorher, DB::table('rec_employees')->where('id', $z->rec_employee_id)->value('zas_changed_at'));
    }
}
