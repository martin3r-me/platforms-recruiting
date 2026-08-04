<?php

namespace Platform\Recruiting\Observers;

use Platform\Recruiting\Models\RecPhase;
use Platform\Recruiting\Models\RecPosition;

/**
 * PFLICHT-Observer (Spec §5): rec_phases.rec_position_id ist cascadeOnDelete —
 * MySQL entfernt die Phasen-Zeilen ohne Eloquent-Event, RecPhaseObserver
 * feuert also NICHT bei Stellen-Loeschung. Prinzip: jede Kaskade, die auf
 * rec_phase_id durchschlaegt, braucht einen Observer an ihrem AUSGANGSPUNKT.
 * (Team-Loeschung ist geprueft und braucht keinen: Bewerber + Transitions
 * kaskadieren konsistent mit.)
 */
class RecPositionObserver
{
    public function deleting(RecPosition $position): void
    {
        $observer = new RecPhaseObserver();
        foreach (RecPhase::where('rec_position_id', $position->id)->get() as $phase) {
            $observer->deleting($phase);
        }
    }
}
