<?php

namespace Platform\Recruiting\Livewire\Dashboard;

use Livewire\Component;
use Platform\Recruiting\Models\RecApplicant;
use Platform\Recruiting\Models\RecPosition;

/**
 * Test-Dashboard — listet Bewerber der Sandbox-Positionen gruppiert nach
 * Phase-Order. Bewusst minimal gehalten, keine Filter, keine Aktionen —
 * dient nur dazu den End-to-End-Test der neuen Phasen-Logik visuell zu
 * verfolgen ohne `show_in_dashboard=true` auf den Sandbox-Phasen setzen
 * zu müssen (was das produktive Dashboard verschmutzen würde).
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
        if (!empty($sandboxPositionIds)) {
            $applicants = RecApplicant::forTeam($teamId)
                ->whereHas('postings', fn ($q) => $q->whereIn('rec_position_id', $sandboxPositionIds))
                ->with(['phase', 'postings.position', 'crmContactLinks.contact'])
                ->orderByDesc('id')
                ->get();
        }

        $byPhase = [];
        for ($order = 1; $order <= 6; $order++) {
            $byPhase[$order] = $applicants->filter(fn ($a) => $a->phase?->order === $order)->values();
        }
        $byPhase['unassigned'] = $applicants->filter(fn ($a) => $a->phase === null)->values();

        return view('recruiting::livewire.dashboard.test-dashboard', [
            'sandboxPositions' => $sandboxPositions,
            'byPhase' => $byPhase,
            'totalCount' => $applicants->count(),
        ])->layout('platform::layouts.app');
    }
}
