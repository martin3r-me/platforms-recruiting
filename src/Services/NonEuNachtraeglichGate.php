<?php

namespace Platform\Recruiting\Services;

/**
 * Nicht-EU erst NACH der Schulung erkannt (Fall Mina, 28.10.2026: EU-Angabe
 * stand auf Ja, der Schulungsleiter meldet „kein EU-Bürger“). Korrigiert HR
 * die Angabe, muss derselbe Nicht-EU-Fall entstehen wie beim Übergang zu
 * „teilgenommen“ (NonEuPostTrainingGate) — sonst gibt es keine
 * Rechtsstatus-Prüfung und keinen Versand vom Schreibtisch.
 *
 * Nur bei ausdrücklichem Nein, nur nach besuchter Schulung, solange
 * ungeprüft, kein Vertrag raus und aktiv. Ein ABGELEHNTER Nicht-EU-Fall
 * wird nicht wiederbelebt; ein früher erledigter schon — der Wechsel des
 * EU-Status setzt die Prüfung zurück (setEuCitizen), also braucht es
 * wieder einen Ort, an dem HR prüft. Pure.
 */
class NonEuNachtraeglichGate
{
    public static function shouldRoute(
        bool $hasLegalStatus,
        ?bool $isEuCitizen,
        bool $isChecked,
        bool $schulungBesucht,
        bool $vertragRaus,
        bool $nonEuFallAbgelehnt,
        bool $aktiv,
    ): bool {
        return $hasLegalStatus
            && $isEuCitizen === false
            && !$isChecked
            && $schulungBesucht
            && !$vertragRaus
            && !$nonEuFallAbgelehnt
            && $aktiv;
    }
}
