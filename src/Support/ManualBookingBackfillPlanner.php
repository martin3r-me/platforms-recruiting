<?php

namespace Platform\Recruiting\Support;

/**
 * Welche Phasen schaltet der Backfill scharf? Reine Auswahl, damit die Regel
 * ohne DB testbar ist (Muster EmployeeBackfillPlanner).
 *
 * Bereits gesetzte Phasen bleiben aussen vor, damit der Command idempotent ist
 * und im Dry-Run ehrlich zaehlt. Inaktive Phasen werden nie geschaltet: sie
 * tauchen im Dialog ohnehin nicht auf, und ein Flag auf einer stillgelegten
 * Phase ist eine Falle beim spaeteren Reaktivieren.
 */
final class ManualBookingBackfillPlanner
{
    /**
     * @param list<array{id:int,order:int,is_active:bool,allow_manual_booking:bool}> $phases
     * @return list<int>
     */
    public static function selectPhaseIds(array $phases, int $fromOrder): array
    {
        $ids = [];

        foreach ($phases as $phase) {
            if (!($phase['is_active'] ?? false)) {
                continue;
            }
            if (($phase['order'] ?? 0) < $fromOrder) {
                continue;
            }
            if ($phase['allow_manual_booking'] ?? false) {
                continue;
            }
            $ids[] = (int) $phase['id'];
        }

        return $ids;
    }
}
