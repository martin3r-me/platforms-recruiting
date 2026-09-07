<?php

namespace Platform\Recruiting\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Support\BookingConfirmationBackfill;

/**
 * Backfill-Parser fuer historische Bestaetigungen: der Log-Eintrag
 * booking_confirmed_by_reply traegt die Buchungs-ID NUR im Summary-Text
 * ("... Booking #674 → confirmed."), nicht als strukturiertes Feld —
 * der Backfill muss sie herausparsen. Fail-closed: was nicht eindeutig
 * ist, wird nicht gestempelt.
 */
final class BookingConfirmationBackfillTest extends TestCase
{
    public function test_parst_die_buchungs_id_aus_dem_summary(): void
    {
        $this->assertSame(674, BookingConfirmationBackfill::bookingIdFromSummary(
            'Bewerber hat Schulungs-Reminder mit "Ja" bestaetigt — Booking #674 → confirmed.'
        ));
    }

    public function test_ohne_booking_marker_kein_treffer(): void
    {
        $this->assertNull(BookingConfirmationBackfill::bookingIdFromSummary('Erinnerung 2/2 per whatsapp gesendet.'));
        $this->assertNull(BookingConfirmationBackfill::bookingIdFromSummary(''));
    }

    public function test_nur_der_booking_marker_zaehlt_nicht_jede_raute(): void
    {
        // "#5" in freiem Text (z. B. ein Terminname) darf nicht als Buchung
        // gelesen werden — geparst wird ausschliesslich "Booking #<id>".
        $this->assertNull(BookingConfirmationBackfill::bookingIdFromSummary('Reminder fuer Termin #5 gesendet.'));
    }
}
