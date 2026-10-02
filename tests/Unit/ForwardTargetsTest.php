<?php

namespace Platform\Recruiting\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Services\Comms\Forward\ForwardTargets;

class ForwardTargetsTest extends TestCase
{
    public function test_hr_ist_bekannt_mit_texten(): void
    {
        $this->assertTrue(ForwardTargets::isTarget('hr'));
        $this->assertTrue(ForwardTargets::isSource('dispo'));
        $this->assertSame('HR', ForwardTargets::label('hr'));
        $this->assertSame('bei HR', ForwardTargets::chipOpen('hr'));
        $this->assertSame('HR erledigt', ForwardTargets::chipDone('hr'));
        $this->assertSame('An HR weiterleiten', ForwardTargets::action('hr'));
    }

    public function test_unbekanntes_ziel(): void
    {
        $this->assertFalse(ForwardTargets::isTarget('lohn'));
        $this->assertFalse(ForwardTargets::isSource('irgendwas'));
        $this->assertSame('lohn', ForwardTargets::label('lohn'));
    }
}
