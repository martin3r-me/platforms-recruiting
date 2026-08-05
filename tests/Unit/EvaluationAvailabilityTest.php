<?php

namespace Platform\Recruiting\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Support\BookingStatusGroups;
use Platform\Recruiting\Support\EvaluationAvailability;

class EvaluationAvailabilityTest extends TestCase
{
    public function test_nur_attended_ist_offen(): void
    {
        $this->assertTrue(EvaluationAvailability::isOpen('attended'));
    }

    public function test_alle_anderen_bekannten_status_sind_gesperrt(): void
    {
        foreach (BookingStatusGroups::KNOWN as $status) {
            if ($status === 'attended') {
                continue;
            }
            $this->assertFalse(
                EvaluationAvailability::isOpen($status),
                "Status {$status} darf die Bewertung nicht freischalten.",
            );
        }
    }

    public function test_die_statusliste_deckt_alle_erwarteten_werte_ab(): void
    {
        // Schuetzt gegen stille Erweiterung der Statusliste ohne Entscheidung,
        // wie der neue Status zur Bewertung steht.
        $this->assertSame(
            ['booked', 'registered', 'confirmed', 'attended', 'cancelled', 'no_show'],
            BookingStatusGroups::KNOWN,
        );
    }

    public function test_null_und_unbekannt_sind_gesperrt(): void
    {
        $this->assertFalse(EvaluationAvailability::isOpen(null));
        $this->assertFalse(EvaluationAvailability::isOpen(''));
        $this->assertFalse(EvaluationAvailability::isOpen('Attended'));
        $this->assertFalse(EvaluationAvailability::isOpen('irgendwas'));
    }
}
