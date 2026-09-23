<?php

namespace Platform\Recruiting\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Services\Zas\ZasInboundRowMapper;
use Platform\Recruiting\Services\Zas\ZasLookupReverseResolver;

/**
 * ZAS liefert `Geburtsort` (Stadt) und `Nation` (Staatsangehoerigkeit) —
 * ein Geburtsland fuehrt ZAS nicht (Spaltenbericht Lieferung 579, 23.09.2026).
 * `Nation` gehoert deshalb in `nationality`, nicht in `birth_country`.
 */
class ZasNationalityInboundTest extends TestCase
{
    private function map(array $row): array
    {
        // Lookup-Paare ohne DB: dieselben ISO-Codes wie im Lookup `geburtsland`.
        $lookups = new class extends ZasLookupReverseResolver {
            protected function loadPairs(string $lookupName): array
            {
                return $lookupName === 'geburtsland'
                    ? [['value' => 'de', 'label' => 'Deutschland'], ['value' => 'tr', 'label' => 'Türkei']]
                    : [];
            }
        };

        return (new ZasInboundRowMapper($lookups))->map($row);
    }

    public function test_nation_lands_in_nationality_not_in_birth_country(): void
    {
        $res = $this->map(['Nation' => 'Deutschland']);

        $this->assertSame('de', $res['employee']['nationality']);
        $this->assertArrayNotHasKey('birth_country', $res['employee']);
    }

    public function test_zas_adjective_resolves_through_the_existing_aliases(): void
    {
        // ZAS schreibt Adjektive ("deutsch"); die Alias-Tabelle biegt sie auf den Code.
        $res = $this->map(['Nation' => 'deutsch']);

        $this->assertSame('de', $res['employee']['nationality']);
    }

    public function test_empty_nation_stays_unset(): void
    {
        $res = $this->map(['Nation' => '']);

        $this->assertArrayNotHasKey('nationality', $res['employee']);
        $this->assertArrayNotHasKey('birth_country', $res['employee']);
    }
}
