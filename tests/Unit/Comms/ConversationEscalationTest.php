<?php

namespace Platform\Recruiting\Tests\Unit\Comms;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Services\Comms\ConversationEscalation;

class ConversationEscalationTest extends TestCase
{
    private const HOUR = 3600;

    private function ts(int $hoursFromNow, int $now): int
    {
        return $now + $hoursFromNow * self::HOUR;
    }

    public function test_no_inbound_yields_none(): void
    {
        $now = 1_000_000;
        $e = ConversationEscalation::compute(null, null, $now);

        $this->assertSame(ConversationEscalation::LEVEL_NONE, $e->level);
        $this->assertFalse($e->isUnanswered);
        $this->assertFalse($e->windowOpen);
        $this->assertNull($e->windowExpiresAt);
    }

    public function test_answered_thread_is_none(): void
    {
        // Outbound NACH dem letzten Inbound → beantwortet → kein Eskalations-Level.
        $now = 1_000_000;
        $lastInbound = $this->ts(-5, $now);
        $lastOutbound = $this->ts(-4, $now);

        $e = ConversationEscalation::compute($lastInbound, $lastOutbound, $now);

        $this->assertSame(ConversationEscalation::LEVEL_NONE, $e->level);
        $this->assertFalse($e->isUnanswered);
    }

    public function test_unanswered_fresh_inbound_is_green(): void
    {
        // Eingang vor 1h, keine Antwort → 23h Restzeit → grün.
        $now = 1_000_000;
        $lastInbound = $this->ts(-1, $now);

        $e = ConversationEscalation::compute($lastInbound, null, $now);

        $this->assertSame(ConversationEscalation::LEVEL_GREEN, $e->level);
        $this->assertTrue($e->isUnanswered);
        $this->assertTrue($e->windowOpen);
        $this->assertSame($lastInbound + 24 * self::HOUR, $e->windowExpiresAt);
        $this->assertEqualsWithDelta(23.0, $e->hoursLeftInWindow, 0.001);
    }

    public function test_inbound_older_than_outbound_but_newer_inbound_is_unanswered(): void
    {
        // Verlauf: outbound, dann neuer inbound → wieder unbeantwortet.
        $now = 1_000_000;
        $lastOutbound = $this->ts(-3, $now);
        $lastInbound = $this->ts(-1, $now);

        $e = ConversationEscalation::compute($lastInbound, $lastOutbound, $now);

        $this->assertTrue($e->isUnanswered);
        $this->assertSame(ConversationEscalation::LEVEL_GREEN, $e->level);
    }

    public function test_window_closing_soon_is_yellow(): void
    {
        // 10h Restzeit (Eingang vor 14h) → ≤ 12h Gelb-Schwelle, > 3h Rot → gelb.
        $now = 1_000_000;
        $lastInbound = $this->ts(-14, $now);

        $e = ConversationEscalation::compute($lastInbound, null, $now);

        $this->assertSame(ConversationEscalation::LEVEL_YELLOW, $e->level);
        $this->assertTrue($e->windowOpen);
    }

    public function test_window_almost_closed_is_red(): void
    {
        // 2h Restzeit (Eingang vor 22h) → ≤ 3h Rot-Schwelle, Fenster noch offen → rot.
        $now = 1_000_000;
        $lastInbound = $this->ts(-22, $now);

        $e = ConversationEscalation::compute($lastInbound, null, $now);

        $this->assertSame(ConversationEscalation::LEVEL_RED, $e->level);
        $this->assertTrue($e->windowOpen);
    }

    public function test_closed_window_unanswered_is_missed(): void
    {
        // Eingang vor 30h, keine Antwort → Fenster zu → verpasst.
        $now = 1_000_000;
        $lastInbound = $this->ts(-30, $now);

        $e = ConversationEscalation::compute($lastInbound, null, $now);

        $this->assertSame(ConversationEscalation::LEVEL_MISSED, $e->level);
        $this->assertTrue($e->isUnanswered);
        $this->assertFalse($e->windowOpen);
        $this->assertLessThan(0.0, $e->hoursLeftInWindow);
    }

    public function test_yellow_threshold_is_inclusive(): void
    {
        // Genau 12h Restzeit (Eingang vor 12h) → ≤ 12h → gelb (nicht grün).
        $now = 1_000_000;
        $lastInbound = $this->ts(-12, $now);

        $e = ConversationEscalation::compute($lastInbound, null, $now);

        $this->assertSame(ConversationEscalation::LEVEL_YELLOW, $e->level);
    }

    public function test_red_threshold_is_inclusive(): void
    {
        // Genau 3h Restzeit (Eingang vor 21h) → ≤ 3h → rot (nicht gelb).
        $now = 1_000_000;
        $lastInbound = $this->ts(-21, $now);

        $e = ConversationEscalation::compute($lastInbound, null, $now);

        $this->assertSame(ConversationEscalation::LEVEL_RED, $e->level);
    }

    public function test_window_exactly_expired_is_missed(): void
    {
        // Exakt 24h her → Restzeit 0 → Fenster zu → verpasst.
        $now = 1_000_000;
        $lastInbound = $this->ts(-24, $now);

        $e = ConversationEscalation::compute($lastInbound, null, $now);

        $this->assertSame(ConversationEscalation::LEVEL_MISSED, $e->level);
        $this->assertFalse($e->windowOpen);
    }

    public function test_custom_thresholds_are_respected(): void
    {
        // Gelb-Schwelle 6h, Rot-Schwelle 1h. 4h Restzeit (Eingang vor 20h) → gelb.
        $now = 1_000_000;
        $lastInbound = $this->ts(-20, $now);

        $e = ConversationEscalation::compute($lastInbound, null, $now, 6.0, 1.0);

        $this->assertSame(ConversationEscalation::LEVEL_YELLOW, $e->level);
    }
}
