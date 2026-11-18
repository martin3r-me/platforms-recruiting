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
        $this->assertSame(['state' => ForwardStatus::DONE, 'target' => 'hr', 'label' => null], ForwardStatus::latest([
            ['forwarded_at' => 100, 'done' => false, 'target' => 'hr'],
            ['forwarded_at' => 200, 'done' => true, 'target' => 'hr'],
        ]));
        $this->assertSame(['state' => ForwardStatus::OPEN, 'target' => 'lohn', 'label' => null], ForwardStatus::latest([
            ['forwarded_at' => 300, 'done' => false, 'target' => 'lohn'],
            ['forwarded_at' => 200, 'done' => true, 'target' => 'hr'],
        ]));
    }

    public function test_die_beschriftung_gehoert_zur_juengsten_weiterleitung(): void
    {
        // Erst an Köln, danach an HR: der Chip darf nicht mehr „Dispo Köln“ tragen.
        $this->assertSame(['state' => ForwardStatus::OPEN, 'target' => 'hr', 'label' => null], ForwardStatus::latest([
            ['forwarded_at' => 100, 'done' => true, 'target' => 'dispo', 'label' => 'Dispo Köln'],
            ['forwarded_at' => 200, 'done' => false, 'target' => 'hr', 'label' => null],
        ]));
        $this->assertSame(['state' => ForwardStatus::DONE, 'target' => 'dispo', 'label' => 'Dispo Köln'], ForwardStatus::latest([
            ['forwarded_at' => 300, 'done' => true, 'target' => 'dispo', 'label' => 'Dispo Köln'],
            ['forwarded_at' => 200, 'done' => false, 'target' => 'hr'],
        ]));
    }
}
