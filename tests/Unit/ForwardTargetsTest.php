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

    public function test_drei_richtungen_und_keine_weitere(): void
    {
        $this->assertTrue(ForwardTargets::isRoute('dispo', 'hr'));
        $this->assertTrue(ForwardTargets::isRoute('hr', 'dispo'));
        $this->assertTrue(ForwardTargets::isRoute('dispo', 'dispo'), 'Dispo an die Dispo einer anderen Niederlassung (18.11.2026).');
        $this->assertFalse(ForwardTargets::isRoute('hr', 'hr'));
        $this->assertFalse(ForwardTargets::isRoute('lohn', 'hr'));
        $this->assertFalse(ForwardTargets::isRoute('dispo', 'lohn'));
    }

    public function test_chip_traegt_den_namen_der_niederlassung(): void
    {
        $this->assertSame('bei Dispo Köln', ForwardTargets::chipOpen('dispo', 'Dispo Köln'));
        $this->assertSame('Dispo Köln erledigt', ForwardTargets::chipDone('dispo', 'Dispo Köln'));
        $this->assertSame('bei Dispo', ForwardTargets::chipOpen('dispo', null));
    }
}
