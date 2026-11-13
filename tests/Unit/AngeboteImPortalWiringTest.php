<?php

namespace Platform\Recruiting\Tests\Unit;

use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Livewire\Public\JobOffers;
use Platform\Recruiting\Livewire\Public\PortalShell;

/**
 * Spec 2026-11-13 §6.5 (Markus 13.11.2026): Angebote im Portal — Verdrahtung
 * der Blades und der Komponenten. Die Zahl selbst prueft JobOffersLadenTest.
 */
class AngeboteImPortalWiringTest extends TestCase
{
    private function quelle(string $rel): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2) . '/' . $rel);
    }

    public function test_start_reiter_kennt_nur_die_vier_reiter(): void
    {
        foreach (['start', 'jobs', 'docs', 'me'] as $reiter) {
            $this->assertSame($reiter, PortalShell::startReiter($reiter));
        }
        foreach ([null, '', 'JOBS', 'jobs ', "jobs'", "'}; alert(1); {'", 'profil', ['jobs'], 7] as $roh) {
            $this->assertSame('start', PortalShell::startReiter($roh), 'unbekannt → Startseite: ' . var_export($roh, true));
        }
    }

    public function test_start_reiter_ist_gesperrt_und_geht_ohne_rohwert_an_alpine(): void
    {
        $eigenschaft = new \ReflectionProperty(PortalShell::class, 'startReiter');
        $this->assertNotSame([], $eigenschaft->getAttributes(Locked::class));

        $shell = $this->quelle('resources/views/livewire/public/portal-shell.blade.php');
        $this->assertStringContainsString('x-data="{ tab: @js($startReiter) }"', $shell);
        $this->assertStringNotContainsString("x-data=\"{ tab: 'start' }\"", $shell);
        $this->assertStringNotContainsString("request()->query('tab')", $shell, 'nie die rohe Query ins Blade');
    }

    public function test_portal_hoert_auf_angebote_geaendert(): void
    {
        $gefunden = false;
        foreach ((new \ReflectionClass(PortalShell::class))->getMethods() as $methode) {
            foreach ($methode->getAttributes(On::class) as $attr) {
                if (($attr->getArguments()[0] ?? null) === 'angebote-geaendert') {
                    $gefunden = true;
                }
            }
        }
        $this->assertTrue($gefunden, 'PortalShell muss auf angebote-geaendert neu rendern (Karte zaehlt neu)');

        $jobOffers = $this->quelle('src/Livewire/Public/JobOffers.php');
        $this->assertSame(2, substr_count($jobOffers, "\$this->dispatch('angebote-geaendert')"), 'nach Zusage und nach Absage');
    }

    public function test_job_offers_eingebettet_ist_gesperrt_und_ohne_kopf(): void
    {
        foreach (['eingebettet', 'duzen'] as $name) {
            $this->assertNotSame([], (new \ReflectionProperty(JobOffers::class, $name))->getAttributes(Locked::class), $name);
        }
        $param = (new \ReflectionMethod(JobOffers::class, 'mount'))->getParameters()[1] ?? null;
        $this->assertSame('eingebettet', $param?->getName());
        $this->assertFalse($param->getDefaultValue());

        $blade = $this->quelle('resources/views/livewire/public/job-offers.blade.php');
        $this->assertStringContainsString("\$eingebettet ? 'rg-crew eingebettet' : 'rg-crew'", $blade);
        $this->assertStringContainsString('@unless ($eingebettet)', $blade);
        $this->assertStringContainsString('Gerade gibt es keine freien Einsätze für dich.', $blade);
        $this->assertStringContainsString('Gerade gibt es keine freien Einsätze für Sie.', $blade);
        // Kopf und Gruss stehen nur auf der eigenstaendigen Seite.
        $kopf = strpos($blade, '<div class="appbar">');
        $gruss = strpos($blade, '<h1>Hallo {{ $firstName }}</h1>');
        $this->assertNotFalse($kopf);
        $this->assertNotFalse($gruss);
        $this->assertNotFalse(strrpos(substr($blade, 0, $kopf), '@unless ($eingebettet)'), 'appbar nur ohne Einbettung');
        $this->assertNotFalse(strrpos(substr($blade, 0, $gruss), '@unless ($eingebettet)'), 'Gruss nur ohne Einbettung');
    }

    public function test_portal_reiter_einsaetze_zeigt_oben_die_freien_einsaetze(): void
    {
        $shell = $this->quelle('resources/views/livewire/public/portal-shell.blade.php');
        $tag = '<livewire:recruiting.public.job-offers :token="$token" :eingebettet="true" lazy wire:key="angebote-im-portal" />';
        $pane = strpos($shell, "<div class=\"pane\" :class=\"tab === 'jobs' && 'on'\">");
        $angebote = strpos($shell, $tag);
        $einsaetze = strpos($shell, '<livewire:recruiting.public.employee-assignments');

        $this->assertNotFalse($pane);
        $this->assertNotFalse($angebote);
        $this->assertTrue($pane < $angebote && $angebote < $einsaetze, 'Freie Einsätze oben im Reiter jobs, darunter die Einsätze');
        $this->assertStringContainsString('Freie Einsätze', substr($shell, $pane, $angebote - $pane));
    }

    public function test_startseite_karte_ueber_dem_naechsten_einsatz(): void
    {
        $shell = $this->quelle('resources/views/livewire/public/portal-shell.blade.php');
        $karte = strpos($shell, '@if ($neueAngebote > 0)');
        $naechster = strpos($shell, "{{ \$duzen ? 'Dein nächster Einsatz' : 'Ihr nächster Einsatz' }}");
        $this->assertNotFalse($karte);
        $this->assertNotFalse($naechster);
        $this->assertLessThan($naechster, $karte);

        $block = substr($shell, $karte, $naechster - $karte);
        $this->assertStringContainsString("@click=\"tab = 'jobs'\"", $block);
        foreach (['1 neuer Einsatz für dich', '1 neuer Einsatz für Sie', ' neue Einsätze für dich', ' neue Einsätze für Sie'] as $text) {
            $this->assertStringContainsString($text, $block);
        }
    }

    /** Die Startkarte GERENDERT: Einzahl/Mehrzahl, du/Sie, ohne Angebote nichts. */
    public function test_startseite_karte_gerendert(): void
    {
        $shell = $this->quelle('resources/views/livewire/public/portal-shell.blade.php');
        $von = strpos($shell, '@if ($neueAngebote > 0)');
        $bis = strpos($shell, "<div class=\"sec-label\">{{ \$duzen ? 'Dein nächster Einsatz'");
        $schnipsel = substr($shell, $von, $bis - $von);

        $verzeichnis = sys_get_temp_dir() . '/recruiting-angebote-karte-' . getmypid();
        @mkdir($verzeichnis);
        $compiler = new \Illuminate\View\Compilers\BladeCompiler(new \Illuminate\Filesystem\Filesystem(), $verzeichnis);
        $datei = $verzeichnis . '/karte.php';
        file_put_contents($datei, $compiler->compileString($schnipsel));
        $render = static function (int $neueAngebote, bool $duzen) use ($datei): string {
            ob_start();
            include $datei;

            return (string) ob_get_clean();
        };

        try {
            $this->assertSame('', trim($render(0, true)));
            $eins = $render(1, true);
            $this->assertStringContainsString('1 neuer Einsatz für dich', $eins);
            $this->assertStringContainsString("@click=\"tab = 'jobs'\"", $eins);
            $this->assertStringContainsString('3 neue Einsätze für dich', $render(3, true));
            $this->assertStringContainsString('3 neue Einsätze für Sie', $render(3, false));
            $this->assertStringContainsString('1 neuer Einsatz für Sie', $render(1, false));
        } finally {
            @unlink($datei);
            @rmdir($verzeichnis);
        }
    }

    public function test_einsatz_seite_karte_nur_eigenstaendig_mit_link_auf_jobs(): void
    {
        $blade = $this->quelle('resources/views/livewire/public/employee-assignments.blade.php');
        $karte = strpos($blade, '@if ($this->neueAngebote > 0)');
        $this->assertNotFalse($karte);
        $this->assertStringContainsString("route('recruiting.public.job-offers', ['token' => \$token])", substr($blade, $karte, 800));

        $komponente = $this->quelle('src/Livewire/Public/EmployeeAssignments.php');
        $this->assertStringContainsString('JobOffersLaden::anzahl(', $komponente);
    }
}
