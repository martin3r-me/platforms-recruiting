<?php

namespace Platform\Recruiting\Tests\Unit\Statistics;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Support\PhaseTransitionTrigger;

class PhaseTransitionTriggerTest extends TestCase
{
    public function test_default_ist_unknown(): void
    {
        $this->assertSame('unknown', PhaseTransitionTrigger::consume(1));
    }

    public function test_consume_liefert_wert_bei_id_match_und_leert(): void
    {
        PhaseTransitionTrigger::set(7, PhaseTransitionTrigger::MANUAL);
        $this->assertSame('manual', PhaseTransitionTrigger::consume(7));
        $this->assertSame('unknown', PhaseTransitionTrigger::consume(7), 'nach consume geleert');
    }

    public function test_id_mismatch_liefert_unknown_und_leert_trotzdem(): void
    {
        // P1: liegengebliebener Trigger (Observer feuerte nie) darf den
        // naechsten Wechsel eines ANDEREN Bewerbers nicht etikettieren.
        PhaseTransitionTrigger::set(7, PhaseTransitionTrigger::MANUAL);
        $this->assertSame('unknown', PhaseTransitionTrigger::consume(8));
        $this->assertSame('unknown', PhaseTransitionTrigger::consume(7), 'Mismatch leert auch');
    }

    public function test_forget_leert_nur_bei_passender_id(): void
    {
        PhaseTransitionTrigger::set(7, PhaseTransitionTrigger::RETURNED);
        PhaseTransitionTrigger::forget(9);
        $this->assertSame('returned', PhaseTransitionTrigger::consume(7), 'fremde ID leert nicht');

        PhaseTransitionTrigger::set(7, PhaseTransitionTrigger::RETURNED);
        PhaseTransitionTrigger::forget(7);
        $this->assertSame('unknown', PhaseTransitionTrigger::consume(7));
    }

    public function test_konstanten_vollstaendig(): void
    {
        $this->assertSame('auto_advance', PhaseTransitionTrigger::AUTO_ADVANCE);
        $this->assertSame('returned', PhaseTransitionTrigger::RETURNED);
        $this->assertSame('position_switch', PhaseTransitionTrigger::POSITION_SWITCH);
        $this->assertSame('fix', PhaseTransitionTrigger::FIX);
        $this->assertSame('phase_deleted', PhaseTransitionTrigger::PHASE_DELETED);
    }
}
