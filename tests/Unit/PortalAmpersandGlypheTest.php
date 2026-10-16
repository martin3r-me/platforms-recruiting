<?php

namespace Platform\Recruiting\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * "Dokumente & Infos" im Portal: die Ueberschriften-Schrift (Chau Philomene
 * One) zeichnet "&" als Et-Ligatur, die wie "eT" aussieht. Das Zeichen steht
 * deshalb in <span class="amp"> und bekommt die Textschrift des Portals.
 */
final class PortalAmpersandGlypheTest extends TestCase
{
    private function datei(string $pfad): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2) . '/resources/views/' . $pfad);
    }

    public function test_alle_drei_stellen_setzen_das_und_zeichen_in_die_textschrift(): void
    {
        $blade = $this->datei('livewire/public/portal-shell.blade.php');

        $this->assertSame(3, substr_count($blade, 'Dokumente <span class="amp">&amp;</span> Infos'));
        $this->assertSame(0, substr_count($blade, 'Dokumente &amp; Infos'), 'keine Stelle vergessen');
    }

    public function test_stil_amp_nutzt_die_textschrift(): void
    {
        $stile = $this->datei('layouts/portal-styles.blade.php');

        $this->assertMatchesRegularExpression('~\.amp\s*\{[^}]*font-family:\s*var\(--body\)~', $stile);
        $this->assertMatchesRegularExpression('~--body:\s*"Inter"~', $stile, '--body ist die Textschrift (Inter)');
    }
}
