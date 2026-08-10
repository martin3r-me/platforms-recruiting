<?php

namespace Platform\Recruiting\Tests\Unit\Comms;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Services\Comms\ThreadRelinkPlanner;

final class ThreadRelinkPlannerTest extends TestCase
{
    // ── normalizePhone (delegiert an DuplicateApplicantGuard::canonicalDigits) ──

    public function test_normalize_kanonisiert_alle_formate_gleich(): void
    {
        $expected = '491637742867';
        $this->assertSame($expected, ThreadRelinkPlanner::normalizePhone('+491637742867'));
        $this->assertSame($expected, ThreadRelinkPlanner::normalizePhone('+49 163 7742867'));
        $this->assertSame($expected, ThreadRelinkPlanner::normalizePhone('01637742867'));
        $this->assertSame($expected, ThreadRelinkPlanner::normalizePhone('491637742867'));
        $this->assertSame($expected, ThreadRelinkPlanner::normalizePhone('0049 163/774-2867'));
    }

    public function test_normalize_matcht_kurze_festnetznummern_ueber_formatgrenzen(): void
    {
        // Regression: fixe Letzte-10-Ziffern-Keys liefen bei Nummern mit <10
        // signifikanten Ziffern auseinander ('0211876543' vs '+49211876543').
        $this->assertSame(
            ThreadRelinkPlanner::normalizePhone('0211876543'),
            ThreadRelinkPlanner::normalizePhone('+49211876543'),
        );
    }

    public function test_normalize_lehnt_leere_und_zu_kurze_werte_ab(): void
    {
        $this->assertNull(ThreadRelinkPlanner::normalizePhone(null));
        $this->assertNull(ThreadRelinkPlanner::normalizePhone(''));
        $this->assertNull(ThreadRelinkPlanner::normalizePhone('12345'));
        $this->assertNull(ThreadRelinkPlanner::normalizePhone('keine nummer'));
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

    public function test_bei_gleichem_status_gewinnt_der_senior(): void
    {
        // Senior-Regel wie DuplicateApplicantGuard: kleinste ID besitzt den
        // Chat — Dedup/Reminder-Logik behandelt denselben Bewerber als
        // Eigentümer der Nummer.
        $chosen = ThreadRelinkPlanner::chooseApplicant([
            ['id' => 1012, 'is_active' => true],
            ['id' => 680, 'is_active' => true],
        ]);
        $this->assertSame(680, $chosen['id']);

        $chosenInactive = ThreadRelinkPlanner::chooseApplicant([
            ['id' => 1012, 'is_active' => false],
            ['id' => 680, 'is_active' => false],
        ]);
        $this->assertSame(680, $chosenInactive['id']);
    }

    public function test_keine_kandidaten_ergibt_null(): void
    {
        $this->assertNull(ThreadRelinkPlanner::chooseApplicant([]));
    }
}
