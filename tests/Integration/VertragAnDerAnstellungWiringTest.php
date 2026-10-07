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

    /** Spec §3.3 d: alle Anlagepfade rufen die eine Regel. Mutation: Aufruf an einer Stelle entfernen → rot. */
    public function test_alle_anlagepfade_setzen_den_anker_ueber_die_regel(): void
    {
        $erwartet = [
            [\Platform\Recruiting\Livewire\Applicant\Show::class, 'createSingleContract', 1],
            [\Platform\Recruiting\Tools\CreateContractTool::class, 'execute', 1],
            [\Platform\Recruiting\Services\SendContractsService::class, 'send', 3],
        ];
        foreach ($erwartet as [$class, $method, $anzahl]) {
            $src = $this->methodSource($class, $method);
            $this->assertSame($anzahl, substr_count($src, '->ankerFuerNeuenVertrag('), "{$class}::{$method}");
            $this->assertSame($anzahl, substr_count($src, "'rec_employee_id'"), "{$class}::{$method} schreibt den Anker nicht an jeder create()-Stelle");
        }
    }

    private function methodSource(string $class, string $method): string
    {
        $r = new ReflectionMethod($class, $method);
        $lines = file($r->getFileName());
        return implode('', array_slice($lines, $r->getStartLine() - 1, $r->getEndLine() - $r->getStartLine() + 1));
    }

    public function test_vorlagen_formular_und_tools_kennen_firma_und_taetigkeit(): void
    {
        $view = file_get_contents(dirname(__DIR__, 2) . '/resources/views/livewire/contract-templates/index.blade.php');
        $this->assertStringContainsString('wire:model="company"', $view);
        $this->assertStringContainsString('wire:model="taetigkeit"', $view);
        foreach ([\Platform\Recruiting\Tools\CreateContractTemplateTool::class, \Platform\Recruiting\Tools\UpdateContractTemplateTool::class] as $tool) {
            $src = file_get_contents((new \ReflectionClass($tool))->getFileName());
            $this->assertStringContainsString("'company'", $src, $tool);
            $this->assertStringContainsString("'taetigkeit'", $src, $tool);
        }
    }
}
