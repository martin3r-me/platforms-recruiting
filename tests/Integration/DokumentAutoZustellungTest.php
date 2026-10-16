<?php

namespace Platform\Recruiting\Tests\Integration;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Models\RecDocument;
use Platform\Recruiting\Models\RecDocumentRecipient;
use Platform\Recruiting\Models\RecEmployee;
use Platform\Recruiting\Services\DokumentAutoZustellung;

/**
 * Automatik "an neue Mitarbeiter" (Spec 2026-10-16 §4): nur Anstellungen, die
 * NACH der Veroeffentlichung angelegt wurden, bekommen die Info — einmal je
 * Person, ohne WhatsApp.
 */
final class DokumentAutoZustellungTest extends TestCase
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

    private function info(bool $auto, string $publishedAt): int
    {
        return (int) RecDocument::create([
            'team_id' => 3, 'title' => 'Willkommen', 'category' => 'info', 'action' => 'none',
            'content_markdown' => 'Hallo', 'file_sha256' => hash('sha256', 'Hallo'),
            'auto_neue_mitarbeiter' => $auto, 'published_at' => $publishedAt,
        ])->id;
    }

    /**
     * Name weicht von der Brief-Vorlage ab: `anstellung()` belegt schon der
     * Harness. Gleiche Handynummer, damit RG+MA derselben Person ueber den
     * person_key-Zweig des PersonScopeResolver gepaart werden.
     */
    private function anstellungAm(string $createdAt, string $company = 'RG', ?string $personKey = null): RecEmployee
    {
        $e = $this->anstellung(['company' => $company, 'person_key' => $personKey, 'phone' => '+491701234567']);
        DB::table('rec_employees')->where('id', $e->id)->update(['created_at' => $createdAt]);

        return $e->fresh();
    }

    public function test_nach_veroeffentlichung_angelegte_anstellung_bekommt_die_info_einmal(): void
    {
        $info = $this->info(true, '2026-10-20 10:00:00');
        $e = $this->anstellungAm('2026-10-21 09:00:00');
        $dienst = new DokumentAutoZustellung();
        $this->assertSame(1, $dienst->fuerErstesOeffnen($e));
        $this->assertSame(0, $dienst->fuerErstesOeffnen($e), 'idempotent');
        $z = RecDocumentRecipient::where('rec_document_id', $info)->get();
        $this->assertCount(1, $z);
        $this->assertSame($e->id, (int) $z->first()->rec_employee_id);
        $this->assertNotEmpty($z->first()->uuid);
        $this->assertNull($z->first()->notified_at, 'keine WhatsApp: der Mensch ist gerade im Portal');
    }

    public function test_vorher_angelegte_anstellung_bekommt_nichts(): void
    {
        $this->info(true, '2026-10-20 10:00:00');
        $e = $this->anstellungAm('2026-10-19 09:00:00');
        $this->assertSame(0, (new DokumentAutoZustellung())->fuerErstesOeffnen($e));
    }

    public function test_gleicher_zeitpunkt_zaehlt_als_vorher(): void
    {
        $this->info(true, '2026-10-20 10:00:00');
        $e = $this->anstellungAm('2026-10-20 10:00:00');
        $this->assertSame(0, (new DokumentAutoZustellung())->fuerErstesOeffnen($e));
    }

    public function test_ohne_haken_zurueckgezogen_oder_fremdes_team_nichts(): void
    {
        $this->info(false, '2026-10-20 10:00:00');
        $geloescht = $this->info(true, '2026-10-20 10:00:00');
        RecDocument::find($geloescht)->delete();
        RecDocument::create(['team_id' => 4, 'title' => 'Fremd', 'category' => 'info', 'action' => 'none',
            'content_markdown' => 'x', 'file_sha256' => hash('sha256', 'x'), 'auto_neue_mitarbeiter' => true, 'published_at' => '2026-10-20 10:00:00']);
        $e = $this->anstellungAm('2026-10-21 09:00:00');
        $this->assertSame(0, (new DokumentAutoZustellung())->fuerErstesOeffnen($e));
    }

    public function test_rg_und_ma_derselben_person_eine_zeile(): void
    {
        $info = $this->info(true, '2026-10-20 10:00:00');
        $rg = $this->anstellungAm('2026-10-21 09:00:00', 'RG', 'pk-1');
        $ma = $this->anstellungAm('2026-10-21 09:05:00', 'MA', 'pk-1');
        $dienst = new DokumentAutoZustellung();
        $this->assertSame(1, $dienst->fuerErstesOeffnen($rg));
        $this->assertSame(0, $dienst->fuerErstesOeffnen($ma), 'Schwester-Anstellung hat die Info schon ueber die Person');
        $this->assertSame(1, RecDocumentRecipient::where('rec_document_id', $info)->count());
    }
}
