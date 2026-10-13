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
        $kopf = strpos($view, '<strong>{{ $fortschritt[\'besetzt\'] }}</strong> von {{ $fortschritt[\'gesucht\'] }} {{ $plaetze }} besetzt');
        $this->assertStringContainsString('@if ($fortschritt[\'zugesagt\'] > $fortschritt[\'besetzt\'])', $view);
        $this->assertStringContainsString('{{ $fortschritt[\'zugesagt\'] }} Zusagen insgesamt', $view);
        $this->assertStringContainsString('$fortschritt[\'prozent\'] >= 100 ? \'bg-green-500\'', $view, 'gruen erst, wenn jeder Platz besetzt ist');
        $liste = strpos($view, 'array_merge($fortschritt[\'offen\'], $fortschritt[\'voll\'])');
        $this->assertNotFalse($kopf);
        $this->assertNotFalse($liste);
        $this->assertLessThan($liste, $kopf, 'Fortschritt vor der Liste');
        $this->assertStringContainsString("\$kopfKlasse = \$voll ? '-mx-4 -mt-4 rounded-t-lg bg-green-50 px-4 pt-4 pb-2' : '';", $view, 'volle Schicht: Kopf gruen hinterlegt');
        $this->assertStringContainsString('<div class="flex flex-wrap items-center justify-between gap-2 {{ $kopfKlasse }}">', $view);
    }

    public function test_eintragen_knoepfe_haengen_am_baustein(): void
    {
        $src = self::datei('src/Livewire/Dispo/Jobs/Show.php');
        $this->assertStringContainsString('DispoJobZasHaken::alle($this->anfrage(), $shiftId', $src);
        $this->assertStringContainsString('DispoJobZasHaken::umschalten($this->anfrage(), $offerShiftId', $src);

        $view = self::datei('resources/views/livewire/dispo/jobs/show.blade.php');
        $this->assertStringContainsString('wire:click="alleEintragen({{ (int) $s->id }})"', $view);
        $this->assertStringContainsString('public function alleEintragen(int $shiftId): void', $src);
        $this->assertStringNotContainsString('abweichung', $src);
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

    public function test_endzeit_wird_ueberall_als_spanne_gezeigt(): void
    {
        $this->assertStringContainsString('DispoJobZeit::problem($von, $bis)', self::datei('src/Livewire/Dispo/Jobs/Create.php'));
        $this->assertStringContainsString('wire:model="neuBis"', self::datei('resources/views/livewire/dispo/jobs/create.blade.php'));
        $this->assertStringContainsString('DispoJobZeit::spanne($s->von, $s->bis)', self::datei('resources/views/livewire/dispo/jobs/show.blade.php'));
        $this->assertStringContainsString('DispoJobZeit::spanne($shift->von, $shift->bis)', self::datei('src/Livewire/Public/JobOffers.php'));
        $this->assertStringContainsString("\$shift['zeit']", self::datei('resources/views/livewire/public/job-offers.blade.php'));
    }

    public function test_badge_in_der_sidebar_haengt_am_zaehler(): void
    {
        $sidebar = self::datei('resources/views/livewire/sidebar.blade.php');
        $this->assertStringContainsString('DispoJobOffeneZusagen::count()', $sidebar);
        $this->assertStringContainsString('{{ $dispoJobsOffen }}', $sidebar);
        $this->assertStringContainsString("DispoJobOffeneZusagen::count(\$this->teamId())", self::datei('src/Livewire/Dispo/Jobs/Index.php'));
    }

    public function test_mitarbeiter_kann_zusagen_nicht_selbst_zuruecknehmen(): void
    {
        $src = self::datei('src/Livewire/Public/JobOffers.php');
        $this->assertStringNotContainsString('zuruecknehmen', $src);
        $this->assertStringNotContainsString('JobOffersRuecknahme', $src);

        $view = self::datei('resources/views/livewire/public/job-offers.blade.php');
        $this->assertStringNotContainsString('zuruecknehmen', $view);
        $this->assertStringNotContainsString('Doch nicht', $view);
        $this->assertStringContainsString('✓ Du bist dabei</div>', $view);
        $this->assertStringNotContainsString('zurückgezogen', self::datei('resources/views/livewire/dispo/jobs/show.blade.php'));
    }

    public function test_uebersicht_hat_filter_quote_und_noch_in_zas(): void
    {
        $src = self::datei('src/Livewire/Dispo/Jobs/Index.php');
        $this->assertMatchesRegularExpression('/#\[Url\]\s+public string \$filter = \'offen\';/', $src);
        $this->assertStringContainsString('DispoJobUebersichtZahlen::laden(', $src);

        $view = self::datei('resources/views/livewire/dispo/jobs/index.blade.php');
        $this->assertStringContainsString(">Besetzt</th>", $view);
        $this->assertStringNotContainsString(">Zusagen</th>", $view);
        $this->assertStringContainsString(">Noch in ZAS</th>", $view);
        $this->assertStringContainsString("{{ \$z['besetzt'] }} / {{ \$z['gesucht'] }}", $view);
        $this->assertStringContainsString("\$z['gesucht'] > 0 && \$z['besetzt'] >= \$z['gesucht']", $view);
        $this->assertStringContainsString("(+{{ \$z['zugesagt'] - \$z['besetzt'] }})", $view);
        $this->assertStringNotContainsString("{{ \$z['zugesagt'] }} / {{ \$z['gesucht'] }}", $view);
        $this->assertStringContainsString("\$set('filter', '{{ \$wert }}')", $view);
    }
}
