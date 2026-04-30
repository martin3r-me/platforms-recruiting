<?php

namespace Platform\Recruiting\Services;

use Platform\Recruiting\Models\RecApplicant;
use Platform\Recruiting\Models\RecAutoPilotLog;
use Platform\Recruiting\Models\RecHrDeskCase;

class HrDeskRoutingService
{
    public function handleEuStatusChange(RecApplicant $applicant, ?bool $isEuCitizen, ?int $userId = null): void
    {
        // Backward-compat shim — alle Routing-Regeln laufen zentral durch
        // evaluateAndRoute(). Diese Methode bleibt nur damit existierende
        // Aufrufer (z.B. setEuCitizen) keinen behavioural-break erleben.
        $this->evaluateAndRoute($applicant, $userId);
    }

    /**
     * Zentrale Stelle für ALLE festen HR-Schreibtisch-Routing-Regeln.
     *
     * Idempotent — kann jederzeit / mehrfach aufgerufen werden, schreibt
     * dank routeIfNotAlreadyOpen() keine Duplicate-Cases. Erweitert durch
     * Hinzufügen weiterer Regel-Blöcke unten.
     *
     * Aufruf-Stellen (Stand jetzt):
     *  - RecApplicantLegalStatus::setEuCitizen() — wenn EU-Status gesetzt wird
     *  - ApplicantForm::save() — nach jedem Form-Save, vor checkAutoPilotCompletion
     *  - MCP/Code-Pfade die applicant-Daten ändern können
     *
     * Wichtig zur Phase-Stop-Kette: routeToHrDesk setzt auto_pilot=false,
     * damit checkAutoPilotCompletion() in der gleichen Request-Kette
     * NICHT mehr Phase-Advance triggert. Reihenfolge im Aufrufer ist
     * entscheidend (evaluateAndRoute VOR checkAutoPilotCompletion).
     */
    public function evaluateAndRoute(RecApplicant $applicant, ?int $userId = null): void
    {
        // Regel 1: Nicht-EU-Bürger
        if ($applicant->legalStatus?->is_eu_citizen === false) {
            $this->routeIfNotAlreadyOpen(
                $applicant,
                RecHrDeskCase::REASON_NON_EU_CITIZEN,
                $userId
            );
        }

        // Regel 2: Keine grundlegenden Deutschkenntnisse
        $deutschkenntnisse = $this->extractBooleanExtraField($applicant, 'grundlegende_deutschkenntnisse');
        if ($deutschkenntnisse === false) {
            $this->routeIfNotAlreadyOpen(
                $applicant,
                RecHrDeskCase::REASON_NO_GERMAN_KNOWLEDGE,
                $userId
            );
        }

        // Weitere Regeln können hier ergänzt werden, z.B. minderjährig.
    }

    private function routeIfNotAlreadyOpen(RecApplicant $applicant, string $reason, ?int $userId = null): void
    {
        $alreadyOpen = $applicant->hrDeskCases()
            ->where('reason', $reason)
            ->open()
            ->exists();

        if (!$alreadyOpen) {
            $this->routeToHrDesk($applicant, $reason, $userId);
        }
    }

    /**
     * Liest einen extra_field-Wert nach Feld-Namen aus, normalisiert auf
     * Bool. Liefert null wenn das Feld nicht existiert oder nicht ausgefüllt
     * ist (= keine Aussage).
     *
     * Nutzt das gleiche Lookup-Pattern wie RecApplicant::calculateProgress():
     * erst Definition nach Name finden (via getExtraFieldDefinitions(),
     * was schon Phase-Inheritance handhabt), dann Value nach definition_id.
     */
    private function extractBooleanExtraField(RecApplicant $applicant, string $fieldName): ?bool
    {
        try {
            $def = $applicant->getExtraFieldDefinitions()->firstWhere('name', $fieldName);
            if (!$def) {
                return null;
            }
            $valueRow = $applicant->extraFieldValues()
                ->where('definition_id', $def->id)
                ->first();
            if (!$valueRow) {
                return null;
            }
            $raw = $valueRow->value;
            if ($raw === null || $raw === '' || $raw === '[]') {
                return null;
            }
            if ($raw === true || $raw === 1 || $raw === '1' || $raw === 'true') {
                return true;
            }
            if ($raw === false || $raw === 0 || $raw === '0' || $raw === 'false') {
                return false;
            }
        } catch (\Throwable) {
            // Definition fehlt, Phase noch nicht verlinkt, etc.
        }
        return null;
    }

    public function routeToHrDesk(RecApplicant $applicant, string $reason, ?int $userId = null): RecHrDeskCase
    {
        $applicant->update([
            'is_on_hr_desk' => true,
            'auto_pilot' => false,
        ]);

        $case = RecHrDeskCase::create([
            'rec_applicant_id' => $applicant->id,
            'team_id' => $applicant->team_id,
            'reason' => $reason,
            'status' => RecHrDeskCase::STATUS_OPEN,
            'opened_at' => now(),
            'opened_by_user_id' => $userId,
        ]);

        RecAutoPilotLog::create([
            'rec_applicant_id' => $applicant->id,
            'type' => 'hr_desk_routed',
            'summary' => "Bewerber auf HR-Schreibtisch verschoben (Grund: {$reason}).",
        ]);

        return $case;
    }

    public function approveCase(RecHrDeskCase $case, int $userId, ?string $notes = null): void
    {
        $case->update([
            'status' => RecHrDeskCase::STATUS_APPROVED,
            'resolved_at' => now(),
            'resolved_by_user_id' => $userId,
            'resolution_notes' => $notes,
        ]);

        $applicant = $case->applicant;

        // Only release from HR desk if no other open cases remain
        $hasOtherOpenCases = $applicant->hrDeskCases()
            ->where('id', '!=', $case->id)
            ->open()
            ->exists();

        if (!$hasOtherOpenCases) {
            $applicant->update(['is_on_hr_desk' => false]);
        }

        RecAutoPilotLog::create([
            'rec_applicant_id' => $applicant->id,
            'type' => 'hr_desk_approved',
            'summary' => "HR-Schreibtisch-Fall freigegeben." . ($notes ? " Notiz: {$notes}" : ''),
        ]);
    }

    public function rejectCase(RecHrDeskCase $case, int $userId, ?string $notes = null): void
    {
        $case->update([
            'status' => RecHrDeskCase::STATUS_REJECTED,
            'resolved_at' => now(),
            'resolved_by_user_id' => $userId,
            'resolution_notes' => $notes,
        ]);

        $applicant = $case->applicant;
        $applicant->update([
            'rejected_at' => now(),
            'is_on_hr_desk' => false,
            'auto_pilot' => false,
            'is_active' => false,
        ]);

        RecAutoPilotLog::create([
            'rec_applicant_id' => $applicant->id,
            'type' => 'hr_desk_rejected',
            'summary' => "Bewerber über HR-Schreibtisch abgelehnt." . ($notes ? " Notiz: {$notes}" : ''),
        ]);
    }
}
