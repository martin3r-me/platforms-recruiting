<?php

namespace Platform\Recruiting\Tests\Unit\Comms;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Services\Comms\VoiceNoteAutoReplyThrottle;

class VoiceNoteAutoReplyThrottleTest extends TestCase
{
    private const HOUR = 3600;
    private const NOW = 1_000_000;

    public function test_never_sent_does_not_skip(): void
    {
        $this->assertFalse(VoiceNoteAutoReplyThrottle::shouldSkip(null, self::NOW));
    }

    public function test_sent_within_window_skips(): void
    {
        // vor 5h gesendet, Fenster 24h → drosseln (skip).
        $lastSent = self::NOW - 5 * self::HOUR;
        $this->assertTrue(VoiceNoteAutoReplyThrottle::shouldSkip($lastSent, self::NOW));
    }

    public function test_sent_before_window_does_not_skip(): void
    {
        // vor 25h gesendet → außerhalb 24h → wieder senden.
        $lastSent = self::NOW - 25 * self::HOUR;
        $this->assertFalse(VoiceNoteAutoReplyThrottle::shouldSkip($lastSent, self::NOW));
    }

    public function test_window_boundary_is_not_skipped(): void
    {
        // exakt 24h her → Fenster gerade abgelaufen → nicht drosseln.
        $lastSent = self::NOW - 24 * self::HOUR;
        $this->assertFalse(VoiceNoteAutoReplyThrottle::shouldSkip($lastSent, self::NOW));
    }

    public function test_custom_window(): void
    {
        // Fenster 1h: vor 30min → skip; vor 2h → senden.
        $this->assertTrue(VoiceNoteAutoReplyThrottle::shouldSkip(self::NOW - 1800, self::NOW, 1));
        $this->assertFalse(VoiceNoteAutoReplyThrottle::shouldSkip(self::NOW - 2 * self::HOUR, self::NOW, 1));
    }
}
