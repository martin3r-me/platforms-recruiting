<?php

namespace Platform\Recruiting\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Services\Comms\TeamClock;

class TeamClockTest extends TestCase
{
    public function test_configured_timezone_is_used(): void
    {
        $this->assertSame('Pacific/Auckland', TeamClock::resolveTimezone('Pacific/Auckland'));
        $this->assertSame('UTC', TeamClock::resolveTimezone('UTC'));
    }

    public function test_missing_timezone_falls_back_to_berlin(): void
    {
        $this->assertSame('Europe/Berlin', TeamClock::resolveTimezone(null));
        $this->assertSame('Europe/Berlin', TeamClock::resolveTimezone(''));
        $this->assertSame('Europe/Berlin', TeamClock::resolveTimezone('   '));
    }

    public function test_today_returns_ymd_format(): void
    {
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', TeamClock::today(null));
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', TeamClock::today('Pacific/Auckland'));
    }

    public function test_today_respects_timezone(): void
    {
        // Kiritimati (UTC+14) und Niue (UTC-11) liegen 25h auseinander —
        // ihre lokalen Kalenderdaten sind zu JEDEM Zeitpunkt verschieden.
        // Deterministischer Beleg, dass die uebergebene TZ wirklich wirkt.
        $this->assertNotSame(
            TeamClock::today('Pacific/Kiritimati'),
            TeamClock::today('Pacific/Niue'),
        );
    }
}
