<?php

namespace Platform\Recruiting\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Services\Zas\ZasInboundRowMapper;
use Platform\Recruiting\Services\Zas\ZasLookupReverseResolver;

/**
 * StatusMASeit (ZAS-Inbound) — Umstellungsdatum GO→MA.
 *
 * Sonderfall gegenueber allen anderen Datumsspalten: leer bedeutet hier
 * LOESCHEN, nicht "nicht anfassen". Damit eine kaputte Lieferung nicht den
 * ganzen Bestand abraeumt, wird das Loeschen an die Status-Spalte derselben
 * Zeile gekoppelt — nur ein Status != MA bestaetigt die Rueckstellung.
 *
 * Konvention: Key FEHLT in $hr  => nicht anfassen
 *             Key = null        => aktiv leeren
 */
class ZasStatusMaSinceInboundTest extends TestCase
{
    private function map(array $row): array
    {
        return (new ZasInboundRowMapper(new ZasLookupReverseResolver()))->map($row);
    }

    public function test_status_ma_mit_datum_setzt_das_datum(): void
    {
        $res = $this->map(['Status' => 'MA', 'StatusMASeit' => '19.08.2026']);

        $this->assertSame('MA', $res['hr']['export_status']);
        $this->assertSame('2026-08-19', $res['hr']['status_ma_since']);
        $this->assertSame([], $res['warnings']);
    }

    public function test_status_klein_geschrieben_zaehlt_auch_als_ma(): void
    {
        $res = $this->map(['Status' => 'ma', 'StatusMASeit' => '19.08.2026']);

        $this->assertSame('2026-08-19', $res['hr']['status_ma_since']);
        $this->assertSame([], $res['warnings']);
    }

    public function test_status_go_und_leeres_datum_loescht_aktiv(): void
    {
        $res = $this->map(['Status' => 'GO', 'StatusMASeit' => '']);

        $this->assertArrayHasKey('status_ma_since', $res['hr']);
        $this->assertNull($res['hr']['status_ma_since']);
        $this->assertSame([], $res['warnings']);
    }

    public function test_status_go_mit_datum_loescht_und_warnt(): void
    {
        $res = $this->map(['Status' => 'GO', 'StatusMASeit' => '19.08.2026']);

        $this->assertArrayHasKey('status_ma_since', $res['hr']);
        $this->assertNull($res['hr']['status_ma_since']);
        $this->assertStringContainsString('Status=GO', implode(' | ', $res['warnings']));
    }

    public function test_status_ma_ohne_datum_laesst_wert_stehen_und_warnt(): void
    {
        $res = $this->map(['Status' => 'MA', 'StatusMASeit' => '']);

        $this->assertArrayNotHasKey('status_ma_since', $res['hr']);
        $this->assertStringContainsString('Status=MA', implode(' | ', $res['warnings']));
    }

    public function test_status_ma_mit_kaputtem_datum_laesst_wert_stehen_und_warnt(): void
    {
        $res = $this->map(['Status' => 'MA', 'StatusMASeit' => '31.02.2026']);

        $this->assertArrayNotHasKey('status_ma_since', $res['hr']);
        $this->assertStringContainsString('Status=MA', implode(' | ', $res['warnings']));
    }

    public function test_fehlende_spalte_laesst_wert_unangetastet_und_warnt_nicht(): void
    {
        $res = $this->map(['Status' => 'GO']);

        $this->assertArrayNotHasKey('status_ma_since', $res['hr']);
        // Kein Rauschen: Lieferungen ohne die Spalte (alle bisherigen) sind
        // keine Anomalie, sie tragen nur keine Information ueber das Feld.
        $this->assertSame([], $res['warnings']);
    }

    public function test_fehlender_status_verhindert_loeschen(): void
    {
        $res = $this->map(['StatusMASeit' => '']);

        $this->assertArrayNotHasKey('status_ma_since', $res['hr']);
        $this->assertStringContainsString('Status', implode(' | ', $res['warnings']));
    }
}
