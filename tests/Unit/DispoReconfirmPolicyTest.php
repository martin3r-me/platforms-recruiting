<?php

namespace Platform\Recruiting\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Services\Zas\Dispo\DispoReconfirmPolicy as P;

class DispoReconfirmPolicyTest extends TestCase
{
    private array $snap = ['datum' => '2026-09-02', 'von' => '10:00', 'bis' => '18:00'];

    public function test_same_times_need_nothing(): void
    {
        $this->assertFalse(P::needsReconfirm($this->snap, $this->snap, '2026-08-28'));
        $this->assertFalse(P::needsReconfirm($this->snap, ['datum' => '2026-09-02', 'von' => '10:00 ', 'bis' => '18:00'], '2026-08-28'), 'Whitespace zaehlt nicht');
    }

    public function test_changed_von_bis_or_datum_needs_reconfirm(): void
    {
        $this->assertTrue(P::needsReconfirm($this->snap, ['datum' => '2026-09-02', 'von' => '11:00', 'bis' => '18:00'], '2026-08-28'));
        $this->assertTrue(P::needsReconfirm($this->snap, ['datum' => '2026-09-02', 'von' => '10:00', 'bis' => '19:00'], '2026-08-28'));
        $this->assertTrue(P::needsReconfirm($this->snap, ['datum' => '2026-09-03', 'von' => '10:00', 'bis' => '18:00'], '2026-08-28'));
    }

    public function test_past_changes_are_ignored(): void
    {
        $old = ['datum' => '2026-08-20', 'von' => '10:00', 'bis' => '18:00'];
        $this->assertFalse(P::needsReconfirm($old, ['datum' => '2026-08-21', 'von' => '10:00', 'bis' => '18:00'], '2026-08-28'));
        $this->assertTrue(P::needsReconfirm($old, ['datum' => '2026-08-30', 'von' => '10:00', 'bis' => '18:00'], '2026-08-28'), 'verschoben in die Zukunft zaehlt');
        $this->assertTrue(P::needsReconfirm($this->snap, ['datum' => '2026-08-20', 'von' => '10:00', 'bis' => '18:00'], '2026-08-28'), 'aus der Zukunft in die Vergangenheit zaehlt (alter Tag war noch offen)');
    }

    public function test_without_snapshot_nothing_is_compared(): void
    {
        $this->assertFalse(P::needsReconfirm(['datum' => null, 'von' => null, 'bis' => null], $this->snap, '2026-08-28'));
    }
}
