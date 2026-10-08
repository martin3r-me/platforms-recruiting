<?php

namespace Platform\Recruiting\Tests\Integration;

use Illuminate\Container\Container;
use Illuminate\Events\Dispatcher;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Routing\UrlGenerator;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Http\Controllers\DokumentDownloadController;
use Platform\Recruiting\Services\PortalAuth;

final class DokumentDownloadControllerTest extends TestCase
{
    public function test_sitzung_deckt_eigene_oder_schwester_anstellung(): void
    {
        $sitzung = [PortalAuth::sessionKey(160) => true];
        $hat = fn (string $key) => isset($sitzung[$key]);

        $this->assertTrue(DokumentDownloadController::sitzungDeckt([160, 223], $hat));
        $this->assertTrue(DokumentDownloadController::sitzungDeckt([223, 160], $hat));
        $this->assertFalse(DokumentDownloadController::sitzungDeckt([223], $hat));
        $this->assertFalse(DokumentDownloadController::sitzungDeckt([], $hat));
    }

    public function test_route_ist_registriert_und_traegt_die_uuid_am_ende(): void
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
            'https://mitarbeiter.rheingedeck.de/recruiting/mitarbeiter/dokument/0192a3b4-0000-7000-8000-000000000001',
            $url->route('recruiting.public.dokument', ['uuid' => '0192a3b4-0000-7000-8000-000000000001'])
        );
        Facade::clearResolvedInstances();
    }
}
