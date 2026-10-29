<?php

namespace Platform\Recruiting\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Portal-Feinschliff 29.10.2026: Verträge und Nachweise einklappbar —
 * zu, wenn nichts zu tun ist; offen, wenn etwas wartet. Vertrag mit
 * Laufzeit. Vertrag an der Quelle (Render braucht den App-Boot).
 */
class PortalDokumenteKlappTest extends TestCase
{
    private static function blade(): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2) . '/resources/views/livewire/public/portal-shell.blade.php');
    }

    public function test_vertraege_und_nachweise_klappen_ein(): void
    {
        $b = self::blade();
        $this->assertSame(2, substr_count($b, '<details class="klapp"'), 'Verträge und Nachweise');
        // Livewire darf den vom Menschen gewaehlten Zustand nicht bei jedem Update zuruecksetzen.
        $this->assertSame(2, substr_count($b, 'wire:ignore.self'), 'beide details mit wire:ignore.self');
        $this->assertStringContainsString('$vertraegeOffen = ', $b);
        $this->assertStringContainsString('$nachweiseOffen = ', $b);
        $this->assertStringContainsString("\$dok['zeitraum'] ?? null", $b);
    }

    public function test_klapp_stil_steht_im_layout(): void
    {
        $css = (string) file_get_contents(dirname(__DIR__, 2) . '/resources/views/layouts/portal.blade.php');
        $this->assertStringContainsString('details.klapp > summary', $css);
    }
}
