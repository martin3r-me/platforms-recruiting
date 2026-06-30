<?php

namespace Platform\Recruiting\Services;

/**
 * Zentrale Entscheidung, ob ein Bewerber wegen offener Rechtsstatus-Pruefung
 * blockiert werden muss. Reine Logik (pure-unit-testbar, kein DB/Laravel).
 *
 * Genutzt vom Vertrags-/Portal-Bulk-Send (InterviewBookings\Index) UND vom
 * Schulungs-Reminder (SendInterviewReminders) — beide muessen dieselbe Regel
 * anwenden, damit nicht-geprüfte Nicht-EU-Bewerber weder Vertrag noch
 * Erinnerung erhalten.
 */
class LegalStatusGate
{
    /**
     * True wenn der Rechtsstatus eine HR-Pruefung erfordert und diese noch
     * NICHT erfolgt ist → Versand/Reminder blockieren.
     *
     * @param bool  $hasLegalStatus legalStatus-Record vorhanden? Fehlt er
     *                              (Bestands-Bewerber), wird NICHT blockiert.
     * @param ?bool $isEuCitizen    true=EU (nie blockiert), false=nicht-EU,
     *                              null=Frage unbeantwortet (wie nicht-EU).
     * @param bool  $isChecked      legal_status_checked_at gesetzt (HR ok)?
     */
    public static function isUnchecked(bool $hasLegalStatus, ?bool $isEuCitizen, bool $isChecked): bool
    {
        if (!$hasLegalStatus) {
            // Kein Record → eu_burger-Frage nie beantwortet. Bestand nicht
            // blockieren (sonst Versand-/Reminder-Regression).
            return false;
        }

        if ($isEuCitizen === true) {
            return false; // EU-Buerger: keine Pruefung noetig.
        }

        // is_eu_citizen=false ODER null → Pruefung relevant.
        return !$isChecked;
    }
}
