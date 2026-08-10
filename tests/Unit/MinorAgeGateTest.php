<?php

namespace Platform\Recruiting\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Support\MinorAgeGate;

final class MinorAgeGateTest extends TestCase
{
    private \DateTimeImmutable $today;

    protected function setUp(): void
    {
        $this->today = new \DateTimeImmutable('2026-08-10');
    }

    public function test_unter_16_wird_abgelehnt(): void
    {
        $this->assertSame(MinorAgeGate::VERDICT_REJECT, MinorAgeGate::verdict('2011-01-07', $this->today)); // 15
        $this->assertSame(MinorAgeGate::VERDICT_REJECT, MinorAgeGate::verdict('2010-08-25', $this->today)); // 15, wird in 2 Wochen 16
        $this->assertSame(MinorAgeGate::VERDICT_REJECT, MinorAgeGate::verdict('2012-12-31', $this->today)); // 13
    }

    public function test_16_bis_17_geht_in_die_pruefung(): void
    {
        $this->assertSame(MinorAgeGate::VERDICT_REVIEW, MinorAgeGate::verdict('2010-08-10', $this->today)); // 16. Geburtstag HEUTE
        $this->assertSame(MinorAgeGate::VERDICT_REVIEW, MinorAgeGate::verdict('2009-11-28', $this->today)); // 16
        $this->assertSame(MinorAgeGate::VERDICT_REVIEW, MinorAgeGate::verdict('2008-08-11', $this->today)); // 17, morgen 18
    }

    public function test_ab_18_passiert_nichts(): void
    {
        $this->assertSame(MinorAgeGate::VERDICT_PASS, MinorAgeGate::verdict('2008-08-10', $this->today)); // 18. Geburtstag HEUTE
        $this->assertSame(MinorAgeGate::VERDICT_PASS, MinorAgeGate::verdict('1990-05-15', $this->today));
    }

    public function test_deutsche_schreibweise_wird_geparst(): void
    {
        $this->assertSame(MinorAgeGate::VERDICT_REJECT, MinorAgeGate::verdict('07.01.2011', $this->today));
        $this->assertSame(MinorAgeGate::VERDICT_PASS, MinorAgeGate::verdict('15.05.1990', $this->today));
    }

    public function test_fehlende_oder_kaputte_werte_sind_unknown(): void
    {
        $this->assertSame(MinorAgeGate::VERDICT_UNKNOWN, MinorAgeGate::verdict(null, $this->today));
        $this->assertSame(MinorAgeGate::VERDICT_UNKNOWN, MinorAgeGate::verdict('', $this->today));
        $this->assertSame(MinorAgeGate::VERDICT_UNKNOWN, MinorAgeGate::verdict('kein datum', $this->today));
        $this->assertSame(MinorAgeGate::VERDICT_UNKNOWN, MinorAgeGate::verdict('2026-99-99', $this->today));
        $this->assertSame(MinorAgeGate::VERDICT_UNKNOWN, MinorAgeGate::verdict(['array'], $this->today));
    }

    public function test_zukunftsdatum_ist_unknown(): void
    {
        // Tippfehler wie 2062 statt 2002 → nicht als "Alter 0" ablehnen,
        // sondern als unplausibel behandeln (Backstop blockt, Mensch klärt).
        $this->assertSame(MinorAgeGate::VERDICT_UNKNOWN, MinorAgeGate::verdict('2062-05-15', $this->today));
    }
}
