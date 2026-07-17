<?php

namespace Platform\Recruiting\Services;

/**
 * Entscheidet, ob ein Buchungs-Save den Nicht-EU-Bewerber auf den
 * HR-Schreibtisch routet: NUR beim echten Übergang zu 'attended'
 * ("nach der Schulung"), NUR für rechtsstatus-prüfpflichtige Bewerber
 * (Nicht-EU oder unbeantwortet — MIT legalStatus-Datensatz; Bestand
 * ohne Datensatz routet nie, Konvention wie LegalStatusGate), und NUR
 * solange ungeprüft. Pure — keine DB, keine Laravel-Abhängigkeit.
 */
class NonEuPostTrainingGate
{
    public static function shouldRoute(
        ?string $oldStatus,
        string $newStatus,
        bool $hasLegalStatus,
        ?bool $isEuCitizen,
        bool $isChecked
    ): bool {
        if ($newStatus !== 'attended' || $oldStatus === 'attended') {
            return false;
        }
        if (!$hasLegalStatus || $isEuCitizen === true) {
            return false;
        }
        return !$isChecked;
    }
}
