<?php

namespace Platform\Recruiting\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Livewire\DirectHire\Index as DirectHire;
use ReflectionMethod;

/**
 * Quelltext-Zusicherungen fuer Stellen, die in dieser Suite nicht ausfuehrbar
 * sind (Livewire-Lebenszyklus, MCP-ToolContext). Schmal und benannt — der
 * Verhaltensnachweis liegt in VertragAnDerAnstellungTest.
 */
class VertragAnDerAnstellungWiringTest extends TestCase
{
    /** Spec §3.3 b: Direkteinstellung legt den Vertrag an, DANN den Mitarbeiter — sonst sieht der Hook keinen Vertrag. Mutation: Reihenfolge tauschen → rot. */
    public function test_direkteinstellung_legt_den_vertrag_vor_dem_mitarbeiter_an(): void
    {
        $src = $this->methodSource(DirectHire::class, 'createEmployeeWithContract');
        $vertrag = strpos($src, 'RecContract::create(');
        $anlage  = strpos($src, '->createOrUpdate(');
        $this->assertNotFalse($vertrag);
        $this->assertNotFalse($anlage);
        $this->assertLessThan($anlage, $vertrag, 'Der Vertrag muss VOR createOrUpdate() entstehen.');
    }

    private function methodSource(string $class, string $method): string
    {
        $r = new ReflectionMethod($class, $method);
        $lines = file($r->getFileName());
        return implode('', array_slice($lines, $r->getStartLine() - 1, $r->getEndLine() - $r->getStartLine() + 1));
    }
}
