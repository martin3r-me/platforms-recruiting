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

    public function test_eintragen_knoepfe_haengen_am_baustein(): void
    {
        $src = self::datei('src/Livewire/Dispo/Jobs/Show.php');
        $this->assertStringContainsString('DispoJobZasHaken::alle($this->anfrage(), $shiftId', $src);
        $this->assertStringContainsString('DispoJobZasHaken::umschalten($this->anfrage(), $offerShiftId', $src);

        $view = self::datei('resources/views/livewire/dispo/jobs/show.blade.php');
        $this->assertStringContainsString('wire:click="alleEintragen({{ (int) $s->id }})"', $view);
        $this->assertStringContainsString('DispoJobZasListe::text(', $view);
        $this->assertStringContainsString('navigator.clipboard.writeText', $view);
    }

    public function test_neue_anfrage_hat_drei_schritte(): void
    {
        $src = self::datei('src/Livewire/Dispo/Jobs/Create.php');
        $this->assertStringContainsString('if ($this->schritt >= 3) {', $src);
        $this->assertStringContainsString("if (\$this->schritt !== 3) {", $src);
        $this->assertStringContainsString('$this->schritt = 2;', $src, '?event= springt zu den Schichten');
        $this->assertStringNotContainsString('$this->schritt === 5', $src);
        $this->assertMatchesRegularExpression('/function updatedVon\(\): void\s*\{\s*\$this->hakenAngleichen\(\);/', $src);
        $this->assertMatchesRegularExpression('/function updatedBis\(\): void\s*\{\s*\$this->hakenAngleichen\(\);/', $src);
        $this->assertStringContainsString('DispoJobCreateState::hakenAngleichen($this->eventIds', $src);

        $view = self::datei('resources/views/livewire/dispo/jobs/create.blade.php');
        $this->assertStringContainsString('@if ($schritt === 3)', $view);
        $this->assertStringNotContainsString('@if ($schritt === 4)', $view);
        $this->assertStringContainsString('wire:model.live="filialNr"', $view);
        $this->assertStringContainsString('Wähle zuerst eine Filiale.', $view);

        $doku = self::datei('docs/anleitungen/dispo-jobs-anbieten.md');
        $this->assertStringContainsString('in drei Schritten', $doku);
        $this->assertStringNotContainsString('fünf Schritten', $doku);
    }
}
