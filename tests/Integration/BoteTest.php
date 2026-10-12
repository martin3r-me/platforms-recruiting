<?php

namespace Platform\Recruiting\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Console\Commands\EinsatzPruefung;
use Platform\Recruiting\Support\Bote;

final class BoteTest extends TestCase
{
    use HrBereichHarness;

    protected function setUp(): void
    {
        parent::setUp();
        $this->hrWeltAufbauen();
    }

    protected function tearDown(): void
    {
        $this->hrWeltAbbauen();
        parent::tearDown();
    }

    public function test_kleinste_kennung_mit_neuem_portal(): void
    {
        $alt = $this->akte(['portal_v2_since' => null]);
        $neu1 = $this->akte();
        $neu2 = $this->akte();

        $this->assertSame($neu1->id, Bote::fuer([$neu2->id, $alt->id, $neu1->id])?->id);
        $this->assertNull(Bote::fuer([$alt->id]));
        $this->assertNull(Bote::fuer([]));
    }

    public function test_das_kommando_nutzt_den_ausgezogenen_boten_und_die_laufsperre_ist_oeffentlich(): void
    {
        $this->assertSame('recruiting:einsatz-pruefung:lauf', EinsatzPruefung::LAUF_SPERRE);
        $this->assertFalse(method_exists(EinsatzPruefung::class, 'bote'), 'eine Regel, eine Stelle: Support\\Bote');
    }
}
