<?php

namespace Platform\Recruiting\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Platform\Recruiting\Services\Zas\Dispo\DispoAccess;
use Platform\Recruiting\Support\EingeschraenktesKontoTeam;
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
        $eventOnly = DispoAccess::eventOnly($user);
        $trainingLeader = DispoAccess::trainingLeader($user);

        $this->teamZurueckstellen($user, ($eventOnly || $trainingLeader) && RecruitingRouteGate::betrifft($name));

        $ziel = RecruitingRouteGate::redirectTo(
            $eventOnly,
            $trainingLeader,
            $name,
        );

        return $ziel === null ? $next($request) : redirect()->route($ziel);
    }

    /**
     * Sammelkonto steht im falschen Team (Vorfall event@, 16.11.2026): zurueck
     * aufs Anker-Team, BEVOR die Modul-Rechtepruefung des Core laeuft (die
     * web-Gruppe kommt vor der Routen-Middleware). Darf nie einen Aufruf
     * brechen — im Zweifel bleibt alles, wie es ist.
     */
    private function teamZurueckstellen(mixed $user, bool $eingeschraenkt): void
    {
        if (!$eingeschraenkt || $user === null) {
            return;
        }

        try {
            $anker = (int) (config('recruiting.zas.inbound_team_id') ?: 0);
            $vorher = $user->current_team_id === null ? null : (int) $user->current_team_id;
            $ziel = EingeschraenktesKontoTeam::ziel(
                true,
                $vorher,
                $anker,
                fn (int $teamId): bool => $user->teams()->whereKey($teamId)->exists(),
            );
            if ($ziel === null) {
                return;
            }

            $user->forceFill(['current_team_id' => $ziel])->save();
            $user->unsetRelation('currentTeamRelation');
            \Illuminate\Support\Facades\Log::info('recruiting_sammelkonto_team_zurueckgestellt', [
                'user_id' => $user->getKey(), 'von_team_id' => $vorher, 'auf_team_id' => $ziel,
            ]);
        } catch (\Throwable) {
            // bewusst leer: lieber die Fehlerseite des Core als ein Fehler hier
        }
    }
}
