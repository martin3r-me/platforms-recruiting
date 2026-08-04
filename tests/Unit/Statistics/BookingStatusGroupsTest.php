<?php

namespace Platform\Recruiting\Tests\Unit\Statistics;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Support\BookingStatusGroups;

class BookingStatusGroupsTest extends TestCase
{
    public function test_cancelled_ist_nie_kohorten_zugeordnet(): void
    {
        $this->assertFalse(BookingStatusGroups::isCohortAssigned('cancelled'));
    }

    public function test_bekannte_aktive_status_sind_kohorten_zugeordnet(): void
    {
        foreach (['booked', 'registered', 'confirmed', 'attended', 'no_show'] as $s) {
            $this->assertTrue(BookingStatusGroups::isCohortAssigned($s), $s);
        }
    }

    public function test_unbekannter_status_ist_nicht_zugeordnet_sondern_unknown_active(): void
    {
        // Spec §4: unbekannte Werte duerfen NICHT still in die Schulungszeilen
        $this->assertFalse(BookingStatusGroups::isCohortAssigned('weird_value'));
        $this->assertTrue(BookingStatusGroups::isUnknownActive('weird_value'));
        $this->assertFalse(BookingStatusGroups::isUnknownActive('cancelled'), 'freigebend != unknown');
    }

    public function test_rang_modell_kumulativ_no_show_ist_rang_2(): void
    {
        $this->assertSame(1, BookingStatusGroups::rank('booked'));
        $this->assertSame(1, BookingStatusGroups::rank('registered'));
        $this->assertSame(2, BookingStatusGroups::rank('confirmed'));
        $this->assertSame(2, BookingStatusGroups::rank('no_show'), 'Abzweig, keine Stufe 3');
        $this->assertSame(3, BookingStatusGroups::rank('attended'));
        $this->assertNull(BookingStatusGroups::rank('cancelled'));
        $this->assertNull(BookingStatusGroups::rank('weird_value'));
    }
}
