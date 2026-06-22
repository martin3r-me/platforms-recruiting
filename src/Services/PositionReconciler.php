<?php

namespace Platform\Recruiting\Services;

/**
 * Entscheidet, welche Phase und welcher Verantwortliche zu einer Bewerbung
 * gehören, NACHDEM sich ihre primäre Stelle geändert haben könnte (z.B. das
 * Enrichment hängt das Posting von Düsseldorf auf Köln um, weil der Bewerber
 * Köln als Standort wünscht).
 *
 * Hintergrund: Posting-Umhängen über die rohen applicant_postings-Tools ändert
 * nur das Pivot. Ohne diesen Abgleich blieben rec_phase_id und
 * owned_by_user_id auf der ALTEN Stelle stehen → Bewerber mit Köln-Posting
 * aber Düsseldorf-Phase und (im schlimmsten Fall) leerem Owner. Leerer Owner =
 * unsichtbar für die Auto-Pilot-Query (whereNotNull('owned_by_user_id')) →
 * kein Template/Reminder. Genau dieser Bruch wird hier verhindert.
 *
 * Reines PHP ohne Framework-Abhängigkeiten, damit unit-testbar (Modul-Konvention).
 *
 * @see \Platform\Recruiting\Models\RecApplicant::reconcilePositionState()
 * @see OwnerResolver
 */
class PositionReconciler
{
    /**
     * @param int|null       $currentPhasePositionId  rec_position_id der aktuellen Phase (null = keine Phase)
     * @param int|null       $currentPhaseOrder       order der aktuellen Phase (null = keine Phase)
     * @param int            $primaryPositionId       ID der aktuellen primären Stelle
     * @param array<int,int> $orderToActivePhaseId    [order => phaseId] der AKTIVEN Phasen der primären Stelle
     * @param int|null       $currentOwnerId
     * @param int|null       $primaryPositionOwnerId
     * @param int|null       $defaultContactId
     * @param int|null       $teamOwnerId
     *
     * @return array{phase_id: int|null, owner_id: int|null, position_changed: bool}
     *   phase_id / owner_id = null bedeutet "keine Änderung nötig".
     */
    public static function resolve(
        ?int $currentPhasePositionId,
        ?int $currentPhaseOrder,
        int $primaryPositionId,
        array $orderToActivePhaseId,
        ?int $currentOwnerId,
        ?int $primaryPositionOwnerId,
        ?int $defaultContactId,
        ?int $teamOwnerId,
    ): array {
        // Stelle gilt als gewechselt, wenn die aktuelle Phase fehlt oder zu
        // einer anderen Stelle gehört als die jetzige primäre Stelle.
        $positionChanged = $currentPhasePositionId === null
            || $currentPhasePositionId !== $primaryPositionId;

        // Phase nur anfassen, wenn sie nicht (mehr) zur primären Stelle gehört.
        $phaseId = $positionChanged
            ? self::sameOrderOrFirst($currentPhaseOrder, $orderToActivePhaseId)
            : null;

        // Owner-Kaskade:
        //  - Bei echtem Stellenwechsel folgt der Verantwortliche der NEUEN Stelle
        //    (Stellen-Owner gewinnt) — "wer auf Köln landet, kriegt Kölns Lead".
        //  - Ohne Wechsel nur auffüllen, falls leer (manuell gesetzter Owner bleibt).
        // In beiden Fällen wird nie leer gelassen, solange irgendein Kandidat existiert.
        $resolvedOwner = $positionChanged
            ? OwnerResolver::resolve($primaryPositionOwnerId, $currentOwnerId, $defaultContactId, $teamOwnerId)
            : OwnerResolver::resolve($currentOwnerId, $primaryPositionOwnerId, $defaultContactId, $teamOwnerId);

        // Nur als Änderung melden, wenn sich tatsächlich etwas ändert.
        $ownerId = ($resolvedOwner !== null && $resolvedOwner !== $currentOwnerId)
            ? $resolvedOwner
            : null;

        return [
            'phase_id' => $phaseId,
            'owner_id' => $ownerId,
            'position_changed' => $positionChanged,
        ];
    }

    /**
     * Liefert die Phase mit gleichem `order` in der Zielstelle, sonst die erste
     * (order-kleinste) aktive Phase. null, wenn die Stelle keine aktive Phase hat.
     *
     * @param array<int,int> $orderToActivePhaseId
     */
    public static function sameOrderOrFirst(?int $order, array $orderToActivePhaseId): ?int
    {
        if (empty($orderToActivePhaseId)) {
            return null;
        }

        if ($order !== null && isset($orderToActivePhaseId[$order])) {
            return $orderToActivePhaseId[$order];
        }

        ksort($orderToActivePhaseId);

        return reset($orderToActivePhaseId) ?: null;
    }
}
