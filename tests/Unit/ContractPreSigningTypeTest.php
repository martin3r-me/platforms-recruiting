<?php

namespace Platform\Recruiting\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Support\ContractPreSigningType;

class ContractPreSigningTypeTest extends TestCase
{
    public function test_arbeitsvertrag_codes_get_the_paragraph_step(): void
    {
        $this->assertSame(ContractPreSigningType::PAR_15_16, ContractPreSigningType::forCode('AV-default'));
        $this->assertSame(ContractPreSigningType::PAR_15_16, ContractPreSigningType::forCode('AV-010'));
        $this->assertSame(ContractPreSigningType::PAR_15_16, ContractPreSigningType::forCode('AV-260'));
    }

    public function test_at140_gets_the_resttage_step(): void
    {
        $this->assertSame(ContractPreSigningType::RESTTAGE, ContractPreSigningType::forCode('AT-140'));
    }

    public function test_ifsg_and_unknown_codes_get_no_step(): void
    {
        $this->assertNull(ContractPreSigningType::forCode('IFSG'));
        $this->assertNull(ContractPreSigningType::forCode('AV'));
        $this->assertNull(ContractPreSigningType::forCode('SONSTIGES'));
    }

    public function test_other_at_codes_get_no_step(): void
    {
        // Zusatzvertraege ohne Resttage-Frage duerfen keinen Schritt bekommen.
        $this->assertNull(ContractPreSigningType::forCode('AT-SONSTIGES'));
    }

    public function test_null_and_empty_code_get_no_step(): void
    {
        $this->assertNull(ContractPreSigningType::forCode(null));
        $this->assertNull(ContractPreSigningType::forCode(''));
    }
}
