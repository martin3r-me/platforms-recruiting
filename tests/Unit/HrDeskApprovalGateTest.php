<?php

namespace Platform\Recruiting\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Models\RecHrDeskCase;
use Platform\Recruiting\Services\HrDeskApprovalGate;

class HrDeskApprovalGateTest extends TestCase
{
    public function test_non_eu_unchecked_blocks_approval(): void
    {
        // Nicht-EU-Fall + Rechtsstatus ungeprüft → Freigabe blockieren.
        $this->assertTrue(
            HrDeskApprovalGate::blocksApproval(RecHrDeskCase::REASON_NON_EU_CITIZEN, true)
        );
    }

    public function test_non_eu_checked_does_not_block(): void
    {
        // Nicht-EU-Fall, aber geprüft → Freigabe erlaubt.
        $this->assertFalse(
            HrDeskApprovalGate::blocksApproval(RecHrDeskCase::REASON_NON_EU_CITIZEN, false)
        );
    }

    public function test_other_reasons_never_block_even_when_unchecked(): void
    {
        // Andere Fall-Gründe hängen nicht am Rechtsstatus → nie blockieren.
        $this->assertFalse(
            HrDeskApprovalGate::blocksApproval(RecHrDeskCase::REASON_NO_GERMAN_KNOWLEDGE, true)
        );
        $this->assertFalse(
            HrDeskApprovalGate::blocksApproval(RecHrDeskCase::REASON_APPLICANT_CANCELLED_TRAINING, true)
        );
    }
}
