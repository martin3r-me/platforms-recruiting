<?php

namespace Platform\Recruiting\Tests\Integration;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Models\RecDocument;
use Platform\Recruiting\Models\RecDocumentRecipient;
use Platform\Recruiting\Models\RecEmployee;
use Platform\Recruiting\Services\Comms\DokumentHinweisSender;
use Platform\Recruiting\Services\DokumentAutoZustellung;
use Platform\Recruiting\Services\DokumentService;
use Platform\Recruiting\Support\DokumentAkteZeilen;

/**
 * Infos als Text (Spec 2026-10-16 §3.1): keine Datei, Pruefsumme ueber den
 * Text, Empfaenger optional nur mit Automatik-Haken. Gewaehlte Empfaenger
 * bekommen die Portal-WhatsApp (§7), automatisch zugestellte nie.
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
        $this->assertTrue($r['versand_noetig'], 'Info mit Empfaengern: Portal-WhatsApp wie bei einer PDF (Spec §7)');
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
        $this->assertFalse($r['versand_noetig'], 'nur Automatik: niemand anzuschreiben');
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

    /** Sender ohne Meta: merkt sich, wen er anschreiben sollte. */
    private function attrappe(): DokumentHinweisSender
    {
        return new class extends DokumentHinweisSender {
            /** @var list<int> */
            public array $angeschrieben = [];

            public function sende(RecEmployee $employee): string
            {
                $this->angeschrieben[] = (int) $employee->id;

                return self::STATUS_SENT;
            }
        };
    }

    public function test_info_mit_empfaengern_schreibt_jede_zeile_an(): void
    {
        $ben = (int) $this->anstellung(['first_name' => 'Ben', 'phone' => '+491709999999'])->id;
        $sender = $this->attrappe();
        $service = new DokumentService($this->speicher, $sender);
        $r = $service->bereitstellenText(3, ['title' => 'News'], 'Text', [$this->maId, $ben], 7);

        $e = $service->hinweiseVersenden($r['dokument']);

        $this->assertSame(2, $e['versucht']);
        $this->assertSame(2, $e['benachrichtigt']);
        $this->assertEqualsCanonicalizing([$this->maId, $ben], $sender->angeschrieben);
        $this->assertSame(2, RecDocumentRecipient::where('rec_document_id', $r['dokument']->id)->whereNotNull('notified_at')->count());
    }

    public function test_automatisch_zugestellte_zeile_wird_nie_angeschrieben(): void
    {
        $sender = $this->attrappe();
        $service = new DokumentService($this->speicher, $sender);
        $r = $service->bereitstellenText(3, ['title' => 'Willkommen', 'auto_neue_mitarbeiter' => true], 'Hallo', [], 7);
        DB::table('rec_documents')->where('id', $r['dokument']->id)->update(['published_at' => '2026-10-01 00:00:00']);
        $neu = $this->anstellung(['first_name' => 'Neu', 'phone' => '+491708888888', 'rec_applicant_id' => 501]);
        DB::table('rec_employees')->where('id', $neu->id)->update(['created_at' => '2026-10-02 00:00:00']);
        $this->assertSame(1, (new DokumentAutoZustellung())->fuerErstesOeffnen($neu->fresh()));

        $z = RecDocumentRecipient::where('rec_document_id', $r['dokument']->id)->sole();
        $this->assertSame(DokumentHinweisSender::STATUS_NICHT_NOETIG, $z->notify_error);
        $this->assertNull($z->notified_at);

        $e = $service->hinweiseVersenden(RecDocument::find($r['dokument']->id));
        $this->assertSame(0, $e['versucht']);
        $this->assertSame([], $sender->angeschrieben);
        $this->assertSame(DokumentHinweisSender::STATUS_NICHT_NOETIG, $service->erneutSenden($z));
        $this->assertSame([], $sender->angeschrieben, 'auch Erneut senden schreibt sie nicht an');

        $zeile = DokumentAkteZeilen::fuer([$z->setRelation('document', RecDocument::find($r['dokument']->id))])[0];
        $this->assertSame('ohne Nachricht (im Portal gesehen)', $zeile['versand_text']);
        $this->assertFalse($zeile['kann_erneut_senden']);
        $this->assertFalse($zeile['versand_laeuft']);
    }

    public function test_erneut_senden_auf_gesehener_info_ist_erledigt(): void
    {
        $sender = $this->attrappe();
        $service = new DokumentService($this->speicher, $sender);
        $r = $service->bereitstellenText(3, ['title' => 'News'], 'Text', [$this->maId], 7);
        $z = RecDocumentRecipient::where('rec_document_id', $r['dokument']->id)->sole();
        $dok = RecDocument::find($r['dokument']->id);

        $offen = DokumentAkteZeilen::fuer([$z->setRelation('document', $dok)])[0];
        $this->assertTrue($offen['kann_erneut_senden'], 'ungesehene Info darf HR erneut anstossen');

        DB::table('rec_document_recipients')->where('id', $z->id)->update(['first_viewed_at' => now()]);
        $z = $z->fresh();
        $this->assertSame('erledigt', $service->erneutSenden($z));
        $this->assertSame([], $sender->angeschrieben);
        $gesehen = DokumentAkteZeilen::fuer([$z->setRelation('document', $dok)])[0];
        $this->assertFalse($gesehen['kann_erneut_senden']);
    }

    public function test_erneut_senden_auf_ungesehener_info_sendet(): void
    {
        $sender = $this->attrappe();
        $service = new DokumentService($this->speicher, $sender);
        $r = $service->bereitstellenText(3, ['title' => 'News'], 'Text', [$this->maId], 7);
        $z = RecDocumentRecipient::where('rec_document_id', $r['dokument']->id)->sole();

        $this->assertSame(DokumentHinweisSender::STATUS_SENT, $service->erneutSenden($z));
        $this->assertSame([$this->maId], $sender->angeschrieben);
    }
}
