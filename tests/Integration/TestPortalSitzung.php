<?php

namespace Platform\Recruiting\Tests\Integration;

use Illuminate\Container\Container;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\Facade;
use Platform\Recruiting\Services\PortalAuth;

/**
 * "Angemeldet" fuer Tests, die eine PortalShell direkt auf 'verified' setzen:
 * seit Spec HR-Bereich §9.6 prueft berechtigterMitarbeiter() die Sitzung.
 * Legt den ALTWERT true ab (wie eine Sitzung von vor dem Deploy) und bindet
 * eine Array-Sitzung, falls die Testklasse keine hat.
 *
 * Aufraeumen ist Pflicht: jede Klasse, die oeffne() ruft, ruft in tearDown()
 * aufraeumen() — sonst lebt die hier gebundene Sitzung (samt Schluesseln) in
 * spaeteren Testklassen weiter (Reihenfolge-Falle).
 */
final class TestPortalSitzung
{
    /** Die von UNS gebundene Sitzung (null = die Testklasse hatte eine eigene). */
    private static ?Store $gebunden = null;

    /** @var array<int, true> Anstellungen, deren Schluessel wir gesetzt haben */
    private static array $gesetzt = [];

    public static function oeffne(int $employeeId): void
    {
        $c = Container::getInstance();
        if (!$c->bound('session')) {
            self::$gebunden = new Store('test', new ArraySessionHandler(60));
            $c->instance('session', self::$gebunden);
            Facade::clearResolvedInstance('session');
        }
        $c->make('session')->put(PortalAuth::sessionKey($employeeId), true);
        self::$gesetzt[$employeeId] = true;
    }

    public static function aufraeumen(): void
    {
        $c = Container::getInstance();
        if (self::$gebunden !== null) {
            if ($c->bound('session') && $c->make('session') === self::$gebunden) {
                $c->forgetInstance('session');
                // offsetUnset entfernt auch die Bindung, damit bound() wieder false ist.
                unset($c['session']);
            }
            Facade::clearResolvedInstance('session');
        } elseif ($c->bound('session')) {
            // Fremde Sitzung der Testklasse: nur unsere Schluessel wieder entfernen.
            foreach (array_keys(self::$gesetzt) as $id) {
                $c->make('session')->forget(PortalAuth::sessionKey($id));
            }
        }
        self::$gebunden = null;
        self::$gesetzt = [];
    }
}
