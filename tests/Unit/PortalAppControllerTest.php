<?php

namespace Platform\Recruiting\Tests\Unit;

use Illuminate\Container\Container;
use Illuminate\Events\Dispatcher;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Routing\UrlGenerator;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Http\Controllers\PortalAppController;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Portal als App (Spec Jobs anbieten 6.6, Markus 13.11.2026): Manifest-Inhalt,
 * Service Worker und Symbole kommen ueber Modul-Routen, nie ueber publizierte
 * Dateien. Der Token-Abgleich des Manifests steht in Integration/PortalPushAboTest.
 */
class PortalAppControllerTest extends TestCase
{
    public function test_manifest_daten_startet_im_portal_des_tokens(): void
    {
        $m = PortalAppController::manifestDaten('abc-123');

        $this->assertSame('Mein Rheingedeck', $m['name']);
        $this->assertSame('Rheingedeck', $m['short_name']);
        $this->assertSame('standalone', $m['display']);
        $this->assertSame('/recruiting/', $m['scope']);
        $this->assertSame('/recruiting/mitarbeiter/neu/abc-123', $m['start_url']);
        $this->assertSame('#0e535e', $m['theme_color']);
        $this->assertSame('#EDF1F2', $m['background_color']);
        $this->assertSame(
            ['/recruiting/portal-app/icon-192.png', '/recruiting/portal-app/icon-512.png'],
            array_column($m['icons'], 'src')
        );
        $this->assertSame(['192x192', '512x512'], array_column($m['icons'], 'sizes'));
    }

    public function test_service_worker_mit_beiden_kopfzeilen_und_ohne_seiten_cache(): void
    {
        $antwort = (new PortalAppController())->sw();

        $this->assertSame('/recruiting/', $antwort->headers->get('Service-Worker-Allowed'));
        $this->assertStringStartsWith('application/javascript', (string) $antwort->headers->get('Content-Type'));
        $js = (string) $antwort->getContent();
        $this->assertStringContainsString("addEventListener('push'", $js);
        $this->assertStringContainsString("addEventListener('notificationclick'", $js);
        $this->assertStringNotContainsString("'fetch'", $js, 'kein Abfangen von Seiten');
        $this->assertStringNotContainsString('caches.', $js, 'kein Caching');
    }

    public function test_symbole_sind_echte_pngs_in_der_genannten_groesse(): void
    {
        foreach (['192', '512'] as $groesse) {
            $antwort = (new PortalAppController())->icon($groesse);
            $this->assertSame('image/png', $antwort->headers->get('Content-Type'));
            $info = getimagesizefromstring((string) $antwort->getContent());
            $this->assertNotFalse($info);
            $this->assertSame([(int) $groesse, (int) $groesse, IMAGETYPE_PNG], [$info[0], $info[1], $info[2]]);
        }
    }

    public function test_unbekannte_symbolgroesse_ist_404(): void
    {
        $this->expectException(NotFoundHttpException::class);
        (new PortalAppController())->icon('../../composer.json');
    }

    public function test_routen_sind_unter_recruiting_registriert(): void
    {
        $container = new Container();
        $router = new Router(new Dispatcher($container), $container);
        $container->instance('router', $router);
        Facade::setFacadeApplication($container);
        $router->prefix('recruiting')->group(function () {
            require dirname(__DIR__, 2) . '/routes/public.php';
        });
        $router->getRoutes()->refreshNameLookups();
        $url = new UrlGenerator($router->getRoutes(), Request::create('https://mitarbeiter.rheingedeck.de'));

        $this->assertSame(
            'https://mitarbeiter.rheingedeck.de/recruiting/portal-app/manifest/0192a3b4-0000-7000-8000-000000000001.webmanifest',
            $url->route('recruiting.public.portal-app.manifest', ['token' => '0192a3b4-0000-7000-8000-000000000001'])
        );
        $this->assertSame('https://mitarbeiter.rheingedeck.de/recruiting/portal-app/sw.js', $url->route('recruiting.public.portal-app.sw'));
        $this->assertSame('https://mitarbeiter.rheingedeck.de/recruiting/portal-app/icon-512.png', $url->route('recruiting.public.portal-app.icon', ['size' => '512']));

        // Der Token-Parameter frisst die Endung nicht mit.
        $treffer = $router->getRoutes()->match(Request::create('/recruiting/portal-app/manifest/abc-123.webmanifest'));
        $this->assertSame('abc-123', $treffer->parameter('token'));
        $treffer = $router->getRoutes()->match(Request::create('/recruiting/portal-app/icon-192.png'));
        $this->assertSame('192', $treffer->parameter('size'));

        Facade::clearResolvedInstances();
    }
}
