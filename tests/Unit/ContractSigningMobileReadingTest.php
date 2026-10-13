<?php

namespace Platform\Recruiting\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * "In der mobilen Ansicht braucht man eine bessere Ansicht, um sich alles
 * durchzulesen" (Demo am Handy, 13.10.2026).
 *
 * Der Vertragstext stand in einer Box mit fester Hoehe (max-h-[60vh]) und
 * eigenem Scrollbalken, in einer Karte mit p-8 — am Handy eine kleine
 * Scrollbox in der Scrollseite, rundherum viel Rand. Die Seite ist fuer
 * Bewerber LIVE; geaendert ist nur die Darstellung (eigene, gescopte Stile),
 * keine Logik und kein Text.
 *
 * Quelltext-Test, weil Blade in dieser Suite nicht gerendert wird. Die letzte
 * Instanz bleibt der Blick auf ein echtes Handy.
 */
class ContractSigningMobileReadingTest extends TestCase
{
    private function blade(): string
    {
        return (string) file_get_contents(
            dirname(__DIR__, 2) . '/resources/views/livewire/public/contract-signing.blade.php'
        );
    }

    /** Die Handy-Regeln: alles, was fuer kleine Bildschirme gilt, in einem Block. */
    private function handyRegeln(): string
    {
        $ok = preg_match('/@media\s*\(max-width:\s*639px\)\s*\{(.*)\}\s*<\/style>/s', $this->blade(), $m);
        $this->assertSame(1, $ok, 'Kein Handy-Block (@media max-width: 639px) im Stilblock der Vertragsseite.');

        return $m[1];
    }

    /** Die Regel fuer einen Selektor innerhalb des Handy-Blocks. */
    private function regel(string $selektor): string
    {
        $ok = preg_match('/' . preg_quote($selektor, '/') . '\s*\{([^}]*)\}/', $this->handyRegeln(), $m);
        $this->assertSame(1, $ok, "Keine Handy-Regel fuer $selektor.");

        return $m[1];
    }

    public function test_der_vertragstext_traegt_die_lese_klasse(): void
    {
        $this->assertMatchesRegularExpression(
            '/<div class="[^"]*\bvertrag-text\b[^"]*">\s*\{!! \$contractContent !!\}/',
            $this->blade(),
            'Der Vertragstext steht nicht in der Box mit der Klasse vertrag-text — die Handy-Regeln greifen nicht.'
        );
    }

    public function test_am_handy_keine_scrollbox_mit_fester_hoehe(): void
    {
        $regel = $this->regel('.vertrag-text');

        $this->assertMatchesRegularExpression('/max-height:\s*none/', $regel);
        $this->assertMatchesRegularExpression('/overflow:\s*visible/', $regel);
    }

    public function test_am_handy_lesbare_schrift(): void
    {
        $regel = $this->regel('.vertrag-text');

        // >= 16px: 1rem oder mehr, und eine Zeilenhoehe um 1.5.
        $this->assertMatchesRegularExpression('/font-size:\s*(1(\.\d+)?rem|1[6-9]px)/', $regel);
        $this->assertMatchesRegularExpression('/line-height:\s*1\.[5-7]/', $regel);
    }

    public function test_am_handy_scrollen_breite_tabellen_in_sich_selbst(): void
    {
        $regel = $this->regel('.vertrag-text table');

        $this->assertMatchesRegularExpression('/overflow-x:\s*auto/', $regel);
        $this->assertMatchesRegularExpression('/max-width:\s*100%/', $regel);
    }

    public function test_am_handy_springt_ein_link_zur_unterschrift(): void
    {
        $this->assertStringContainsString('id="unterschrift"', $this->blade());
        $this->assertStringContainsString('href="#unterschrift"', $this->blade());
        // Nur am Handy: ausserhalb des Blocks ausgeblendet.
        $this->assertMatchesRegularExpression('/\.vertrag-sprung\s*\{[^}]*display:\s*none/', $this->blade());
    }

    /** Der Desktop bleibt wie er war: die alte Box steht unveraendert im Markup. */
    public function test_am_desktop_bleibt_die_box(): void
    {
        $this->assertMatchesRegularExpression(
            '/<div class="[^"]*\bmax-h-\[60vh\][^"]*\boverflow-y-auto\b[^"]*\bvertrag-text\b/',
            $this->blade()
        );
    }

    /** Zoomen bleibt erlaubt — weder die Seite noch das Gast-Layout sperren es. */
    public function test_zoomen_bleibt_erlaubt(): void
    {
        $this->assertDoesNotMatchRegularExpression('/user-scalable|maximum-scale/', $this->blade());

        $layout = dirname(__DIR__, 5) . '/meingedeck/vendor/martin3r/platform-core/resources/views/layouts/guest.blade.php';
        if (!is_file($layout)) {
            $this->markTestIncomplete('Gast-Layout aus platforms-core nicht gefunden: ' . $layout);
        }
        $this->assertDoesNotMatchRegularExpression(
            '/user-scalable\s*=\s*(no|0)|maximum-scale\s*=\s*1(\.0)?\b/',
            (string) file_get_contents($layout)
        );
    }
}
