<?php

namespace Platform\Recruiting\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Session\SessionManager;
use Platform\Recruiting\Support\LangeSitzung;

/**
 * Setzt fuer EINE Anfrage die Sitzungs-Lebensdauer auf 180 Tage, wenn sie zu
 * einer Konto-Anmeldung mit "Auf diesem Geraet angemeldet bleiben" gehoert
 * (Entscheidung in LangeSitzung::giltFuer, Begruendung dort).
 *
 * WARUM VOR StartSession, und warum das die ganze Schwierigkeit ist:
 * StartSession baut den DatabaseSessionHandler mit den Minuten, die in
 * DIESEM Augenblick in config('session.lifetime') stehen, und liest damit
 * die Zeile (abgelaufen = last_activity aelter als diese Minuten). Eine
 * Routen-Middleware des Moduls laeuft erst danach — zu spaet. Und eine
 * Pfad-Weiche ginge auch nicht: Livewire schickt jede Aktion an
 * /livewire/update. Deshalb haengt diese Middleware in der web-Gruppe
 * zwischen EncryptCookies (die Cookies sind dann schon entschluesselt) und
 * StartSession — eingehaengt vom ServiceProvider ueber einhaengen().
 *
 * NEBENBEI schaltet sie fuer JEDE web-Anfrage die Lotterie-Aufraeumung des
 * Datenbank-Treibers ab (LangeSitzung::schalteLotterieAb); aufgeraeumt wird
 * stattdessen stuendlich von recruiting:sitzungen-aufraeumen.
 *
 * Fuer alle anderen Anfragen (kein Merker-Cookie) kostet sie keine Abfrage.
 */
class LangeSitzungVerlaengern
{
    public function handle(Request $anfrage, Closure $weiter)
    {
        if (LangeSitzung::moeglich()) {
            LangeSitzung::schalteLotterieAb();

            if (LangeSitzung::giltFuer($anfrage)) {
                config(['session.lifetime' => LangeSitzung::MINUTEN]);
                self::treiberNeuBauen();
            }
        }

        return $weiter($anfrage);
    }

    /**
     * Hat schon jemand VOR StartSession den Treiber gebaut (ein
     * ServiceProvider, der session() anfasst), steckt darin noch die alte
     * Minutenzahl. Ein noch nicht gestarteter Treiber wird dann verworfen;
     * StartSession baut ihn gleich mit der neuen Zahl neu. Ein schon
     * gestarteter bleibt unangetastet (dann ist die Zeile ohnehin gelesen).
     */
    private static function treiberNeuBauen(): void
    {
        $app = app();
        if (!$app->resolved('session')) {
            return;
        }

        $manager = $app->make('session');
        if (!$manager instanceof SessionManager || $manager->getDrivers() === []) {
            return;
        }

        foreach ($manager->getDrivers() as $treiber) {
            if (method_exists($treiber, 'isStarted') && $treiber->isStarted()) {
                return;
            }
        }

        $manager->forgetDrivers();
        $app->forgetInstance('session.store');
    }

    /**
     * In die web-Gruppe einhaengen: unmittelbar VOR StartSession (und damit
     * hinter EncryptCookies), und in der Prioritaetsliste des Routers
     * ebenfalls direkt vor StartSession.
     *
     * Die Prioritaetsliste ist das zweite Netz: der Router sortiert die
     * gesammelte Middleware einer Route danach (SortedMiddleware). Steht die
     * Klasse dort direkt vor StartSession, kann keine Umsortierung sie hinter
     * StartSession schieben.
     *
     * BEWUSST NICHT ueber den HTTP-Kernel (addToMiddlewarePriorityBefore):
     * der synchronisiert dabei ALLE Gruppen aus seinem eigenen Stand in den
     * Router zurueck und warf damit weg, was andere ServiceProvider schon per
     * pushMiddlewareToGroup eingehaengt haben (etwa RecruitingAccessGate).
     *
     * Fehlt StartSession in der Gruppe (z. B. in der Konsole, wo der Kernel
     * die Gruppen nie gesetzt hat), passiert nichts: ohne Sitzung gibt es
     * nichts zu verlaengern. Mehrfacher Aufruf ist harmlos.
     */
    public static function einhaengen(Router $router): void
    {
        $gruppe = $router->getMiddlewareGroups()['web'] ?? null;
        if (!is_array($gruppe) || in_array(self::class, $gruppe, true)) {
            return;
        }

        $stelle = array_search(StartSession::class, $gruppe, true);
        if ($stelle === false) {
            return;
        }

        array_splice($gruppe, $stelle, 0, [self::class]);
        $router->middlewareGroup('web', array_values($gruppe));

        $prioritaet = $router->middlewarePriority;
        if (!in_array(self::class, $prioritaet, true)) {
            $stelle = array_search(StartSession::class, $prioritaet, true);
            if ($stelle !== false) {
                array_splice($prioritaet, $stelle, 0, [self::class]);
                $router->middlewarePriority = array_values($prioritaet);
            }
        }
    }
}
