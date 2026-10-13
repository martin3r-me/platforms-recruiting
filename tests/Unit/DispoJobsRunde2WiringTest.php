<?php

namespace Platform\Recruiting\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Platzierungs-Vertraege Runde 2 „Jobs anbieten“ — die Livewire-Komponenten
 * booten in dieser Suite nicht; das Verhalten steht in den Bausteinen-Tests.
 */
final class DispoJobsRunde2WiringTest extends TestCase
{
    private static function datei(string $rel): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2) . '/' . $rel);
    }

    public function test_rueckmeldungen_laden_die_entscheidungshilfe_einmal_fuer_alle(): void
    {
        $src = self::datei('src/Livewire/Dispo/Jobs/Show.php');
        $this->assertStringContainsString('DispoJobZusagenInfo::laden(', $src);
        $this->assertStringNotContainsString("'kontakte' =>", $src);

        $view = self::datei('resources/views/livewire/dispo/jobs/show.blade.php');
        foreach (['>Einsätze</th>', '>Tätigkeit</th>', '>Bewertung</th>', 'DispoJobZusagenInfo::passt(', 'DispoJobZusagenInfo::bewertung('] as $s) {
            $this->assertStringContainsString($s, $view, $s);
        }
        $this->assertStringNotContainsString("\$rueck['kontakte']", $view);
    }
}
