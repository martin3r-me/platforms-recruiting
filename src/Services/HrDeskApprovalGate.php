<?php

namespace Platform\Recruiting\Services;

use Platform\Recruiting\Models\RecHrDeskCase;

/**
 * Entscheidet, ob die HR-Freigabe eines Falls blockiert werden muss, weil ein
 * Nicht-EU-Bewerber noch nicht rechtlich geprüft wurde. Reine Logik
 * (pure-unit-testbar), spiegelt das Muster von LegalStatusGate.
 *
 * WICHTIG: Nur der menschliche Approve-Pfad (HrDeskRoutingService::approveCase)
 * fragt hier. Der automatische autoCloseObsoleteCases-Pfad NICHT — er feuert nur,
 * wenn die Nicht-EU-Bedingung obsolet ist (Bewerber wurde EU), womit keine
 * Prüfung mehr nötig ist.
 */
class HrDeskApprovalGate
{
    /**
     * @param string $reason                Reason-Code des HR-Desk-Falls.
     * @param bool   $isLegalStatusUnchecked Ergebnis von RecApplicant::isLegalStatusUnchecked().
     */
    public static function blocksApproval(string $reason, bool $isLegalStatusUnchecked): bool
    {
        return $reason === RecHrDeskCase::REASON_NON_EU_CITIZEN && $isLegalStatusUnchecked;
    }
}
