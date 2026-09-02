<?php

namespace Platform\Recruiting\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Platform\Recruiting\Services\Zas\Dispo\DispoAccess;

/**
 * Route-Gate der Stufe "Nur Veranstaltungen" (siehe DispoAccess): fuer
 * gelistete Konten sind im Recruiting nur die Veranstaltungs-Routen
 * erreichbar, alles andere leitet dorthin um. Haengt in der web-Gruppe
 * (Provider), ist aber fuer alle Nicht-Recruiting-Routen und alle nicht
 * gelisteten Nutzer ein No-op.
 *
 * Livewire-Actions laufen nicht ueber recruiting.*-Routen — Mutationen der
 * VA-Seite sind deshalb ZUSAETZLICH in der Komponente selbst gesperrt
 * (Events/Show, blockedForEventOnly). Andere Komponenten sind unerreichbar,
 * weil ihre Seiten (und damit die Snapshots) hier geblockt werden.
 */
class DispoEventOnlyGate
{
    private const ALLOWED = [
        'recruiting.dispo.events.index',
        'recruiting.dispo.events.show',
        'recruiting.dispo.attachments.download',
    ];

    public function handle(Request $request, Closure $next)
    {
        $name = (string) ($request->route()?->getName() ?? '');
        if (!str_starts_with($name, 'recruiting.') || in_array($name, self::ALLOWED, true)) {
            return $next($request);
        }
        if (!DispoAccess::eventOnly($request->user())) {
            return $next($request);
        }

        return redirect()->route('recruiting.dispo.events.index');
    }
}
