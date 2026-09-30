<?php

namespace Platform\Recruiting\Tests\Unit;

use Illuminate\Support\Carbon;
use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Models\RecEmployee;
use Platform\Recruiting\Models\RecEmployeeHrData;
use Platform\Recruiting\Services\Zas\ZasEmployeeFieldResolver;

/**
 * Die drei Tagekonto-Spalten des Mitarbeiter-Exports (mit Olaf abgestimmt
 * 30.09.2026). Was auf dem Spiel steht: eine zu hohe Zahl heisst, dass jemand
 * mehr Tage arbeitet als erlaubt — die kurzfristige Beschaeftigung verliert
 * dann rueckwirkend ihren Status.
 */
class ZasTagekontoExportTest extends TestCase
{
    protected function setUp(): void
    {
        Carbon::setTestNow('2026-09-30 10:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
    }

    private function resolver(): object
    {
        return new class extends ZasEmployeeFieldResolver {
            public function __construct() {}
            public function col(RecEmployee $e, $hr, string $column): ?string
            {
                return $this->resolveColumn($e, $hr, $column);
            }
        };
    }

    private function hrData(array $attributes): RecEmployeeHrData
    {
        $hr = new RecEmployeeHrData();
        $hr->setRawAttributes($attributes);

        return $hr;
    }

    private function employee(array $attributes = []): RecEmployee
    {
        $e = new RecEmployee();
        $e->setRawAttributes($attributes);

        return $e;
    }

    public function test_startwert_des_laufenden_jahres_geht_raus(): void
    {
        $out = $this->resolver()->col(
            $this->employee(),
            $this->hrData(['short_term_days_allowed' => 50, 'short_term_days_allowed_year' => 2026]),
            'TageErlaubt'
        );

        $this->assertSame('50', $out);
    }

    /** Das Kontingent gilt je Kalenderjahr — eine 50 aus 2026 ist 2027 falsch. */
    public function test_startwert_aus_dem_vorjahr_geht_nicht_raus(): void
    {
        $out = $this->resolver()->col(
            $this->employee(),
            $this->hrData(['short_term_days_allowed' => 50, 'short_term_days_allowed_year' => 2025]),
            'TageErlaubt'
        );

        $this->assertNull($out, 'Leer heisst "keine Grundlage" — ZAS wendet dann nichts an.');
    }

    /** 0 heisst "Grenze ausgeschoepft" und muss ankommen, nicht als leer gelten. */
    public function test_null_tage_ist_ein_wert_und_kein_leerfeld(): void
    {
        $out = $this->resolver()->col(
            $this->employee(),
            $this->hrData(['short_term_days_allowed' => 0, 'short_term_days_allowed_year' => 2026]),
            'TageErlaubt'
        );

        $this->assertSame('0', $out);
    }

    public function test_ohne_erklaerung_bleibt_die_spalte_leer(): void
    {
        $out = $this->resolver()->col($this->employee(), $this->hrData([]), 'TageErlaubt');

        $this->assertNull($out);
    }

    public function test_hauptarbeitgeber_ja_und_nein(): void
    {
        $r = $this->resolver();

        $this->assertSame('Ja', $r->col($this->employee(['is_main_employer' => true]), null, 'Hauptarbeitgeber'));
        $this->assertSame('Nein', $r->col($this->employee(['is_main_employer' => false]), null, 'Hauptarbeitgeber'));
    }

    /**
     * Ohne Erklaerung LEER statt "Nein": ein Nein hiesse "jemand anderes ist
     * Hauptarbeitgeber", waehrend AndererArbeitgeber leer bliebe — ein
     * Widerspruch in einem Feld, an dem die Sozialversicherung haengt.
     */
    public function test_hauptarbeitgeber_ohne_angabe_bleibt_leer(): void
    {
        $this->assertNull($this->resolver()->col($this->employee(), null, 'Hauptarbeitgeber'));
    }

    public function test_anderer_arbeitgeber_geht_als_text_raus(): void
    {
        $out = $this->resolver()->col(
            $this->employee(['is_main_employer' => false, 'other_employer' => 'Mueller GmbH']),
            null,
            'AndererArbeitgeber'
        );

        $this->assertSame('Mueller GmbH', $out);
    }
}
