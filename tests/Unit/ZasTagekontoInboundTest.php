<?php

namespace Platform\Recruiting\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Services\Zas\ZasInboundRowMapper;
use Platform\Recruiting\Services\Zas\ZasLookupReverseResolver;

/**
 * Tagekonto-Rueckweg aus ZAS (Abstimmung mit Olaf 30.09.2026):
 * TageGearbeitetJahr + ArbeitstageRest. Beide Werte gehoeren ZAS und sind bei
 * uns schreibgeschuetzt.
 *
 * Konvention des Mappers: leer = nicht anfassen (der vorhandene Stand bleibt).
 */
class ZasTagekontoInboundTest extends TestCase
{
    private function map(array $row): array
    {
        return (new ZasInboundRowMapper(new ZasLookupReverseResolver()))->map($row);
    }

    public function test_beide_werte_landen_in_den_hr_feldern(): void
    {
        $res = $this->map(['TageGearbeitetJahr' => '18', 'ArbeitstageRest' => '32']);

        $this->assertSame(18, $res['hr']['short_term_days_worked']);
        $this->assertSame(32, $res['hr']['short_term_days_remaining']);
        $this->assertSame([], $res['warnings']);
    }

    /**
     * Mehr gearbeitet als erlaubt — der teure Fall. Der Wert MUSS ankommen
     * statt die Zeile zu kippen; die Spalte ist dafuer vorzeichenbehaftet.
     */
    public function test_negativer_rest_wird_uebernommen(): void
    {
        $res = $this->map(['TageGearbeitetJahr' => '75', 'ArbeitstageRest' => '-5']);

        $this->assertSame(-5, $res['hr']['short_term_days_remaining']);
        $this->assertSame([], $res['warnings']);
    }

    public function test_null_ist_ein_wert(): void
    {
        $res = $this->map(['ArbeitstageRest' => '0']);

        $this->assertSame(0, $res['hr']['short_term_days_remaining']);
    }

    public function test_leer_laesst_den_vorhandenen_stand_stehen(): void
    {
        $res = $this->map(['TageGearbeitetJahr' => '', 'ArbeitstageRest' => '']);

        $this->assertArrayNotHasKey('short_term_days_worked', $res['hr']);
        $this->assertArrayNotHasKey('short_term_days_remaining', $res['hr']);
    }

    /** Lieber kein Wert als ein geratener — und eine Warnung mit dem Original. */
    public function test_unsinniger_wert_wird_nicht_uebernommen_sondern_gemeldet(): void
    {
        $res = $this->map(['TageGearbeitetJahr' => 'k.A.']);

        $this->assertArrayNotHasKey('short_term_days_worked', $res['hr']);
        $this->assertCount(1, $res['warnings']);
        $this->assertStringContainsString('k.A.', $res['warnings'][0]);
    }

    /** Sonst meldet der Spaltenbericht die neuen Spalten als ungelesene Luecke. */
    public function test_spalten_stehen_im_spaltenbericht_als_gelesen(): void
    {
        $known = ZasInboundRowMapper::knownColumns();

        $this->assertContains('TageGearbeitetJahr', $known);
        $this->assertContains('ArbeitstageRest', $known);
    }
}
