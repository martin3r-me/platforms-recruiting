<?php

namespace Platform\Recruiting\Tests\Integration;

use Illuminate\Container\Container;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Events\Dispatcher;
use Illuminate\Http\Request;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;

/**
 * Fixrunde 1, Aufgabe 4 (Fehlbefund am urspruenglichen /mitarbeiter/{token}/neu):
 * Meta-URL-Buttons erlauben die dynamische Variable NUR als Suffix — der Token
 * MUSS am Ende der Adresse stehen, sonst ist die Route per WhatsApp-Knopf nicht
 * erreichbar. Dieser Test haelt zwei Dinge fest, die sich sonst wieder
 * verschieben koennten:
 *
 *  1. Die Adresse von recruiting.public.portal-shell endet auf {token}.
 *  2. Sie kollidiert NICHT mit recruiting.public.employee-portal
 *     (/mitarbeiter/{token}, ein Segment) — geprueft nicht nur am
 *     URI-Muster, sondern an echten Request-Treffern gegen den geladenen
 *     Router. Laravel matcht {token} standardmaessig nur gegen EIN
 *     Pfadsegment ohne Slash; eine zweisegmentige Adresse kann die
 *     einsegmentige Route also strukturell nie treffen, unabhaengig von der
 *     Registrierungsreihenfolge.
 *
 * Lade- und Aufraeum-Muster 1:1 aus TrainingCertificatePublicRouteTest
 * (routes/public.php wird ohne den "recruiting"-Praefix aus
 * RecruitingServiceProvider geladen — der kommt aus der Route::prefix()-Gruppe
 * in boot(), nicht aus dieser Datei).
 */
class PortalTokenRouteTest extends TestCase
{
    private ?Container $container = null;

    private ?Router $router = null;

    protected function setUp(): void
    {
        Facade::clearResolvedInstances();
    }

    protected function tearDown(): void
    {
        Facade::setFacadeApplication(null);
        Facade::clearResolvedInstances();
        Model::clearBootedModels();
        $this->router = null;

        if ($this->container !== null) {
            Container::setInstance(null);
            $this->container = null;
        }
    }

    private function router(): Router
    {
        if ($this->router !== null) {
            return $this->router;
        }

        $container = new Container();
        Container::setInstance($container);
        $this->container = $container;

        $router = new Router(new Dispatcher($container), $container);
        $container->instance('router', $router);
        Facade::setFacadeApplication($container);
        $this->router = $router;

        require __DIR__ . '/../../routes/public.php';

        $router->getRoutes()->refreshNameLookups();

        return $router;
    }

    private function route(string $name): RoutingRoute
    {
        $route = $this->router()->getRoutes()->getByName($name);

        if ($route === null) {
            self::fail("Route \"{$name}\" ist nicht registriert.");
        }

        return $route;
    }

    public function testPortalShellRouteTraegtDenTokenAmEnde(): void
    {
        $route = $this->route('recruiting.public.portal-shell');

        $this->assertSame('mitarbeiter/neu/{token}', $route->uri());
        $this->assertTrue(
            str_ends_with($route->uri(), '/{token}'),
            'Der Token muss das LETZTE Segment der Adresse sein — Meta-URL-Buttons erlauben die Variable nur als Suffix.'
        );
    }

    public function testAltesMitarbeiterPortalBleibtEinsegmentig(): void
    {
        // Unveraendert durch die Fixrunde — nur der neue Pfad wurde umgebaut.
        $route = $this->route('recruiting.public.employee-portal');

        $this->assertSame('mitarbeiter/{token}', $route->uri());
    }

    public function testZweisegmentigeAdresseTrifftNurDieNeuePortalRoute(): void
    {
        $router = $this->router();

        $request = Request::create('/mitarbeiter/neu/PORTAL-TOKEN-XYZ', 'GET');
        $route = $router->getRoutes()->match($request);

        $this->assertSame('recruiting.public.portal-shell', $route->getName());
        $this->assertSame('PORTAL-TOKEN-XYZ', $route->parameter('token'));
    }

    public function testEinsegmentigeAdresseTrifftWeiterhinNurDasAltePortal(): void
    {
        // Die Nicht-Kollision positiv bewiesen: /mitarbeiter/neu OHNE weiteres
        // Segment hat nur EIN Segment ("neu") und kann daher nur die
        // einsegmentige Route (employee-portal) treffen — die zweisegmentige
        // portal-shell-Route kann so nie erreicht werden. "neu" landet hier
        // als ganz gewoehnlicher (nie existierender) Token, genau wie jeder
        // andere Einzel-Wert auch — keine Sonderbehandlung noetig.
        $router = $this->router();

        $request = Request::create('/mitarbeiter/neu', 'GET');
        $route = $router->getRoutes()->match($request);

        $this->assertSame('recruiting.public.employee-portal', $route->getName());
        $this->assertSame('neu', $route->parameter('token'));
    }
}
