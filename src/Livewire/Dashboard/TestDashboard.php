<?php

namespace Platform\Recruiting\Livewire\Dashboard;

use Livewire\Component;
use Platform\Recruiting\Models\RecApplicant;
use Platform\Recruiting\Models\RecPhase;
use Platform\Recruiting\Models\RecPosition;

/**
 * Test-Dashboard — listet Bewerber der Sandbox-Positionen gruppiert nach
 * Phase. Phasen werden dynamisch aus der DB geladen (alle Phasen der
 * Sandbox-Positionen, dedupliziert per order, sortiert nach order) und
 * jede Phase wird gerendert — auch leere — damit man auf einen Blick
 * sieht wo gerade Bewerber haengen und welche Phasen leer sind.
 *
 * Sandbox-Positionen werden per Title-Pattern "Teststelle_Sandbox*"
 * erkannt — klon-stabil, neue Sandbox-Positionen werden automatisch
 * erkannt.
 *
 * Route: /recruiting/testdashboard
 */
class TestDashboard extends Component
{
    public function render()
    {
        $teamId = auth()->user()->currentTeam->id;

        $sandboxPositions = RecPosition::forTeam($teamId)
            ->where('title', 'like', 'Teststelle_Sandbox%')
            ->orderBy('id')
            ->get();

        $sandboxPositionIds = $sandboxPositions->pluck('id')->all();

        $applicants = collect();
        $phaseRows = collect();

        if (!empty($sandboxPositionIds)) {
            $applicants = RecApplicant::forTeam($teamId)
                ->whereHas('postings', fn ($q) => $q->whereIn('rec_position_id', $sandboxPositionIds))
                ->with(['phase', 'postings.position', 'crmContactLinks.contact'])
                ->orderByDesc('id')
                ->get();

            // Alle Phasen der Sandbox-Positionen — dedupliziert per order.
            // Falls mehrere Positionen die gleiche Order haben (typisch:
            // identische Templates), nehmen wir die erste (sortiert nach
            // position_id) als Label-Quelle.
            $phaseRows = RecPhase::whereIn('rec_position_id', $sandboxPositionIds)
                ->orderBy('order')
                ->orderBy('rec_position_id')
                ->get()
                ->groupBy('order')
                ->map(fn ($group) => $group->first())
                ->sortBy('order')
                ->values();
        }

        $byPhase = [];
        foreach ($phaseRows as $phase) {
            $byPhase[$phase->order] = [
                'phase'      => $phase,
                'applicants' => $applicants->filter(fn ($a) => $a->phase?->order === $phase->order)->values(),
            ];
        }

        $unassigned = $applicants->filter(fn ($a) => $a->phase === null)->values();

        return view('recruiting::livewire.dashboard.test-dashboard', [
            'sandboxPositions' => $sandboxPositions,
            'byPhase'          => $byPhase,
            'unassigned'       => $unassigned,
            'totalCount'       => $applicants->count(),
        ])->layout('platform::layouts.app');
    }
}
