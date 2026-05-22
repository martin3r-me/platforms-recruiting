<?php

namespace Platform\Recruiting\Livewire\Dashboard;

/**
 * Dashboard-Legacy: zeigt das alte 2-Phasen-Modell auf Stellen mit
 * '_old'-Suffix. Erbt 1:1 die Dashboard-Logik, schaltet nur den
 * legacyMode-Flag um. Dadurch wird:
 *  - die Stellen-Liste auf Title LIKE '%\\_old' gefiltert
 *  - der show_in_dashboard-Flag auf den Phasen ignoriert (alte
 *    Phasen wurden bewusst auf false gesetzt damit sie nicht im
 *    Production-Dashboard auftauchen — im Legacy aber sichtbar)
 *
 * Temporaere Bruecke: laeuft bis der Kollege die alten Bewerber
 * manuell zum MA migriert hat. Danach koennen Legacy-Stellen +
 * Component archiviert werden.
 */
class DashboardLegacy extends Dashboard
{
    public bool $legacyMode = true;

    public function render()
    {
        // Wiederverwendet die Dashboard-View 1:1 — wir wollen die gleiche
        // UI mit nur unterschiedlichem Stellen-/Phasen-Scope.
        return view('recruiting::livewire.dashboard.dashboard')
            ->layout('platform::layouts.app');
    }
}
