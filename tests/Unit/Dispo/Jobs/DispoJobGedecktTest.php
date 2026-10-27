<?php

namespace Platform\Recruiting\Tests\Unit\Dispo\Jobs;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Services\Zas\Dispo\Jobs\DispoJobGedeckt;

final class DispoJobGedecktTest extends TestCase
{
    public function testGedecktDurchZusagenOderVonHand(): void
    {
        $this->assertTrue(DispoJobGedeckt::istGedeckt((object) ['gesucht' => 2, 'gedeckt_manuell_at' => null], 2));
        $this->assertFalse(DispoJobGedeckt::istGedeckt((object) ['gesucht' => 2, 'gedeckt_manuell_at' => null], 1));
        $this->assertTrue(DispoJobGedeckt::istGedeckt((object) ['gesucht' => 5, 'gedeckt_manuell_at' => '2026-10-27 10:00:00'], 0), 'von Hand');
        $this->assertFalse(DispoJobGedeckt::istGedeckt((object) ['gesucht' => 0, 'gedeckt_manuell_at' => null], 3), 'ohne Soll nie automatisch gedeckt');
        $this->assertTrue(DispoJobGedeckt::istGedeckt(['gesucht' => 1, 'gedeckt_manuell_at' => null, 'zugesagt' => 1]), 'Array mit zugesagt-Feld');
    }
}
