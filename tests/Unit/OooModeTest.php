<?php

namespace Platform\Recruiting\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Services\Comms\OooMode;

class OooModeTest extends TestCase
{
    public function test_disabled_is_off_regardless_of_dates(): void
    {
        $this->assertSame(OooMode::STATE_OFF, OooMode::state(false, '2026-07-01', '2026-07-20', '2026-07-10'));
    }

    public function test_missing_dates_are_off_never_forever_active(): void
    {
        // Fehlende Daten duerfen NIE "ewig aktiv" bedeuten.
        $this->assertSame(OooMode::STATE_OFF, OooMode::state(true, null, '2026-07-20', '2026-07-10'));
        $this->assertSame(OooMode::STATE_OFF, OooMode::state(true, '2026-07-01', null, '2026-07-10'));
        $this->assertSame(OooMode::STATE_OFF, OooMode::state(true, null, null, '2026-07-10'));
    }

    public function test_pending_before_from(): void
    {
        // Vorplanung: enabled, aber Abwesenheit hat noch nicht begonnen.
        $this->assertSame(OooMode::STATE_PENDING, OooMode::state(true, '2026-07-14', '2026-07-21', '2026-07-10'));
    }

    public function test_active_between_from_and_back_at(): void
    {
        $this->assertSame(OooMode::STATE_ACTIVE, OooMode::state(true, '2026-07-14', '2026-07-21', '2026-07-16'));
        // Grenztag: today == from -> aktiv
        $this->assertSame(OooMode::STATE_ACTIVE, OooMode::state(true, '2026-07-14', '2026-07-21', '2026-07-14'));
    }

    public function test_off_from_back_at_day_on(): void
    {
        // Grenztag: today == backAt -> aus (lazy Auto-Off ab dem Wieder-da-Tag)
        $this->assertSame(OooMode::STATE_OFF, OooMode::state(true, '2026-07-14', '2026-07-21', '2026-07-21'));
        $this->assertSame(OooMode::STATE_OFF, OooMode::state(true, '2026-07-14', '2026-07-21', '2026-08-01'));
    }

    public function test_is_active_only_in_active_state(): void
    {
        $this->assertTrue(OooMode::isActive(true, '2026-07-14', '2026-07-21', '2026-07-16'));
        $this->assertFalse(OooMode::isActive(true, '2026-07-14', '2026-07-21', '2026-07-10')); // pending
        $this->assertFalse(OooMode::isActive(true, '2026-07-14', '2026-07-21', '2026-07-21')); // abgelaufen
        $this->assertFalse(OooMode::isActive(false, '2026-07-14', '2026-07-21', '2026-07-16')); // aus
    }
}
