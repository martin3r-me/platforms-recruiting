<?php

namespace Platform\Recruiting\Services;

/**
 * Bestimmt den Verantwortlichen (owned_by_user_id) eines Bewerbers per Kaskade.
 *
 * Reihenfolge: bestehender Bewerber-Owner (nie überschreiben) → Verantwortlicher
 * der Stelle → Default-Kontakt aus den Team-Settings → Team-Owner.
 *
 * Reines PHP ohne Framework-Abhängigkeiten, damit unit-testbar.
 */
class OwnerResolver
{
    /**
     * Liefert den ersten brauchbaren User-Identifier (> 0) der Kaskade, sonst null.
     */
    public static function resolve(
        ?int $applicantOwnerId,
        ?int $positionOwnerId,
        ?int $defaultContactId,
        ?int $teamOwnerId,
    ): ?int {
        foreach ([$applicantOwnerId, $positionOwnerId, $defaultContactId, $teamOwnerId] as $candidate) {
            if ($candidate !== null && $candidate > 0) {
                return $candidate;
            }
        }

        return null;
    }
}
