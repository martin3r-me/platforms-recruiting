<?php

namespace Platform\Recruiting\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Models\RecHrDeskCase;
use Platform\Recruiting\Services\HrDeskApprovalGate;

/**
 * Klaerungsfall aus der Schulung (Markus, 01.09.2026): der Schulungsleiter
 * markiert eine Buchung „zur Klaerung an HR" (Gehaltswunsch, Nachfrage, …) —
 * ein HR-Schreibtisch-Fall mit seiner Notiz, und solange er offen ist, geht
 * fuer diese Person kein Vertrag raus.
 */
final class HrDeskTrainingClarificationTest extends TestCase
{
    public function test_reason_existiert_mit_label(): void
    {
        $this->assertSame('training_clarification', RecHrDeskCase::REASON_TRAINING_CLARIFICATION);
        $this->assertSame(
            'Klärung aus der Schulung',
            RecHrDeskCase::REASON_LABELS[RecHrDeskCase::REASON_TRAINING_CLARIFICATION],
            'der HR-Schreibtisch-Filter iteriert ueber REASON_LABELS — ohne Label ist der Fall unfilterbar',
        );
    }

    public function test_freigabe_ist_nicht_gegated(): void
    {
        // Das Rechtsstatus-Gate gehoert exklusiv zum Non-EU-Fall: eine Klaerung
        // aus der Schulung kann HR immer freigeben, auch wenn der Rechtsstatus
        // (noch) ungeprueft ist — das prueft der Non-EU-Fall selbst.
        $this->assertFalse(HrDeskApprovalGate::blocksApproval(RecHrDeskCase::REASON_TRAINING_CLARIFICATION, true));
    }

    public function test_vertragsversand_blockende_reasons_sind_eine_liste(): void
    {
        // EINE Quelle fuer „welcher offene Fall blockiert den Vertragsversand"
        // — vorher stand die Aufzaehlung wortgleich im Computed der
        // Buchungsseite und implizit im Blade.
        $this->assertSame(
            [
                RecHrDeskCase::REASON_NON_EU_CITIZEN,
                RecHrDeskCase::REASON_MINOR,
                RecHrDeskCase::REASON_TRAINING_CLARIFICATION,
            ],
            RecHrDeskCase::CONTRACT_BLOCKING_REASONS,
        );
    }
}
