<?php

namespace Platform\Recruiting\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Platform\Recruiting\Services\Zas\Dispo\DispoAccess;
use Platform\Recruiting\Support\RecruitingRouteGate;

/**
 * Route-Gate der eingeschraenkten Zugaenge. Hiess bis 14.09.2026
 * DispoEventOnlyGate — seit der zweiten Stufe ("Schulungsbewertung")
 * traegt es mehr als nur die Veranstaltungen.
 *
 * Welche Route fuer welche Stufe offen ist, entscheidet RecruitingRouteGate;
 * dort steht auch die Begruendung und dort liegen die Tests. Diese Klasse
 * macht nur noch das Drumherum: Nutzer lesen, Routennamen lesen, umleiten.
 *
 * Haengt in der web-Gruppe (Provider) und ist fuer alle Nicht-Recruiting-
 * Routen und alle nicht gelisteten Nutzer ein No-op.
 *
 * Livewire-Actions laufen nicht ueber recruiting.*-Routen — Mutationen der
 * erreichbaren Seiten sind deshalb ZUSAETZLICH in den Komponenten selbst
 * gesperrt (Dispo\Events\Show::blockedForEventOnly, TrainingReview\Show).
 * Andere Komponenten sind unerreichbar, weil ihre Seiten (und damit die
 * Snapshots) hier geblockt werden.
 */
class RecruitingAccessGate
{
    public function handle(Request $request, Closure $next)
    {
        $name = (string) ($request->route()?->getName() ?? '');
        if ($name === '') {
            return $next($request);
        }

        $user = $request->user();
        $ziel = RecruitingRouteGate::redirectTo(
            DispoAccess::eventOnly($user),
            DispoAccess::trainingLeader($user),
            $name,
        );

        return $ziel === null ? $next($request) : redirect()->route($ziel);
    }
}
