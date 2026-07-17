<?php

namespace Platform\Recruiting\Services;

/**
 * Gemeinsames Sende-Prädikat pro Bewerber — Ort der Wahrheit für
 * "darf jetzt Verträge + Portallink bekommen?". Genutzt vom Bulk
 * (Nachbereitung, bulkSendState) und der HR-Desk-Karte (Button-Enable).
 * Prüf-Reihenfolge exakt wie der historische Bulk: sent → legal →
 * beginn → zuschlag. Pure — keine DB, keine Laravel-Abhängigkeit.
 */
class ContractSendEligibility
{
    public static function state(bool $hasSent, bool $legalBlocked, bool $hasBeginn, bool $hasZuschlag): string
    {
        if ($hasSent) {
            return 'already_sent';
        }
        if ($legalBlocked) {
            return 'legal_blocked';
        }
        if (!$hasBeginn) {
            return 'missing_beginn';
        }
        if (!$hasZuschlag) {
            return 'missing_zuschlag';
        }
        return 'ready';
    }
}
