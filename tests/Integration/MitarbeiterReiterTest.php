<?php

namespace Platform\Recruiting\Tests\Integration;

use Illuminate\Container\Container;
use Illuminate\Events\Dispatcher;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Routing\UrlGenerator;
use Illuminate\Support\Facades\Facade;
use Illuminate\View\Compilers\BladeCompiler;
use Illuminate\View\Engines\CompilerEngine;
use Illuminate\View\Engines\EngineResolver;
use Illuminate\View\Factory as ViewFactory;
use Illuminate\View\FileViewFinder;
use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Livewire\Sidebar;

/**
 * Mitarbeiter / Dokumente / Lohnaenderungen sind Reiter EINES Bereichs:
 * gemeinsames Partial auf allen drei Seiten (je mit dem eigenen Reiter aktiv),
 * die beiden alten Seitenleisten-Eintraege sind weg.
 */
final class MitarbeiterReiterTest extends TestCase
{
    private const VIEWS = __DIR__ . '/../../resources/views/livewire/';

    private string $cacheDir;

    protected function setUp(): void
    {
        parent::setUp();
        $container = Container::getInstance();
        Facade::setFacadeApplication($container);
        Facade::clearResolvedInstances();
        $router = new Router(new Dispatcher($container), $container);
        $container->instance('router', $router);
        $router->prefix('recruiting')->group(function () {
            require dirname(__DIR__, 2) . '/routes/web.php';
        });
        $router->getRoutes()->refreshNameLookups();
        $container->instance('url', new UrlGenerator($router->getRoutes(), Request::create('https://meingedeck.de')));
        $this->cacheDir = sys_get_temp_dir() . '/reiter-render-' . getmypid() . '-' . uniqid();
        @mkdir($this->cacheDir, 0777, true);
    }

    protected function tearDown(): void
    {
        $c = Container::getInstance();
        $c->forgetInstance('router');
        $c->forgetInstance('url');
        Facade::clearResolvedInstances();
        exec('rm -rf ' . escapeshellarg($this->cacheDir));
        parent::tearDown();
    }

    private function rendern(string $reiter, array $daten = []): string
    {
        $files = new Filesystem();
        $compiler = new BladeCompiler($files, $this->cacheDir);
        $resolver = new EngineResolver();
        $resolver->register('blade', fn () => new CompilerEngine($compiler, $files));
        $finder = new FileViewFinder($files, []);
        $finder->addNamespace('recruiting', [dirname(__DIR__, 2) . '/resources/views']);
        $factory = new ViewFactory($resolver, $finder, new Dispatcher(new Container()));
        $factory->addExtension('blade.php', 'blade');

        return $factory->make('recruiting::livewire.employees._reiter', ['reiter' => $reiter] + $daten)->render();
    }

    /** @return array<string, string> reiter => aria-current */
    private function zustand(string $html): array
    {
        preg_match_all('/data-reiter="([a-z]+)" aria-current="([a-z]+)"/', $html, $m, PREG_SET_ORDER);

        return array_column($m, 2, 1);
    }

    public function test_partial_zeigt_drei_reiter_mit_dem_aktiven_hervorgehoben(): void
    {
        foreach (['mitarbeiter', 'dokumente', 'lohn'] as $aktiv) {
            $html = $this->rendern($aktiv);
            $erwartet = ['mitarbeiter' => 'false', 'dokumente' => 'false', 'lohn' => 'false'];
            $erwartet[$aktiv] = 'page';
            $this->assertSame($erwartet, $this->zustand($html), "aktiv: {$aktiv}");
            $this->assertSame(1, substr_count($html, 'border-blue-600'), 'genau ein Reiter hervorgehoben');
        }
        $html = $this->rendern('mitarbeiter');
        $this->assertStringContainsString('href="https://meingedeck.de/recruiting/employees"', $html);
        $this->assertStringContainsString('href="https://meingedeck.de/recruiting/employees/documents"', $html);
        $this->assertStringContainsString('href="https://meingedeck.de/recruiting/employees/payroll-changes"', $html);
        $this->assertStringContainsString('>Lohnänderungen', $html);
    }

    public function test_zaehler_offener_lohnaenderungen_nur_am_lohn_reiter_und_nur_wenn_etwas_offen_ist(): void
    {
        foreach (['mitarbeiter', 'dokumente', 'lohn'] as $aktiv) {
            $html = $this->rendern($aktiv, ['lohnOffen' => 7]);
            $this->assertSame(1, substr_count($html, 'data-reiter-zaehler'), "aktiv: {$aktiv}");
            $this->assertMatchesRegularExpression('/data-reiter="lohn"[^>]*>Lohnänderungen\s*<span data-reiter-zaehler[^>]*>7<\/span>\s*<\/a>/', $html);
        }
        $this->assertStringNotContainsString('data-reiter-zaehler', $this->rendern('lohn', ['lohnOffen' => 0]));
        $this->assertStringNotContainsString('data-reiter-zaehler', $this->rendern('lohn'), 'ohne Variable kein Zaehler, kein Fehler');
    }

    public function test_alle_drei_seiten_reichen_den_zaehler_aus_derselben_quelle_wie_die_seitenleiste(): void
    {
        $src = __DIR__ . '/../../src/Livewire/';
        foreach (['Employees/Index.php', 'Employees/Documents.php', 'Employees/PayrollChanges.php'] as $datei) {
            $quelle = (string) file_get_contents($src . $datei);
            $this->assertMatchesRegularExpression("/'lohnOffen' => (self|PayrollChanges)::offeneAnzahl\(/", $quelle, $datei);
        }
        $this->assertStringContainsString("'pending_payroll_changes' => PayrollChanges::offeneAnzahl(\$teamId)", (string) file_get_contents($src . 'Sidebar.php'));
    }

    public function test_alle_drei_seiten_binden_das_partial_mit_ihrem_reiter_ein(): void
    {
        foreach ([
            'employees/index.blade.php'           => 'mitarbeiter',
            'employees/documents.blade.php'       => 'dokumente',
            'employees/payroll-changes.blade.php' => 'lohn',
        ] as $datei => $reiter) {
            $quelle = (string) file_get_contents(self::VIEWS . $datei);
            $this->assertSame(1, substr_count($quelle, "@include('recruiting::livewire.employees._reiter', ['reiter' => '{$reiter}'])"), $datei);
            $this->assertSame(1, substr_count($quelle, '_reiter'), "{$datei}: kein zweites Partial");
        }
    }

    public function test_seitenleiste_ohne_dokumente_und_lohn_eintrag_mitarbeiter_bleibt_aktiv_fuer_den_bereich(): void
    {
        $quelle = (string) file_get_contents(self::VIEWS . 'sidebar.blade.php');
        $this->assertStringNotContainsString("route('recruiting.employees.documents')", $quelle);
        $this->assertStringNotContainsString("route('recruiting.employees.payroll-changes')", $quelle);
        $this->assertStringNotContainsString('Lohnrelevante Änderungen', $quelle);
        $this->assertStringContainsString(":href=\"route('recruiting.employees.index')\" :active=\"\$mitarbeiterBereichAktiv\"", $quelle);
        $this->assertSame(
            ['recruiting.employees.index', 'recruiting.employees.documents', 'recruiting.employees.payroll-changes'],
            Sidebar::MITARBEITER_BEREICH
        );
    }
}
