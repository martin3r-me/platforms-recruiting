<?php

namespace Platform\Recruiting\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Services\ContractSendEligibility;

class ContractSendEligibilityTest extends TestCase
{
    public function test_bereits_gesendet_gewinnt_vor_allem(): void
    {
        $this->assertSame('already_sent', ContractSendEligibility::state(true, true, false, false));
    }

    public function test_legal_block_vor_feldern(): void
    {
        $this->assertSame('legal_blocked', ContractSendEligibility::state(false, true, true, true));
    }

    public function test_fehlender_beginn_vor_zuschlag(): void
    {
        $this->assertSame('missing_beginn', ContractSendEligibility::state(false, false, false, false));
    }

    public function test_fehlender_zuschlag(): void
    {
        $this->assertSame('missing_zuschlag', ContractSendEligibility::state(false, false, true, false));
    }

    public function test_ready(): void
    {
        $this->assertSame('ready', ContractSendEligibility::state(false, false, true, true));
    }

    public function test_fehlende_mitarbeiter_anlage_blockt_nach_rechtsstatus_und_vor_den_daten(): void
    {
        $this->assertSame('no_employee_path', ContractSendEligibility::state(false, false, false, false, true));
        $this->assertSame('legal_blocked', ContractSendEligibility::state(false, true, true, true, true));
        $this->assertSame('already_sent', ContractSendEligibility::state(true, false, true, true, true));
    }
}
