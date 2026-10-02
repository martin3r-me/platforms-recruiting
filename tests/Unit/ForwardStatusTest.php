<?php

namespace Platform\Recruiting\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Services\Comms\Forward\ForwardStatus;

class ForwardStatusTest extends TestCase
{
    public function test_keine_weiterleitung_kein_chip(): void
    {
        $this->assertNull(ForwardStatus::latest([]));
    }

    public function test_juengste_gewinnt_auch_wenn_aeltere_offen(): void
    {
        $this->assertSame(['state' => ForwardStatus::DONE, 'target' => 'hr'], ForwardStatus::latest([
            ['forwarded_at' => 100, 'done' => false, 'target' => 'hr'],
            ['forwarded_at' => 200, 'done' => true, 'target' => 'hr'],
        ]));
        $this->assertSame(['state' => ForwardStatus::OPEN, 'target' => 'lohn'], ForwardStatus::latest([
            ['forwarded_at' => 300, 'done' => false, 'target' => 'lohn'],
            ['forwarded_at' => 200, 'done' => true, 'target' => 'hr'],
        ]));
    }
}
