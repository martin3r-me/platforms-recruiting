<?php

namespace Platform\Recruiting\Support;

use Platform\Recruiting\Models\RecHrDeskCase;

/**
 * Entscheidet, ob eine HR-Schreibtisch-Ablehnung einen Bewerbungsstatus
 * stempelt — und welchen.
 *
 * Hintergrund: Jugendschutz-Ablehnungen sollen denselben Status tragen wie die
 * automatische U16-Absage, damit in der Bewerber-Liste nicht der eine Fall
 * begründet und der andere nur "inaktiv" dasteht. Andere Fall-Typen (Nicht-EU,
 * Deutschkenntnisse, Schulung abgesagt) bleiben bewusst ungestempelt — dort
 * gibt es keinen abgestimmten Status.
 *
 * Pure Logik (unit-testbar), gleiche Bauart wie HrDeskApprovalGate.
 */
final class HrDeskRejectionStatus
{
    /**
     * @param string   $reason           Reason-Code des Falls.
     * @param int|null $currentStatusId  Bereits am Bewerber gesetzter Status (Handauswahl gewinnt).
     * @param int|null $configuredStatusId Status aus den Bewerber-Einstellungen (minor_rejection_status_id).
     * @return int|null Zu setzender Status oder null (= nicht anfassen).
     */
    public static function resolve(string $reason, ?int $currentStatusId, ?int $configuredStatusId): ?int
    {
        if ($reason !== RecHrDeskCase::REASON_MINOR) {
            return null;
        }
        if ($currentStatusId !== null && $currentStatusId > 0) {
            return null;
        }
        if ($configuredStatusId === null || $configuredStatusId <= 0) {
            return null;
        }

        return $configuredStatusId;
    }
}
