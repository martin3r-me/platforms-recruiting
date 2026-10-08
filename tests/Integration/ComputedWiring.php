<?php

namespace Platform\Recruiting\Tests\Integration;

use Livewire\Component;
use Livewire\EventBus;
use Livewire\Features\SupportComputed\SupportLegacyComputedPropertySyntax;

/**
 * Verdrahtet #[Computed]-Getter einer von Hand gebauten Komponente (kein
 * Livewire-Mount, kein Testbench).
 *
 * Bis Livewire 3.8.1 registrierte jedes Computed-Attribut in boot() selbst
 * seinen __get-Hook; die Tests riefen boot() auf allen Attributen. Seit
 * 3.8.10 gibt es dieses boot() nicht mehr — die Aufloesung liegt im
 * Component-Hook SupportLegacyComputedPropertySyntax, den in Produktion der
 * LivewireServiceProvider registriert. In der Capsule-Suite laeuft kein
 * ServiceProvider, also registrieren wir den Hook hier selbst — genau einmal
 * je EventBus-Instanz: on() haengt bei jedem Aufruf einen weiteren Listener
 * an (jeder Getter wuerde mehrfach ausgewertet), und die Testklassen binden
 * und vergessen den EventBus-Singleton je Klasse neu, ein Prozess-Flag
 * wuerde den frischen Bus leer lassen.
 *
 * Die boot()-Schleife bleibt fuer Attribute, die sie noch haben
 * (z. B. Locked), und fuer den Rueckweg auf eine aeltere Livewire-Version.
 */
final class ComputedWiring
{
    /** @var \WeakMap<EventBus, true>|null */
    private static ?\WeakMap $wiredBuses = null;

    public static function wire(Component $c): void
    {
        self::$wiredBuses ??= new \WeakMap();
        $bus = app(EventBus::class);

        if (! isset(self::$wiredBuses[$bus]) && method_exists(SupportLegacyComputedPropertySyntax::class, 'provide')) {
            SupportLegacyComputedPropertySyntax::provide();
            self::$wiredBuses[$bus] = true;
        }

        $c->getAttributes()->each(function ($attribute) {
            if (method_exists($attribute, 'boot')) {
                $attribute->boot();
            }
        });
    }
}
