<?php

namespace Platform\Recruiting\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Services\EmployeeContactListSyncService;

class EmployeeContactListDiffTest extends TestCase
{
    public function test_klassifiziert_add_normalize_remove_unchanged(): void
    {
        $diff = EmployeeContactListSyncService::computeDiff(
            [1, 2, 3],
            [2 => 'subscribed', 3 => 'unsubscribed', 4 => 'subscribed'],
        );

        $this->assertSame([1], $diff->toAdd);
        $this->assertSame([3], $diff->toNormalize);  // unsubscribed -> subscribe
        $this->assertSame([4], $diff->toRemove);
        $this->assertSame(1, $diff->unchanged);      // Kontakt 2
        $this->assertFalse($diff->guardTripped);
        $this->assertNull($diff->guardReason);
    }

    public function test_guard_bei_leerer_soll_menge_nicht_mit_force_uebersteuerbar(): void
    {
        $diff = EmployeeContactListSyncService::computeDiff([], [1 => 'subscribed'], force: true);

        $this->assertTrue($diff->guardTripped);
        $this->assertSame('empty_soll', $diff->guardReason);
        $this->assertSame([1], $diff->toRemove);
    }

    public function test_leere_soll_und_leere_ist_menge_ist_ok(): void
    {
        $diff = EmployeeContactListSyncService::computeDiff([], []);

        $this->assertFalse($diff->guardTripped);
        $this->assertNull($diff->guardReason);
    }

    public function test_guard_bei_mehr_als_25_entfernungen(): void
    {
        $ist = [];
        foreach (range(1, 100) as $i) {
            $ist[$i] = 'subscribed';
        }

        // Soll = 1..74 -> 26 Entfernungen (26 % der Liste): > 25 triggert, obwohl Ratio < 50 %.
        $diff = EmployeeContactListSyncService::computeDiff(range(1, 74), $ist);

        $this->assertTrue($diff->guardTripped);
        $this->assertSame('threshold', $diff->guardReason);
        $this->assertCount(26, $diff->toRemove);
    }

    public function test_guard_bei_mehr_als_50_prozent_entfernungen(): void
    {
        $ist = [1 => 'subscribed', 2 => 'subscribed', 3 => 'subscribed'];

        // 2 von 3 Zeilen = 66 % > 50 %, obwohl absolut <= 25.
        $diff = EmployeeContactListSyncService::computeDiff([1], $ist);

        $this->assertTrue($diff->guardTripped);
        $this->assertSame('threshold', $diff->guardReason);
    }

    public function test_force_uebersteuert_schwellen_guard(): void
    {
        $ist = [1 => 'subscribed', 2 => 'subscribed', 3 => 'subscribed'];

        $diff = EmployeeContactListSyncService::computeDiff([1], $ist, force: true);

        $this->assertFalse($diff->guardTripped);
        $this->assertNull($diff->guardReason);
        $this->assertCount(2, $diff->toRemove);
    }

    public function test_soll_menge_wird_dedupliziert(): void
    {
        $diff = EmployeeContactListSyncService::computeDiff([5, 5, 5], []);

        $this->assertSame([5], $diff->toAdd);
    }
}
