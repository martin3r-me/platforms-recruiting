<?php

namespace Platform\Recruiting\Tests\Unit\Dispo\Jobs;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Models\RecDispoJobRequest;
use Platform\Recruiting\Services\Zas\Dispo\Jobs\DispoJobGedeckt;

final class DispoJobGedecktTest extends TestCase
{
    public function testGedecktDurchZusagenOderVonHand(): void
    {
        $z = RecDispoJobRequest::MODUS_ZUERST;
        $this->assertTrue(DispoJobGedeckt::istGedeckt((object) ['gesucht' => 2, 'gedeckt_manuell_at' => null], 2, $z));
        $this->assertFalse(DispoJobGedeckt::istGedeckt((object) ['gesucht' => 2, 'gedeckt_manuell_at' => null], 1, $z));
        $this->assertTrue(DispoJobGedeckt::istGedeckt((object) ['gesucht' => 5, 'gedeckt_manuell_at' => '2026-10-27 10:00:00'], 0, $z), 'von Hand');
        $this->assertFalse(DispoJobGedeckt::istGedeckt((object) ['gesucht' => 0, 'gedeckt_manuell_at' => null], 3, $z), 'ohne Soll nie automatisch gedeckt');
        $this->assertTrue(DispoJobGedeckt::istGedeckt(['gesucht' => 1, 'gedeckt_manuell_at' => null, 'zugesagt' => 1], null, $z), 'Array mit zugesagt-Feld');
    }

    /** Spec 2026-11-20 Punkt 4: „sammeln“ ist nur von Hand gedeckt; genug Zusagen ist dort nur ein Hinweis. */
    public function testSammelnNurVonHandGedeckt(): void
    {
        $s = RecDispoJobRequest::MODUS_SAMMELN;
        $this->assertFalse(DispoJobGedeckt::istGedeckt((object) ['gesucht' => 2, 'gedeckt_manuell_at' => null], 5, $s));
        $this->assertTrue(DispoJobGedeckt::istGedeckt((object) ['gesucht' => 2, 'gedeckt_manuell_at' => '2026-11-20 10:00:00'], 0, $s));
        $this->assertTrue(DispoJobGedeckt::genugZusagen((object) ['gesucht' => 2], 2));
        $this->assertFalse(DispoJobGedeckt::genugZusagen((object) ['gesucht' => 2], 1));
    }
}
