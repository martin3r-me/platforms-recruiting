<?php

namespace Platform\Recruiting\Services;

use Illuminate\Support\Facades\DB;
use Platform\Recruiting\Exceptions\LegalStatusNotCheckedException;
use Platform\Recruiting\Models\RecApplicant;
use Platform\Recruiting\Models\RecApplicantSettings;
use Platform\Recruiting\Models\RecApplicantStatus;
use Platform\Recruiting\Models\RecAutoPilotLog;
use Platform\Recruiting\Models\RecHrDeskCase;
use Platform\Recruiting\Support\HrDeskRejectionStatus;
use Platform\Recruiting\Support\MinorAgeGate;

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
     *  - PublicExtraFieldForm.save() (core) — nach jedem Form-Save, vor checkAutoPilotCompletion
     *  - MCP/Code-Pfade die applicant-Daten ändern können
     *
     * Wichtig zur Phase-Stop-Kette: routeToHrDesk setzt auto_pilot=false,
     * damit checkAutoPilotCompletion() in der gleichen Request-Kette
     * NICHT mehr Phase-Advance triggert. Reihenfolge im Aufrufer ist
     * entscheidend (evaluateAndRoute VOR checkAutoPilotCompletion).
     */
    public function evaluateAndRoute(RecApplicant $applicant, ?int $userId = null): void
    {
        // Regel 1: Nicht-EU-Bürger — das ROUTING passiert seit der
        // Nach-Schulung-Umstellung NICHT mehr hier (P3), sondern im
        // RecInterviewBookingComplianceObserver beim Statuswechsel auf
        // 'attended'. Hier verbleibt nur der Auto-Close bei Korrektur
        // auf EU-Bürger.
        if ($applicant->legalStatus?->is_eu_citizen === true) {
            // Korrektur: Bewerber war non-EU, ist jetzt EU → obsoleten Case
            // automatisch schliessen damit er nicht orphaned auf dem
            // HR-Schreibtisch haengt. is_eu_citizen=null ist BEWUSST kein
            // Auto-Close (unklar, HR soll's noch sehen).
            $this->autoCloseObsoleteCases(
                $applicant,
                RecHrDeskCase::REASON_NON_EU_CITIZEN,
                'Automatisch geschlossen: Bewerber ist jetzt als EU-Buerger gekennzeichnet.'
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
        } elseif ($deutschkenntnisse === true) {
            $this->autoCloseObsoleteCases(
                $applicant,
                RecHrDeskCase::REASON_NO_GERMAN_KNOWLEDGE,
                'Automatisch geschlossen: Bewerber hat jetzt grundlegende Deutschkenntnisse angegeben.'
            );
        }

        // Weitere Regeln können hier ergänzt werden, z.B. minderjährig.
    }

    /**
     * Schliesst offene Cases fuer einen Reason, wenn die Routing-Bedingung
     * dieses Reasons nicht mehr zutrifft (z.B. Bewerber korrigiert
     * EU-Status). Setzt is_on_hr_desk=false NUR wenn danach kein anderer
     * offener Case mehr existiert — analog zu approveCase().
     *
     * Wichtig: das ist ein Auto-Resolution-Pfad, kein HR-Approve. Cases
     * fuer User-Aktionen (z.B. applicant_cancelled_training) bleiben
     * unangetastet — die brauchen menschliche HR-Entscheidung.
     */
    public function autoCloseObsoleteCases(RecApplicant $applicant, string $reason, string $notes): void
    {
        $openCases = $applicant->hrDeskCases()
            ->where('reason', $reason)
            ->open()
            ->get();

        if ($openCases->isEmpty()) {
            return;
        }

        foreach ($openCases as $case) {
            $case->update([
                'status'           => RecHrDeskCase::STATUS_APPROVED,
                'resolved_at'      => now(),
                'resolution_notes' => $notes,
            ]);

            RecAutoPilotLog::create([
                'rec_applicant_id' => $applicant->id,
                'type'             => 'hr_desk_auto_resolved',
                'summary'           => "HR-Schreibtisch-Fall automatisch geschlossen (Reason: {$reason}). {$notes}",
            ]);
        }

        $hasOtherOpenCases = $applicant->hrDeskCases()
            ->open()
            ->exists();

        if (!$hasOtherOpenCases && $applicant->is_on_hr_desk) {
            $applicant->update(['is_on_hr_desk' => false]);
        }
    }

    /**
     * Routet einen Bewerber auf den HR-Schreibtisch wenn fuer den gegebenen
     * Reason nicht bereits ein offener Case existiert. Verhindert Duplicate-
     * Cases bei wiederholtem Aufruf desselben Pfads (z.B. mehrfach abgesagt).
     *
     * Public weil ein-off-Pfade wie cancelSchulung() / cancelBooking() darauf
     * zugreifen — die laufen NICHT durch evaluateAndRoute() weil ihre Reasons
     * nicht aus Bewerber-Daten ableitbar sind sondern aus User-Aktion.
     *
     * $notes wird als Freitext auf den RecHrDeskCase geschrieben (z.B.
     * "Schulung am 25.05. in Koeln abgesagt" beim Cancel-Pfad). Bleibt
     * unter dem Reason-Badge auf der HR-Schreibtisch-Card sichtbar.
     */
    public function routeIfNotAlreadyOpen(RecApplicant $applicant, string $reason, ?int $userId = null, ?string $notes = null): void
    {
        $alreadyOpen = $applicant->hrDeskCases()
            ->where('reason', $reason)
            ->open()
            ->exists();

        if (!$alreadyOpen) {
            $this->routeToHrDesk($applicant, $reason, $userId, $notes);
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

    public function routeToHrDesk(RecApplicant $applicant, string $reason, ?int $userId = null, ?string $notes = null): RecHrDeskCase
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
            'notes' => $notes,
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
        $applicant = $case->applicant;

        // Guard: Nicht-EU-Fall darf nicht freigegeben werden, solange der
        // Rechtsstatus ungeprüft ist. Nur dieser menschliche Approve-Pfad
        // wird gegated — autoCloseObsoleteCases bewusst NICHT.
        if ($applicant && HrDeskApprovalGate::blocksApproval($case->reason, $applicant->isLegalStatusUnchecked())) {
            throw new LegalStatusNotCheckedException($case->rec_applicant_id);
        }

        $case->update([
            'status' => RecHrDeskCase::STATUS_APPROVED,
            'resolved_at' => now(),
            'resolved_by_user_id' => $userId,
            'resolution_notes' => $notes,
        ]);

        // Only release from HR desk if no other open cases remain
        $hasOtherOpenCases = $applicant->hrDeskCases()
            ->where('id', '!=', $case->id)
            ->open()
            ->exists();

        if (!$hasOtherOpenCases) {
            // Vollstaendige Freigabe: AutoPilot wieder aktivieren + Phase-
            // Completion-Check neu triggern. evaluateAndRoute hatte vorher
            // auto_pilot=false gesetzt als Sicherheits-Stopp — nach HR-
            // Approve muss der Flow weitergehen, sonst bleibt der Bewerber
            // in der Phase haengen und z.B. creates_employee_on_completion
            // wird nie ausgeloest obwohl die Vertraege bereits sent sind.
            // Direkteinstellung läuft bewusst OHNE AutoPilot — die Freigabe
            // darf den Aus-Zustand nicht überschreiben (der saving-Guard
            // verhindert nur true→false-Flips, nicht false→true).
            $isDirectHire = (bool) $applicant->primaryPosition()?->is_direct_hire;
            $applicant->update([
                'is_on_hr_desk' => false,
                'auto_pilot'    => !$isDirectHire,
            ]);

            try {
                $applicant->refresh();
                $applicant->checkAutoPilotCompletion();
            } catch (\Throwable $e) {
                // Fail-safe: HR-Approve darf nicht hart blockieren wenn
                // der Phase-Hook crasht — HR sieht es im Log.
                try {
                    RecAutoPilotLog::create([
                        'rec_applicant_id' => $applicant->id,
                        'type'             => 'phase_check_failed',
                        'summary'          => "Phase-Check nach HR-Approve fehlgeschlagen: " . $e->getMessage(),
                    ]);
                } catch (\Throwable) {}
            }
        }

        RecAutoPilotLog::create([
            'rec_applicant_id' => $applicant->id,
            'type' => 'hr_desk_approved',
            'summary' => "HR-Schreibtisch-Fall freigegeben." . ($notes ? " Notiz: {$notes}" : ''),
        ]);
    }

    public function rejectCase(RecHrDeskCase $case, int $userId, ?string $notes = null): void
    {
        // Fall-Abschluss und Bewerber-Update gehören zusammen: scheitert das
        // zweite Update (z.B. FK), darf der Fall nicht geschlossen zurückbleiben.
        DB::transaction(function () use ($case, $userId, $notes) {
            $this->applyRejection($case, $userId, $notes);
        });
    }

    private function applyRejection(RecHrDeskCase $case, int $userId, ?string $notes): void
    {
        $case->update([
            'status' => RecHrDeskCase::STATUS_REJECTED,
            'resolved_at' => now(),
            'resolved_by_user_id' => $userId,
            'resolution_notes' => $notes,
        ]);

        $applicant = $case->applicant;
        $attributes = [
            'rejected_at' => now(),
            'is_on_hr_desk' => false,
            'auto_pilot' => false,
            'is_active' => false,
        ];

        // Jugendschutz-Ablehnung stempelt denselben Status wie die U16-Auto-
        // Absage — sonst steht ein von HR abgelehnter 16/17-Jähriger ohne
        // sichtbaren Grund inaktiv in der Liste. Settings-Lookup bewusst erst
        // im Minderjährigen-Zweig (sonst liefe bei JEDER Ablehnung ein
        // firstOrCreate auf rec_applicant_settings).
        if ($case->reason === RecHrDeskCase::REASON_MINOR) {
            $stampStatusId = HrDeskRejectionStatus::resolve(
                $case->reason,
                MinorAgeGate::verdict($applicant->getExtraField('geburtsdatum'), new \DateTimeImmutable('today')),
                RecApplicantSettings::getOrCreateForTeam($applicant->team_id)->minorRejectionStatusId(),
            );

            // FK-Ziel prüfen: ein gelöschter/verstellter Status würde sonst
            // beim Update eine Constraint-Verletzung werfen — mitten in einer
            // bereits geschlossenen Fall-Zeile.
            if ($stampStatusId !== null && RecApplicantStatus::whereKey($stampStatusId)->exists()) {
                $attributes['rec_applicant_status_id'] = $stampStatusId;
            }
        }

        $applicant->update($attributes);

        RecAutoPilotLog::create([
            'rec_applicant_id' => $applicant->id,
            'type' => 'hr_desk_rejected',
            'summary' => "Bewerber über HR-Schreibtisch abgelehnt." . ($notes ? " Notiz: {$notes}" : ''),
        ]);
    }
}
