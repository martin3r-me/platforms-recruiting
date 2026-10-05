<?php

namespace Platform\Recruiting\Tests\Unit\Dispo;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Console\Commands\DispoRawHistory;

/**
 * Suchregeln des Rohdaten-Verlaufs (02.10.2026). Ein zu lockerer Vergleich
 * mischte fremde Einbuchungen in die Historie — und die Historie ist genau
 * das, womit wir belegen, was ZAS wann geliefert hat.
 */
class DispoRawHistoryMatchTest extends TestCase
{
    private function row(array $overrides = []): array
    {
        return $overrides + [
            'ds_id' => '926453', 'pnr' => 'RG1464', 'einsatz_id' => 'RG19896',
            'datum' => '02.10.2026', 'von' => '15:00', 'bis' => '23:00',
        ];
    }

    public function test_ds_id_trifft_exakt(): void
    {
        $this->assertTrue(DispoRawHistory::passt($this->row(), '926453', '', ''));
        $this->assertFalse(DispoRawHistory::passt($this->row(), '92645', '', ''));
        $this->assertFalse(DispoRawHistory::passt($this->row(), '9264530', '', ''));
    }

    /** ZAS liefert gern mit Leerzeichen — die duerfen nicht zum Fehlschlag fuehren. */
    public function test_leerzeichen_in_der_rohzeile_stoeren_nicht(): void
    {
        $this->assertTrue(DispoRawHistory::passt($this->row(['ds_id' => ' 926453 ']), '926453', '', ''));
    }

    public function test_pnr_und_einsatz_als_eigene_kriterien(): void
    {
        $this->assertTrue(DispoRawHistory::passt($this->row(), '', 'RG1464', ''));
        $this->assertFalse(DispoRawHistory::passt($this->row(), '', 'RG14', ''), 'Praefix darf nicht ausreichen.');
        $this->assertTrue(DispoRawHistory::passt($this->row(), '', '', 'RG19896'));
    }

    /** Mehrere Angaben sind UND-verknuepft, nicht ODER. */
    public function test_kriterien_sind_und_verknuepft(): void
    {
        $this->assertTrue(DispoRawHistory::passt($this->row(), '926453', 'RG1464', 'RG19896'));
        $this->assertFalse(DispoRawHistory::passt($this->row(), '926453', 'RG9999', ''));
    }

    public function test_fehlende_spalte_trifft_nicht(): void
    {
        $this->assertFalse(DispoRawHistory::passt(['datum' => '02.10.2026'], '926453', '', ''));
    }
}
