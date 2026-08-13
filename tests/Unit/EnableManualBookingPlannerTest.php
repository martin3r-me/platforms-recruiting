<?php

namespace Platform\Recruiting\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Support\ManualBookingBackfillPlanner;

/**
 * Auswahl-Logik des Backfills, ohne DB: ab welcher Ordnungszahl, was ist schon
 * gesetzt, was ist inaktiv. Der Command selbst tut danach nur noch ein Update.
 */
final class EnableManualBookingPlannerTest extends TestCase
{
    /** @return array{id:int,order:int,is_active:bool,allow_manual_booking:bool} */
    private function phase(int $id, int $order, bool $active = true, bool $flag = false): array
    {
        return ['id' => $id, 'order' => $order, 'is_active' => $active, 'allow_manual_booking' => $flag];
    }

    public function test_waehlt_phasen_ab_der_grenze(): void
    {
        $phasen = [
            $this->phase(1, 1),
            $this->phase(2, 2),
            $this->phase(3, 3),
            $this->phase(4, 4),
        ];

        $this->assertSame([2, 3, 4], ManualBookingBackfillPlanner::selectPhaseIds($phasen, 2));
    }

    public function test_ueberspringt_bereits_gesetzte(): void
    {
        $phasen = [$this->phase(2, 2, true, true), $this->phase(3, 3)];

        $this->assertSame([3], ManualBookingBackfillPlanner::selectPhaseIds($phasen, 2));
    }

    public function test_ueberspringt_inaktive_phasen(): void
    {
        $phasen = [$this->phase(2, 2), $this->phase(5, 5, false)];

        $this->assertSame([2], ManualBookingBackfillPlanner::selectPhaseIds($phasen, 2));
    }

    public function test_grenze_kann_hoeher_liegen(): void
    {
        $phasen = [$this->phase(2, 2), $this->phase(3, 3), $this->phase(4, 4)];

        $this->assertSame([3, 4], ManualBookingBackfillPlanner::selectPhaseIds($phasen, 3));
    }

    public function test_leere_liste_bleibt_leer(): void
    {
        $this->assertSame([], ManualBookingBackfillPlanner::selectPhaseIds([], 2));
    }
}
