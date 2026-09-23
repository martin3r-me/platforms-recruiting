<?php

namespace Platform\Recruiting\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Services\Zas\ZasInboundRowMapper;
use Platform\Recruiting\Services\Zas\ZasLookupReverseResolver;
use Platform\Recruiting\Support\EuMemberStates;

/**
 * EU-Status aus der ZAS-Lieferung (Befund 23.09.2026, Lieferung 608).
 *
 * `EUBuerger` kommt bei keinem Mitarbeiter gefuellt — `is_eu_citizen` war bei
 * allen 1.234 Bestandsmitarbeitern NULL. ZAS liefert aber zu 100 %
 * `AufenthaltGenehmigungErforderlich`. Daraus ist nur EINE Richtung sicher:
 *
 *  - Ja   → braucht eine Genehmigung → kein EU-Buerger
 *  - Nein → heisst NICHT automatisch EU (Schweiz/Norwegen/Island/
 *           Liechtenstein brauchen keine; unbefristete Titel ggf. auch nicht).
 *           Nur zusammen mit einer EU-Staatsangehoerigkeit → EU-Buerger.
 *  - sonst bleibt der Wert leer.
 *
 * Liefert ZAS `EUBuerger` selbst, gilt immer dieser Wert.
 */
class ZasEuStatusInboundTest extends TestCase
{
    private function map(array $row): array
    {
        $lookups = new class extends ZasLookupReverseResolver {
            protected function loadPairs(string $lookupName): array
            {
                return $lookupName === 'geburtsland'
                    ? [
                        ['value' => 'de', 'label' => 'Deutschland'],
                        ['value' => 'pl', 'label' => 'Polen'],
                        ['value' => 'tr', 'label' => 'Türkei'],
                        ['value' => 'ch', 'label' => 'Schweiz'],
                    ]
                    : [];
            }
        };

        return (new ZasInboundRowMapper($lookups))->map($row);
    }

    public function test_genehmigung_erforderlich_heisst_kein_eu_buerger(): void
    {
        $res = $this->map(['AufenthaltGenehmigungErforderlich' => 'Ja', 'Nation' => 'Türkei']);

        $this->assertFalse($res['employee']['is_eu_citizen']);
    }

    public function test_ja_gilt_auch_ohne_nation(): void
    {
        $res = $this->map(['AufenthaltGenehmigungErforderlich' => 'Ja']);

        $this->assertFalse($res['employee']['is_eu_citizen']);
    }

    public function test_nein_mit_eu_nation_heisst_eu_buerger(): void
    {
        $this->assertTrue($this->map(['AufenthaltGenehmigungErforderlich' => 'Nein', 'Nation' => 'Deutschland'])['employee']['is_eu_citizen']);
        $this->assertTrue($this->map(['AufenthaltGenehmigungErforderlich' => 'Nein', 'Nation' => 'Polen'])['employee']['is_eu_citizen']);
    }

    public function test_nein_mit_nicht_eu_nation_bleibt_leer(): void
    {
        // Schweizer brauchen keine Genehmigung, sind aber keine EU-Buerger.
        $this->assertArrayNotHasKey('is_eu_citizen', $this->map(['AufenthaltGenehmigungErforderlich' => 'Nein', 'Nation' => 'Schweiz'])['employee']);
        $this->assertArrayNotHasKey('is_eu_citizen', $this->map(['AufenthaltGenehmigungErforderlich' => 'Nein', 'Nation' => 'Türkei'])['employee']);
    }

    public function test_nein_ohne_oder_mit_unbekannter_nation_bleibt_leer(): void
    {
        $this->assertArrayNotHasKey('is_eu_citizen', $this->map(['AufenthaltGenehmigungErforderlich' => 'Nein'])['employee']);
        // Freitext ohne Lookup-Treffer landet roh in nationality — kein Code, kein Schluss.
        $this->assertArrayNotHasKey('is_eu_citizen', $this->map(['AufenthaltGenehmigungErforderlich' => 'Nein', 'Nation' => 'Muelheim an der Ruhr'])['employee']);
    }

    public function test_leere_spalte_bleibt_leer(): void
    {
        $this->assertArrayNotHasKey('is_eu_citizen', $this->map(['AufenthaltGenehmigungErforderlich' => '', 'Nation' => 'Deutschland'])['employee']);
    }

    public function test_geliefertes_eu_buerger_gewinnt(): void
    {
        $res = $this->map(['EUBuerger' => 'Nein', 'AufenthaltGenehmigungErforderlich' => 'Nein', 'Nation' => 'Deutschland']);
        $this->assertFalse($res['employee']['is_eu_citizen']);

        $res = $this->map(['EUBuerger' => 'Ja', 'AufenthaltGenehmigungErforderlich' => 'Ja']);
        $this->assertTrue($res['employee']['is_eu_citizen']);
    }

    public function test_spalte_gilt_im_spaltenbericht_als_gelesen(): void
    {
        $this->assertContains('AufenthaltGenehmigungErforderlich', ZasInboundRowMapper::knownColumns());
    }

    public function test_eu_liste_hat_27_staaten_und_keine_ewr_laender(): void
    {
        $this->assertCount(27, EuMemberStates::CODES);
        $this->assertTrue(EuMemberStates::contains('DE'));
        foreach (['ch', 'no', 'is', 'li', 'gb'] as $notEu) {
            $this->assertFalse(EuMemberStates::contains($notEu), $notEu);
        }
        $this->assertFalse(EuMemberStates::contains(null));
    }
}
