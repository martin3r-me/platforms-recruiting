<?php

namespace Platform\Recruiting\Tests\Unit\Dashboard;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Services\Dashboard\WhatsAppWindowResolver;

class WhatsAppWindowResolverTest extends TestCase
{
    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->now = new \DateTimeImmutable('2026-07-16 12:00:00');
    }

    public function test_inbound_vor_23h_ist_offen(): void
    {
        $map = WhatsAppWindowResolver::windowMap([7 => '2026-07-15 13:00:00'], $this->now);
        $this->assertSame([7 => true], $map);
    }

    public function test_inbound_vor_25h_ist_zu(): void
    {
        $map = WhatsAppWindowResolver::windowMap([7 => '2026-07-15 11:00:00'], $this->now);
        $this->assertSame([7 => false], $map);
    }

    public function test_exakt_24h_ist_zu(): void
    {
        // isWindowOpen nutzt striktes greaterThan — exakt 24h alt = zu
        $map = WhatsAppWindowResolver::windowMap([7 => '2026-07-15 12:00:00'], $this->now);
        $this->assertSame([7 => false], $map);
    }

    public function test_null_ist_zu(): void
    {
        $map = WhatsAppWindowResolver::windowMap([7 => null], $this->now);
        $this->assertSame([7 => false], $map);
    }

    public function test_string_keys_werden_int(): void
    {
        $map = WhatsAppWindowResolver::windowMap(['7' => '2026-07-16 11:00:00'], $this->now);
        $this->assertSame([7 => true], $map);
    }

    public function test_leere_map(): void
    {
        $this->assertSame([], WhatsAppWindowResolver::windowMap([], $this->now));
    }
}
