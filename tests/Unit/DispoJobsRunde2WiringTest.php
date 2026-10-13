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

    public function test_fortschritt_steht_ueber_den_schichten_und_offene_kommen_zuerst(): void
    {
        $src = self::datei('src/Livewire/Dispo/Jobs/Show.php');
        $this->assertStringContainsString('DispoJobFortschritt::berechnen($this->schichten)', $src);

        $view = self::datei('resources/views/livewire/dispo/jobs/show.blade.php');
        $kopf = strpos($view, '{{ $fortschritt[\'zugesagt\'] }}</strong> von');
        $liste = strpos($view, 'array_merge($fortschritt[\'offen\'], $fortschritt[\'voll\'])');
        $this->assertNotFalse($kopf);
        $this->assertNotFalse($liste);
        $this->assertLessThan($liste, $kopf, 'Fortschritt vor der Liste');
    }
}
