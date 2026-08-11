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
 * WICHTIG (zwei Fallstricke, beide im Review aufgeschlagen):
 *  - Das Alters-Verdikt MUSS mitgegeben werden: REASON_MINOR trägt auch Fälle
 *    mit fehlendem/unplausiblem Geburtsdatum (VERDICT_UNKNOWN). Ein Erwachsener
 *    ohne Geburtsdatum darf keinen "unter 16"-Absagestatus bekommen.
 *  - KEIN "bereits gesetzter Status gewinnt"-Guard: Bewerber bekommen beim
 *    Intake automatisch den Standard-Status, ein solcher Guard wäre praktisch
 *    immer wahr und der Stempel damit wirkungslos. Die Ablehnung ist ein
 *    Endzustand und überschreibt bewusst — genau wie die U16-Auto-Absage.
 *
 * Pure Logik (unit-testbar), gleiche Bauart wie HrDeskApprovalGate.
 */
final class HrDeskRejectionStatus
{
    /**
     * @param string   $reason             Reason-Code des Falls.
     * @param string   $ageVerdict         Ergebnis von MinorAgeGate::verdict().
     * @param int|null $configuredStatusId Status aus den Bewerber-Einstellungen.
     * @return int|null Zu setzender Status oder null (= nicht anfassen).
     */
    public static function resolve(string $reason, string $ageVerdict, ?int $configuredStatusId): ?int
    {
        if ($reason !== RecHrDeskCase::REASON_MINOR) {
            return null;
        }

        $isMinor = in_array($ageVerdict, [
            MinorAgeGate::VERDICT_REJECT,
            MinorAgeGate::VERDICT_REVIEW,
        ], true);
        if (!$isMinor) {
            return null;
        }

        if ($configuredStatusId === null || $configuredStatusId <= 0) {
            return null;
        }

        return $configuredStatusId;
    }
}
