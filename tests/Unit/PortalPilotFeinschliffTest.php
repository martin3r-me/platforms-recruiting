<?php

namespace Platform\Recruiting\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Pilot-Review Mitarbeiterportal (28.10.2026). Vertrag an der Quelle — das
 * Portal rendert nur mit App-Boot (Muster SharedPartialContractTest).
 */
class PortalPilotFeinschliffTest extends TestCase
{
    private static function datei(string $pfad): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2) . '/' . $pfad);
    }

    /** Blaetter hoeher als der Bildschirm (Adresse, Unterschrift, offene Tastatur) muessen scrollen. */
    public function test_blaetter_sind_am_handy_scrollbar(): void
    {
        $css = self::datei('resources/views/layouts/portal.blade.php');
        $start = strpos($css, '.portal-body .upload-sheet {');
        $this->assertNotFalse($start);
        $regel = substr($css, $start, (int) strpos($css, '}', $start) - $start);

        $this->assertStringContainsString('max-height: 90dvh', $regel);
        $this->assertStringContainsString('overflow-y: auto', $regel);
    }

    /** Speichern erst, wenn das Foto oben ist — sonst irrefuehrend „Datei zu gross“. Meldung sichtbar auf Start und Profil. */
    public function test_speichern_wartet_auf_den_upload_und_meldung_wird_gezeigt(): void
    {
        $blade = self::datei('resources/views/livewire/public/portal-shell.blade.php');
        $this->assertStringContainsString('wire:target="speichereNachweis,uploadDatei,uploadDateiRueckseite"', $blade);
        $this->assertGreaterThanOrEqual(2, substr_count($blade, '{{ $nachweisMeldung }}'), 'Start und Profil');
    }
}
