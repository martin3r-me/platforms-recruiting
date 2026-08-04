<?php

namespace Platform\Recruiting\Observers;

use Platform\Recruiting\Models\RecApplicant;
use Platform\Recruiting\Models\RecPhase;
use Platform\Recruiting\Models\RecPhaseTransition;
use Platform\Recruiting\Support\PhaseTransitionTrigger;

/**
 * Model-Events feuern nicht bei DB-Kaskaden (Spec §5). Dieser Observer
 * faengt nur direkt ueber Eloquent geloeschte Einzel-Phasen; die
 * Stellen-Loeschung (Kaskade) faengt RecPositionObserver.
 * Achtung: from_phase_id wird von der nullOnDelete-Kaskade unmittelbar
 * nach dem Insert genullt — der Name-Snapshot bleibt (Spec §5). Tests
 * duerfen fuer diesen Fall NICHT die ID erwarten.
 */
class RecPhaseObserver
{
    /**
     * Doppel-Schreib-Guard: RecPositionObserver ruft deleting() direkt auf;
     * loescht danach noch irgendein Pfad dieselbe Phase via Eloquent, gaebe es
     * zwei Intervall-Enden. Prozessweiter Static ist hier fast immer unkritisch
     * (Phase-IDs sind einmalig) — bekannter Randfall: wird eine Loeschung in
     * einer Transaktion zurueckgerollt und im selben Prozess erneut versucht,
     * ueberspringt der Guard das zweite Schreiben (toleriert, dokumentiert).
     * Gecheckter Sonderfall: DuplicatePosition.php:122 loescht Phasen per
     * Query-Builder-Bulk (phases()->delete(), KEINE Events) — betrifft nur die
     * frisch geklonte Zielstelle ohne Bewerber, keine Transitions noetig.
     *
     * @var array<int,bool>
     */
    private static array $handled = [];

    public function deleting(RecPhase $phase): void
    {
        if (isset(self::$handled[$phase->id])) {
            return;
        }
        self::$handled[$phase->id] = true;

        try {
            RecApplicant::where('rec_phase_id', $phase->id)
                ->select(['id', 'team_id'])
                ->chunkById(200, function ($applicants) use ($phase) {
                    foreach ($applicants as $applicant) {
                        RecPhaseTransition::create([
                            'team_id'          => $applicant->team_id,
                            'rec_applicant_id' => $applicant->id,
                            'rec_position_id'  => $phase->rec_position_id,
                            'from_phase_id'    => $phase->id,
                            'to_phase_id'      => null,
                            'from_phase_name'  => $phase->name,
                            'to_phase_name'    => null,
                            'trigger'          => PhaseTransitionTrigger::PHASE_DELETED,
                            'source'           => 'live',
                            'occurred_at'      => now(),
                        ]);
                    }
                });
        } catch (\Throwable $e) {
            report($e); // Loeschung nie blockieren
        }
    }
}
