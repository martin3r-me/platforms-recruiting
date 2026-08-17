<?php

namespace Platform\Recruiting\Tests\Integration;

use Illuminate\Container\Container;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Events\Dispatcher;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Http\Controllers\TrainingCertificatePdfController;
use Platform\Recruiting\Support\TrainingCertificateWaTemplate;

/**
 * Was routes/public.php ueber das Zertifikat-PDF zusagt.
 *
 * Geprueft wird die REGISTRIERUNG, indem die echte Routendatei gegen einen von
 * Hand gebauten Router geladen wird. Ein Feature-Test waere der direktere Weg,
 * geht in diesem Modul aber nicht: die Suite bootet kein Laravel und hat kein
 * testbench (Modulkonvention, tests/bootstrap.php ist ein reiner Autoloader).
 * Die Alternative — die Datei als Text nach dem Routennamen durchsuchen — waere
 * kein Nachweis: ein Treffer in einem Kommentar oder in auskommentiertem Code
 * haette denselben Effekt gehabt.
 *
 * BEWUSSTE LUECKE, benannt statt behauptet: Prefix ("recruiting") und Middleware
 * ("web" + NoCacheHeaders) kommen NICHT aus dieser Datei, sondern aus der Gruppe
 * in RecruitingServiceProvider.php:127. Dieser Test sagt darueber nichts. Wer
 * die Gruppe dort aendert, faellt hier nicht auf.
 *
 * PROZESSWEITER ZUSTAND: diese Klasse setzt eine Facade-Wurzel (Route) und muss
 * sie deshalb selbst wieder wegraeumen — Facade::$app und die aufgeloesten
 * Instanzen sind statisch, der Schaden trifft SPAETERE Testklassen und faellt
 * NUR im Gesamtlauf auf, nie im gefilterten. Muster:
 * PlaceholderResolutionPinTest.
 */
class TrainingCertificatePublicRouteTest extends TestCase
{
    private const ROUTE_NAME = 'recruiting.public.training-certificate';

    /** Die Routen, die es vor dem Zertifikat schon gab. */
    private const BESTAND = [
        'recruiting.public.applicant-form',
        'recruiting.public.interview-booking',
        'recruiting.public.contract-signing',
        'recruiting.public.applicant-portal',
        'recruiting.public.employee-portal',
        'recruiting.public.contract-pdf',
    ];

    /**
     * NACH dem Zertifikat dazugekommen. Jede Zeile hier ist eine bewusste
     * Entscheidung fuer eine weitere OEFFENTLICHE Route — genau das, was die
     * Assertion unten erzwingen soll.
     *
     * 'employee-assignments': oeffentliche Einsatz-Seite mit Sammel-Bestaetigen,
     * token-only (commit d917e1a). Sie hat diesen Test rot gemacht, und das war
     * seine Aufgabe: eine neue Route, die allein mit einem Token erreichbar ist,
     * soll nicht nebenbei mitlaufen. Eingetragen heisst hier "gesehen und
     * gewollt", nicht "weggeklickt".
     */
    private const SPAETER = [
        'recruiting.public.employee-assignments',
    ];

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

    /**
     * Laedt routes/public.php gegen einen frischen Router — EINMAL pro Test.
     *
     * Frisch pro Test, nicht einmal pro Klasse: derselbe Router zweimal beladen
     * registrierte jede Route doppelt, und dann sagte eine Zaehlung nichts mehr.
     *
     * Aber auch nur EINMAL pro Test, und das ist der sichere Weg, den diese
     * Memoisierung vorgibt: ein zweiter Aufruf baute einen zweiten Router und
     * einen zweiten Container, waehrend die Route-Facade ihre bereits
     * aufgeloeste Instanz behielt — die Routen landeten dann im ERSTEN Router
     * und der zweite blieb leer. Gemessen ist genau das passiert: der Test
     * meldete "routes/public.php hat keine benannte Route registriert", obwohl
     * die sechs Bestandsrouten da waren. Wer hier eine weitere Ladestelle
     * braucht, gibt den Router als Parameter weiter statt ihn neu zu bauen.
     */
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

        // require, nicht require_once: die Datei ruft nur Route::get() auf und
        // muss in jedem Test erneut ausgefuehrt werden. Mit require_once waere
        // der zweite Test lautlos ohne Routen gelaufen.
        require __DIR__ . '/../../routes/public.php';

        // PFLICHT und der eigentliche Grund, warum dieser Test einmal komplett
        // rot war, ohne dass an den Routen etwas fehlte: ->name() laeuft NACH
        // dem Hinzufuegen zur Sammlung, die Namensliste ist zu diesem Zeitpunkt
        // also noch leer. Ohne diesen Aufruf liefert getByName() fuer JEDE Route
        // null — der Test haette "Route nicht registriert" gemeldet und man
        // haette in routes/public.php gesucht. In der Host-App uebernimmt das
        // Laravels Boot-Reihenfolge, hier niemand.
        $router->getRoutes()->refreshNameLookups();

        if ($router->getRoutes()->getRoutesByName() === []) {
            self::fail(
                'routes/public.php hat keine benannte Route registriert. Das ist selbst '
                . 'der Befund: entweder ist die Datei leer, oder das Laden ist '
                . 'fehlgeschlagen. Ohne diesen Abbruch liefen alle Assertionen dieser '
                . 'Klasse gegen eine leere Sammlung.'
            );
        }

        return $router;
    }

    private function route(string $name): RoutingRoute
    {
        $route = $this->router()->getRoutes()->getByName($name);

        if ($route === null) {
            $vorhanden = array_keys($this->router()->getRoutes()->getRoutesByName());
            self::fail(sprintf(
                'Route "%s" ist nicht registriert. Registriert sind: %s',
                $name,
                $vorhanden === [] ? '(keine)' : implode(', ', $vorhanden)
            ));
        }

        return $route;
    }

    public function testZertifikatRouteIstRegistriert(): void
    {
        $route = $this->route(self::ROUTE_NAME);

        $this->assertSame('zertifikat/{uuid}', $route->uri());
        $this->assertContains('GET', $route->methods());
        $this->assertSame(
            TrainingCertificatePdfController::class . '@__invoke',
            $route->getActionName()
        );
    }

    /**
     * Der Kern der Sicherheitszusage der Spec, als Assertion statt als Satz im
     * Docblock: das Zertifikat haengt an einer eigenen uuid und NICHT am
     * Applicant-Token. Der Token oeffnet auch Bewerbungsformular und
     * Vertrags-PDFs, unbegrenzt und ohne Rotation — ihn per WhatsApp aktiv
     * erneut zu versenden waere eine neu geoeffnete Tuer in eine bestehende
     * Luecke.
     *
     * Geprueft wird der Parametername, nicht nur "enthaelt kein token": ein
     * Umbau auf {token} waere genau der naheliegende Fehler, und er wuerde die
     * Route funktionsfaehig lassen.
     */
    public function testZertifikatRouteHaengtNichtAmApplicantToken(): void
    {
        $route = $this->route(self::ROUTE_NAME);

        $this->assertSame(['uuid'], $route->parameterNames());
    }

    /**
     * Die Form, die bei Meta in der Button-URL stehen muss — abgeleitet, nicht
     * getippt.
     *
     * DIE ERWARTUNG STEHT IM TEST UND NICHT IM PRODUKTIVCODE (Spec W7/B1): eine
     * Konstante mit dieser Form waere bei einem Praefix- oder Pfadwechsel still
     * falsch geblieben. So wird dieser Test rot — und das ist die gewuenschte
     * Wirkung, denn dann muss die Button-URL im Meta-Manager nachgezogen werden
     * (Spec T1). Was dort wirklich hinterlegt ist, sieht kein Test.
     *
     * Das Praefix wird hier gesetzt wie in RecruitingServiceProvider.php:128 —
     * der router()-Helfer dieser Klasse laedt bewusst ohne, weil er die
     * Registrierung IN routes/public.php prueft.
     *
     * DAS AUFRAEUMEN STEHT IM finally, nicht am Ende des Testkoerpers: bei einer
     * roten Assertion wird der Rest der Methode nicht mehr ausgefuehrt, und die
     * Facade-Wurzel bliebe fuer SPAETERE Testklassen stehen — der Schaden faellt
     * dann nur im Gesamtlauf auf und sieht wie ein fremder Fehler aus.
     */
    public function testFormDerMetaButtonUrlKommtAusDerRoute(): void
    {
        $container = new Container();
        Container::setInstance($container);
        $router = new Router(new Dispatcher($container), $container);
        $container->instance('router', $router);
        Facade::setFacadeApplication($container);

        try {
            $router->prefix('recruiting')->group(function () {
                require dirname(__DIR__, 2) . '/routes/public.php';
            });
            $router->getRoutes()->refreshNameLookups();

            $url = new \Illuminate\Routing\UrlGenerator(
                $router->getRoutes(),
                \Illuminate\Http\Request::create('https://mitarbeiter.rheingedeck.de')
            );

            $mitSentinel = $url->route(
                TrainingCertificateWaTemplate::ROUTE_NAME,
                ['uuid' => TrainingCertificateWaTemplate::UUID_SENTINEL]
            );

            $this->assertSame(
                'https://mitarbeiter.rheingedeck.de/recruiting/zertifikat/{{1}}',
                TrainingCertificateWaTemplate::metaButtonUrlFrom($mitSentinel),
                'Aendert sich Praefix oder Pfad, muss die Button-URL bei Meta nachgezogen werden.'
            );
        } finally {
            Facade::setFacadeApplication(null);
            Container::setInstance(null);
        }
    }

    /**
     * Die Bestandsrouten muessen alle noch da sein. Ohne diese Assertion waere
     * ein Syntaxfehler oder eine verlorene Zeile in routes/public.php nur
     * dadurch aufgefallen, dass die Zertifikat-Route fehlt — und man haette am
     * falschen Ende gesucht.
     */
    public function testBestandsroutenBleibenRegistriert(): void
    {
        $namen = array_keys($this->router()->getRoutes()->getRoutesByName());

        foreach (self::BESTAND as $name) {
            $this->assertContains($name, $namen);
        }

        // Genau der Bestand plus die eine neue: eine zusaetzliche oeffentliche
        // Route ist in diesem Modul eine Entscheidung und soll hier auffallen,
        // nicht nebenbei mitlaufen.
        sort($namen);
        $erwartet = array_merge(self::BESTAND, self::SPAETER, [self::ROUTE_NAME]);
        sort($erwartet);

        $this->assertSame($erwartet, $namen);
    }
}
