<?php

namespace Platform\Recruiting\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Support\SeatStandbyPolicy;

final class SeatStandbyPolicyTest extends TestCase
{
    public function test_counts_as_seat_status_matrix(): void
    {
        foreach (['booked', 'registered', 'confirmed', 'attended', 'no_show'] as $status) {
            $this->assertTrue(SeatStandbyPolicy::countsAsSeat($status, false), "{$status} ohne Release muss zählen");
        }
        $this->assertFalse(SeatStandbyPolicy::countsAsSeat('cancelled', false));
        $this->assertFalse(SeatStandbyPolicy::countsAsSeat('cancelled', true));
        // Standby (booked + released) zählt NICHT
        $this->assertFalse(SeatStandbyPolicy::countsAsSeat('booked', true));
        // Invariante wird anderswo erzwungen, aber die Zählung bleibt defensiv:
        // released + Nicht-booked zählt ebenfalls nicht.
        $this->assertFalse(SeatStandbyPolicy::countsAsSeat('registered', true));
    }

    public function test_should_release_nur_fuer_booked_ohne_bestehendes_release(): void
    {
        $this->assertTrue(SeatStandbyPolicy::shouldRelease('booked', false));
        $this->assertFalse(SeatStandbyPolicy::shouldRelease('booked', true));
        foreach (['registered', 'confirmed', 'attended', 'cancelled', 'no_show', null] as $status) {
            $this->assertFalse(SeatStandbyPolicy::shouldRelease($status, false), var_export($status, true));
        }
    }

    public function test_reclaim_outcome(): void
    {
        // Kein Standby → normales Upgrade, keine Kapazitätsfrage
        $this->assertSame(SeatStandbyPolicy::RECLAIM_UPGRADE, SeatStandbyPolicy::reclaimOutcome(false, 99, 10, true));
        // Standby + Platz frei + Zukunft → Re-Claim
        $this->assertSame(SeatStandbyPolicy::RECLAIM_OK, SeatStandbyPolicy::reclaimOutcome(true, 9, 10, true));
        // Standby + unbegrenzte Kapazität → Re-Claim
        $this->assertSame(SeatStandbyPolicy::RECLAIM_OK, SeatStandbyPolicy::reclaimOutcome(true, 999, null, true));
        // Standby + voll → fehlgeschlagen
        $this->assertSame(SeatStandbyPolicy::RECLAIM_FAILED, SeatStandbyPolicy::reclaimOutcome(true, 10, 10, true));
        // Standby + Termin vergangen → fehlgeschlagen (auch wenn "frei")
        $this->assertSame(SeatStandbyPolicy::RECLAIM_FAILED, SeatStandbyPolicy::reclaimOutcome(true, 0, 10, false));
    }

    public function test_must_clear_release_marker(): void
    {
        $this->assertFalse(SeatStandbyPolicy::mustClearReleaseMarker('booked'));
        foreach (['registered', 'confirmed', 'attended', 'cancelled', 'no_show'] as $status) {
            $this->assertTrue(SeatStandbyPolicy::mustClearReleaseMarker($status), $status);
        }
    }

    public function test_status_label(): void
    {
        $this->assertSame('Standby', SeatStandbyPolicy::statusLabel('booked', true));
        $this->assertNull(SeatStandbyPolicy::statusLabel('booked', false));
        $this->assertNull(SeatStandbyPolicy::statusLabel('registered', true));
    }
}
