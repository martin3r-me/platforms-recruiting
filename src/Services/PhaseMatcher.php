<?php

namespace Platform\Recruiting\Services;

/**
 * Findet die "äquivalente" Phase in einer Zielstelle, wenn ein Bewerber auf
 * eine andere Stelle angeglichen wird (gleiche-order-Phase, sonst die erste).
 *
 * Hintergrund: Phasen sind pro Stelle geklont (gleicher Name/`order`, eigene
 * ID). Beim Angleichen (reconcilePositionState / switchToPosition) muss
 * rec_phase_id auf die Phase desselben Schritts in der Zielstelle zeigen.
 *
 * Reines PHP ohne Framework-Abhängigkeiten, damit unit-testbar (Modul-Konvention).
 *
 * @see \Platform\Recruiting\Models\RecApplicant::reconcilePositionState()
 */
class PhaseMatcher
{
    /**
     * Phase mit gleichem `order` in der Zielstelle, sonst die erste
     * (order-kleinste). null, wenn die Stelle keine aktive Phase hat.
     *
     * @param array<int,int> $orderToPhaseId  [order => phaseId] der aktiven Phasen der Zielstelle
     */
    public static function sameOrderOrFirst(?int $order, array $orderToPhaseId): ?int
    {
        if (empty($orderToPhaseId)) {
            return null;
        }

        if ($order !== null && isset($orderToPhaseId[$order])) {
            return $orderToPhaseId[$order];
        }

        ksort($orderToPhaseId);

        return reset($orderToPhaseId) ?: null;
    }
}
