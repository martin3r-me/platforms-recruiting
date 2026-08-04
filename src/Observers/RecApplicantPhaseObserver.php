<?php

namespace Platform\Recruiting\Observers;

use Platform\Recruiting\Models\RecApplicant;
use Platform\Recruiting\Models\RecPhase;
use Platform\Recruiting\Models\RecPhaseTransition;
use Platform\Recruiting\Support\PhaseTransitionTrigger;

/**
 * Schreibt rec_phase_transitions bei jedem Eloquent-Pfad, der rec_phase_id
 * setzt/aendert. Deckt NICHT ab (Spec §5, bekannte Ausnahmen):
 *  1. FixApplicantPhase (Query-Builder) — expliziter Insert im Command
 *  2. DB-Kaskaden (nullOnDelete) — RecPhaseObserver/RecPositionObserver
 */
class RecApplicantPhaseObserver
{
    public function created(RecApplicant $applicant): void
    {
        if ($applicant->rec_phase_id) {
            $this->record($applicant, null, (int) $applicant->rec_phase_id);
        }
    }

    public function updated(RecApplicant $applicant): void
    {
        if (!$applicant->wasChanged('rec_phase_id')) {
            return;
        }
        $from = $applicant->getOriginal('rec_phase_id');
        $this->record($applicant, $from ? (int) $from : null, $applicant->rec_phase_id ? (int) $applicant->rec_phase_id : null);
    }

    private function record(RecApplicant $applicant, ?int $fromId, ?int $toId): void
    {
        try {
            $phases = RecPhase::whereIn('id', array_filter([$fromId, $toId]))->get()->keyBy('id');
            $from = $fromId ? $phases->get($fromId) : null;
            $to = $toId ? $phases->get($toId) : null;

            RecPhaseTransition::create([
                'team_id'          => $applicant->team_id,
                'rec_applicant_id' => $applicant->id,
                'rec_position_id'  => $to?->rec_position_id ?? $from?->rec_position_id,
                'from_phase_id'    => $fromId,
                'to_phase_id'      => $toId,
                'from_phase_name'  => $from?->name,
                'to_phase_name'    => $to?->name,
                'trigger'          => PhaseTransitionTrigger::consume($applicant->id),
                'source'           => 'live',
                'occurred_at'      => now(),
            ]);
        } catch (\Throwable $e) {
            // Transition-Log darf den Phasenwechsel NIE brechen (Spec §5)
            report($e);
        }
    }
}
