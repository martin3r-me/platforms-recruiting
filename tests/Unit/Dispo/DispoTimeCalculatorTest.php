<?php

namespace Platform\Recruiting\Tests\Unit\Dispo;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Services\Zas\Dispo\DispoTimeCalculator;

class DispoTimeCalculatorTest extends TestCase
{
    public function test_arrival_time(): void
    {
        $this->assertSame('15:30', DispoTimeCalculator::arrivalTime('16:00', 30));
        $this->assertSame('16:00', DispoTimeCalculator::arrivalTime('16:00', null));
        $this->assertSame('16:00', DispoTimeCalculator::arrivalTime('16:00', 0));
        $this->assertNull(DispoTimeCalculator::arrivalTime(null, 30));
    }

    public function test_arrival_time_crosses_midnight(): void
    {
        $this->assertSame('23:30', DispoTimeCalculator::arrivalTime('00:15', 45));
    }

    public function test_confirmation_deadline(): void
    {
        $this->assertSame('2026-08-20 12:00', DispoTimeCalculator::confirmationDeadline('2026-08-20', '16:00', 4));
        $this->assertSame('2026-08-20 00:30', DispoTimeCalculator::confirmationDeadline('2026-08-20', '04:30', 4));
    }

    public function test_confirmation_deadline_without_von_is_conservative(): void
    {
        $this->assertSame('2026-08-19 20:00', DispoTimeCalculator::confirmationDeadline('2026-08-20', null, 4));
    }
}
