<?php

namespace Platform\Recruiting\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Livewire\Public\ContractSigning;
use ReflectionClass;

/**
 * VERDRAHTUNG im Unterschriften-Schritt — Quelltext-Test, weil die
 * Livewire-Komponente in dieser Suite nicht instanziierbar ist.
 *
 * Zwei Entscheidungen aus dem Review vom 25.09.2026 werden hier festgenagelt:
 *
 * 1. sign() prueft die §15-Zeilen mit DENSELBEN Regeln wie nextStep().
 *    sign() ist direkt aufrufbar und par15Entries ist nicht #[Locked].
 *    Solange die Zeilen nur als Text ins Vertrags-PDF wanderten, war das
 *    verschmerzbar — seit daraus "Tage erlaubt" gerechnet wird, waere eine
 *    ungeprueft durchgelassene Zeile eine Geldgroesse aus dem Browser.
 *
 * 2. Der Startwert haengt NICHT an der Arbeitgeber-Erklaerung. Vorher stand
 *    sein Aufruf hinter deren Waechtern: fehlte die Arbeitgeber-Rolle, wurde
 *    auch das Tagekonto nicht gesetzt, obwohl die §15-Angaben vorlagen —
 *    still, ohne Log. Zwei fachlich unabhaengige Erklaerungen, zwei Aufrufe.
 */
class ContractSigningDayBudgetWiringTest extends TestCase
{
    private function quelltext(): string
    {
        return file_get_contents((new ReflectionClass(ContractSigning::class))->getFileName());
    }

    public function test_sign_nutzt_dieselben_paragraf_regeln_wie_der_schritt(): void
    {
        $src = $this->quelltext();

        $this->assertStringContainsString(
            'private function par1516Rules()',
            $src,
            'Die Regeln muessen an einer Stelle stehen, sonst laufen sie auseinander.',
        );
        $this->assertSame(
            2,
            substr_count($src, '$this->par1516Rules()'),
            'Genau zwei Aufrufer erwartet: validatePreSigningData() und sign().',
        );
    }

    public function test_startwert_haengt_nicht_an_der_arbeitgeber_erklaerung(): void
    {
        $src = $this->quelltext();

        $employer = strpos($src, '$this->applyEmployerDeclaration(');
        $budget   = strpos($src, '$this->applyDayBudget(');

        $this->assertNotFalse($employer);
        $this->assertNotFalse($budget);

        // Beide werden aus sign() heraus aufgerufen, nicht ineinander.
        $this->assertStringNotContainsString(
            '$this->applyDayBudget($contract, $employee,',
            $src,
            'applyDayBudget darf nicht aus applyEmployerDeclaration heraus aufgerufen werden.',
        );
    }

    /**
     * Beide Uebernahmen duerfen die Unterschrift nie kippen — der Vertrag ist
     * zu dem Zeitpunkt bereits gespeichert.
     */
    public function test_beide_uebernahmen_haben_einen_eigenen_fehlerzweig(): void
    {
        $src = $this->quelltext();

        $this->assertStringContainsString('[ContractSigning] Arbeitgeber-Erklaerung nicht uebernommen', $src);
        $this->assertStringContainsString('[ContractSigning] Startwert Tagekonto nicht gesetzt', $src);
    }
}
