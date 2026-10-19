<?php

namespace Platform\Recruiting\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Services\Zas\ZasInboundRowMapper;
use Platform\Recruiting\Services\Zas\ZasLookupReverseResolver;

/**
 * Grundlohn + Zuschlag aus der ZAS-Lieferung (Abgleich 19.10.2026: beide
 * Spalten kommen zu 100 % gefuellt, wurden aber verworfen).
 *
 * Reine Lesewerte, wie das Tagekonto: leer = nicht anfassen, Unsinn = Warnung
 * statt Raten. ZAS liefert Komma-Dezimalen ohne Auffuell-Nullen ("13,9").
 */
class ZasLohnInboundTest extends TestCase
{
    private function map(array $row): array
    {
        return (new ZasInboundRowMapper(new ZasLookupReverseResolver()))->map($row);
    }

    public function test_komma_werte_landen_zweistellig_in_den_hr_feldern(): void
    {
        $res = $this->map(['Grundlohn' => '13,9', 'Zuschlag' => '1,1']);

        $this->assertSame('13.90', $res['hr']['zas_grundlohn']);
        $this->assertSame('1.10', $res['hr']['zas_zuschlag']);
        $this->assertSame([], $res['warnings']);
    }

    public function test_zwei_nachkommastellen_bleiben_erhalten(): void
    {
        $res = $this->map(['Grundlohn' => '15,33', 'Zuschlag' => '0,67']);

        $this->assertSame('15.33', $res['hr']['zas_grundlohn']);
        $this->assertSame('0.67', $res['hr']['zas_zuschlag']);
    }

    /** 0 heisst bei ZAS "Zeile nicht gepflegt" — trotzdem ein gelieferter Wert. */
    public function test_null_ist_ein_wert(): void
    {
        $res = $this->map(['Grundlohn' => '0', 'Zuschlag' => '0']);

        $this->assertSame('0.00', $res['hr']['zas_grundlohn']);
        $this->assertSame('0.00', $res['hr']['zas_zuschlag']);
    }

    public function test_leer_laesst_den_vorhandenen_stand_stehen(): void
    {
        $res = $this->map(['Grundlohn' => '', 'Zuschlag' => '']);

        $this->assertArrayNotHasKey('zas_grundlohn', $res['hr']);
        $this->assertArrayNotHasKey('zas_zuschlag', $res['hr']);
    }

    public function test_unsinniger_wert_wird_nicht_uebernommen_sondern_gemeldet(): void
    {
        $res = $this->map(['Zuschlag' => 'k.A.']);

        $this->assertArrayNotHasKey('zas_zuschlag', $res['hr']);
        $this->assertCount(1, $res['warnings']);
        $this->assertStringContainsString('k.A.', $res['warnings'][0]);
    }

    /** decimal(6,2) wuerde eine dritte Stelle still runden — lieber melden. */
    public function test_drei_nachkommastellen_werden_gemeldet_statt_gerundet(): void
    {
        $res = $this->map(['Zuschlag' => '0,675']);

        $this->assertArrayNotHasKey('zas_zuschlag', $res['hr']);
        $this->assertCount(1, $res['warnings']);
    }

    /** Sonst meldet der Spaltenbericht die Spalten weiter als ungelesene Luecke. */
    public function test_spalten_stehen_im_spaltenbericht_als_gelesen(): void
    {
        $known = ZasInboundRowMapper::knownColumns();

        $this->assertContains('Grundlohn', $known);
        $this->assertContains('Zuschlag', $known);
    }
}
