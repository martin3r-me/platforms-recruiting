<?php

namespace Platform\Recruiting\Tests\Unit\Comms;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Services\Comms\ThreadRelinkPlanner;

final class ThreadRelinkPlannerTest extends TestCase
{
    // ── normalizePhone ───────────────────────────────────────────────

    public function test_normalize_reduziert_auf_letzte_zehn_ziffern(): void
    {
        $this->assertSame('1637742867', ThreadRelinkPlanner::normalizePhone('+491637742867'));
        $this->assertSame('1637742867', ThreadRelinkPlanner::normalizePhone('+49 163 7742867'));
        $this->assertSame('1637742867', ThreadRelinkPlanner::normalizePhone('01637742867'));
        $this->assertSame('1637742867', ThreadRelinkPlanner::normalizePhone('1637742867'));
        $this->assertSame('1637742867', ThreadRelinkPlanner::normalizePhone('0049 163/774-2867'));
    }

    public function test_normalize_lehnt_zu_kurze_nummern_ab(): void
    {
        $this->assertNull(ThreadRelinkPlanner::normalizePhone(null));
        $this->assertNull(ThreadRelinkPlanner::normalizePhone(''));
        $this->assertNull(ThreadRelinkPlanner::normalizePhone('12345'));
        $this->assertNull(ThreadRelinkPlanner::normalizePhone('keine nummer'));
    }

    // ── phonesMatch ──────────────────────────────────────────────────

    public function test_match_ueber_formatgrenzen(): void
    {
        $this->assertTrue(ThreadRelinkPlanner::phonesMatch('+491637742867', '01637742867'));
        $this->assertTrue(ThreadRelinkPlanner::phonesMatch('+49 163 7742867', '+491637742867'));
        $this->assertFalse(ThreadRelinkPlanner::phonesMatch('+491637742867', '+491637742868'));
        $this->assertFalse(ThreadRelinkPlanner::phonesMatch(null, '+491637742867'));
        $this->assertFalse(ThreadRelinkPlanner::phonesMatch('123', '123'));
    }

    // ── chooseApplicant ──────────────────────────────────────────────

    public function test_einziger_kandidat_gewinnt(): void
    {
        $chosen = ThreadRelinkPlanner::chooseApplicant([
            ['id' => 2474, 'is_active' => true],
        ]);
        $this->assertSame(2474, $chosen['id']);
    }

    public function test_aktiver_kandidat_schlaegt_inaktiven(): void
    {
        $chosen = ThreadRelinkPlanner::chooseApplicant([
            ['id' => 1528, 'is_active' => false],
            ['id' => 307, 'is_active' => true],
        ]);
        $this->assertSame(307, $chosen['id']);
    }

    public function test_bei_gleichem_status_gewinnt_der_neueste(): void
    {
        $chosen = ThreadRelinkPlanner::chooseApplicant([
            ['id' => 680, 'is_active' => true],
            ['id' => 1012, 'is_active' => true],
        ]);
        $this->assertSame(1012, $chosen['id']);

        $chosenInactive = ThreadRelinkPlanner::chooseApplicant([
            ['id' => 680, 'is_active' => false],
            ['id' => 1012, 'is_active' => false],
        ]);
        $this->assertSame(1012, $chosenInactive['id']);
    }

    public function test_keine_kandidaten_ergibt_null(): void
    {
        $this->assertNull(ThreadRelinkPlanner::chooseApplicant([]));
    }
}
