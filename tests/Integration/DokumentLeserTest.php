<?php

namespace Platform\Recruiting\Tests\Integration;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Models\RecDocument;
use Platform\Recruiting\Models\RecDocumentRecipient;
use Platform\Recruiting\Services\DokumentLeser;

final class DokumentLeserTest extends TestCase
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

    private function dokument(array $set = []): RecDocument
    {
        return RecDocument::create(array_merge([
            'team_id' => 3, 'title' => 'Verschwiegenheit', 'category' => 'contract', 'action' => 'sign',
            'disk' => 'test-local', 'stored_path' => 'recruiting/dokumente/3/x.pdf', 'original_filename' => 'x.pdf',
            'file_sha256' => str_repeat('a', 64), 'file_size' => 10,
        ], $set));
    }

    private function zustellung(RecDocument $d, int $employeeId, array $set = []): RecDocumentRecipient
    {
        $z = RecDocumentRecipient::create(['team_id' => 3, 'rec_document_id' => $d->id, 'rec_employee_id' => $employeeId]);
        if ($set !== []) {
            DB::table('rec_document_recipients')->where('id', $z->id)->update($set);
        }

        return $z->fresh();
    }

    public function test_sieht_dokumente_beider_anstellungen_einmal_und_fremde_nie(): void
    {
        $rg = $this->anstellung(['rec_person_id' => 1, 'person_key' => 'p-1', 'company' => 'RG']);
        $ma = $this->anstellung(['rec_person_id' => 1, 'person_key' => 'p-1', 'company' => 'MA']);
        $fremd = $this->anstellung(['rec_person_id' => 2, 'person_key' => 'p-2']);
        $d1 = $this->dokument(['title' => 'An RG']);
        $d2 = $this->dokument(['title' => 'An MA']);
        $d3 = $this->dokument(['title' => 'Fremd']);
        $this->zustellung($d1, $rg->id);
        $this->zustellung($d2, $ma->id);
        $this->zustellung($d3, $fremd->id);

        $liste = (new DokumentLeser())->fuerMitarbeiter($ma);

        $this->assertSame(['An MA', 'An RG'], array_column($liste, 'title'), 'neueste zuerst, beide Anstellungen');
        $this->assertSame('Vertrag / Zusatzvereinbarung', $liste[0]['category_label']);
        $this->assertSame('offen', $liste[0]['status']);
        $this->assertTrue($liste[0]['offen']);
    }

    public function test_zurueckgezogene_und_geloeschte_fehlen(): void
    {
        $e = $this->anstellung();
        $d1 = $this->dokument();
        $this->zustellung($d1, $e->id, ['withdrawn_at' => '2026-10-09 10:00:00']);
        $d2 = $this->dokument(['title' => 'Geloescht']);
        $this->zustellung($d2, $e->id);
        $d2->delete();
        $d3 = $this->dokument(['title' => 'Bleibt']);
        $this->zustellung($d3, $e->id);

        $liste = (new DokumentLeser())->fuerMitarbeiter($e);

        $this->assertSame(['Bleibt'], array_column($liste, 'title'));
    }

    public function test_empfaenger_liefert_nur_eigene_offene(): void
    {
        $e = $this->anstellung();
        $fremd = $this->anstellung();
        $d = $this->dokument();
        $eigen = $this->zustellung($d, $e->id);
        $fremde = $this->zustellung($this->dokument(), $fremd->id);
        $zurueck = $this->zustellung($this->dokument(), $e->id, ['withdrawn_at' => '2026-10-09 10:00:00']);

        $leser = new DokumentLeser();
        $this->assertSame($eigen->id, $leser->empfaenger($e, $eigen->id)?->id);
        $this->assertNull($leser->empfaenger($e, $fremde->id));
        $this->assertNull($leser->empfaenger($e, $zurueck->id));
        $this->assertNull($leser->empfaenger($e, 999999));
    }

    public function test_offene_punkte_nur_fuer_handlungsbedarf(): void
    {
        $e = $this->anstellung();
        $sign = $this->zustellung($this->dokument(['title' => 'Unterschreiben']), $e->id);
        $ack = $this->zustellung($this->dokument(['title' => 'Lesen', 'action' => 'acknowledge', 'category' => 'instruction']), $e->id);
        $this->zustellung($this->dokument(['title' => 'Lohn', 'action' => 'none', 'category' => 'payslip']), $e->id);
        $this->zustellung($this->dokument(['title' => 'Fertig']), $e->id, ['signed_at' => '2026-10-09 10:00:00']);
        $this->zustellung($this->dokument(['title' => 'Gelesen', 'action' => 'acknowledge']), $e->id, ['acknowledged_at' => '2026-10-09 10:00:00']);

        $punkte = (new DokumentLeser())->offenePunkte($e);

        $this->assertCount(2, $punkte);
        $codes = array_column($punkte, 'code');
        $this->assertContains('dokument:' . $sign->id, $codes);
        $this->assertContains('dokument:' . $ack->id, $codes);
        $this->assertSame('Unterschreiben', $punkte[0]['label']);
        $this->assertFalse($punkte[0]['ko']);
        $this->assertSame('crit', $punkte[0]['punkt']);
        $this->assertSame('Lesen und unterschreiben', $punkte[0]['text']);
        $this->assertSame('Lesen und bestätigen', $punkte[1]['text']);
    }

    public function test_frisch_gemeldete_dokumente_fallen_fuer_den_trigger_weg(): void
    {
        $e = $this->anstellung();
        $frisch = $this->zustellung($this->dokument(['title' => 'Frisch']), $e->id, ['notified_at' => '2026-10-08 10:00:00']);
        $alt    = $this->zustellung($this->dokument(['title' => 'Alt']), $e->id, ['notified_at' => '2026-09-30 10:00:00']);
        $fehl   = $this->zustellung($this->dokument(['title' => 'Fehlversuch']), $e->id, ['notify_error' => 'no_phone']);

        $alle = (new DokumentLeser())->offenePunkte($e);
        $trigger = (new DokumentLeser())->offenePunkte($e, 7, '2026-10-09');

        $this->assertCount(3, $alle, 'das Portal sieht alles');
        $codes = array_column($trigger, 'code');
        $this->assertNotContains('dokument:' . $frisch->id, $codes, 'vor einem Tag gemeldet — die Einsatz-Pruefung schweigt noch');
        $this->assertContains('dokument:' . $alt->id, $codes, 'vor neun Tagen gemeldet — jetzt wieder dran');
        $this->assertContains('dokument:' . $fehl->id, $codes, 'nie angekommen — die Einsatz-Pruefung ist der zweite Fang');
    }
}
