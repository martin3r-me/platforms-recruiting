<?php

namespace Platform\Recruiting\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Support\BookingCancellationMeta;

/**
 * Storno-Metadaten bei Statuswechseln: EINE Regel fuer UI-Pfad
 * (InterviewBookings\Index::updateStatus) und MCP-Tool
 * (UpdateInterviewBookingTool). Vorher stand sie nur im UI — eine per MCP
 * reaktivierte Buchung (cancelled -> rejected_on_site bei der Nachpflege
 * 31.08.2026) haette ihr cancelled_at fuer immer behalten.
 *
 * Rueckgabe sind die zu MERGENDEN Updates: wer den Status nicht wechselt
 * oder cancelled -> cancelled setzt, bekommt ein leeres Array — bestehende
 * Metadaten (wer wann stornierte) werden nie ueberstempelt.
 */
final class BookingCancellationMetaTest extends TestCase
{
    public function test_wechsel_auf_cancelled_stempelt_hr(): void
    {
        $updates = BookingCancellationMeta::updatesFor('booked', 'cancelled', '2026-08-31 10:00:00');
        $this->assertSame('hr', $updates['cancelled_by']);
        $this->assertSame('2026-08-31 10:00:00', $updates['cancelled_at']);
    }

    public function test_wechsel_weg_von_cancelled_raeumt_ab(): void
    {
        $this->assertSame(
            ['cancelled_by' => null, 'cancelled_at' => null],
            BookingCancellationMeta::updatesFor('cancelled', 'rejected_on_site', '2026-08-31 10:00:00'),
        );
    }

    public function test_cancelled_zu_cancelled_ueberstempelt_nichts(): void
    {
        // Der Bewerber hat selbst storniert (cancelled_by='applicant') — ein
        // erneutes Setzen desselben Status darf daraus kein 'hr' machen.
        $this->assertSame([], BookingCancellationMeta::updatesFor('cancelled', 'cancelled', '2026-08-31 10:00:00'));
    }

    public function test_wechsel_zwischen_aktiven_status_laesst_metadaten_in_ruhe(): void
    {
        $this->assertSame([], BookingCancellationMeta::updatesFor('booked', 'attended', '2026-08-31 10:00:00'));
    }
}
