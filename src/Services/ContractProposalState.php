<?php

namespace Platform\Recruiting\Services;

/**
 * Zustand des Schulungsleiter-Vorschlags (Lohn + Vertragslaufzeit).
 * Pure — keine DB, keine Laravel-Abhaengigkeit.
 */
class ContractProposalState
{
    public static function state(?int $proposedAt, ?int $takenAt, bool $hasSent): string
    {
        if ($proposedAt === null) {
            return 'none';
        }

        if ($hasSent) {
            return 'archived';
        }

        if ($takenAt === null) {
            return 'open';
        }

        if ($proposedAt > $takenAt) {
            return 'changed';
        }

        return 'taken';
    }
}
