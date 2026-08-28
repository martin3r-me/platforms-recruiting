<?php

namespace Platform\Recruiting\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Services\Zas\Dispo\DispoReplyWindow;

class DispoReplyWindowTest extends TestCase
{
    public function test_none_without_inbound(): void
    {
        $this->assertSame(['state' => 'none', 'left' => null], DispoReplyWindow::info(null, new \DateTimeImmutable('2026-08-28 10:00')));
    }

    public function test_open_with_hours_or_minutes_left(): void
    {
        $in = new \DateTimeImmutable('2026-08-28 10:00');
        $this->assertSame(['state' => 'open', 'left' => '22 h'], DispoReplyWindow::info($in, new \DateTimeImmutable('2026-08-28 12:00')));
        $this->assertSame(['state' => 'open', 'left' => '45 min'], DispoReplyWindow::info($in, new \DateTimeImmutable('2026-08-29 09:15')));
    }

    public function test_closed_after_24h(): void
    {
        $in = new \DateTimeImmutable('2026-08-28 10:00');
        $this->assertSame(['state' => 'closed', 'left' => null], DispoReplyWindow::info($in, new \DateTimeImmutable('2026-08-29 10:01')));
    }
}
