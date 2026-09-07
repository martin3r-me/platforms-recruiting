<?php

namespace Platform\Recruiting\Support;

/**
 * Parser fuer den confirmed_at-Backfill: der historische Log-Eintrag
 * booking_confirmed_by_reply (ReminderResponseHandler) traegt die Buchungs-ID
 * nur im Summary-Text ("... Booking #674 → confirmed."), nicht als
 * strukturiertes Feld. Geparst wird ausschliesslich der "Booking #<id>"-Marker
 * — fail-closed, eine beliebige Raute im Text zaehlt nicht.
 */
final class BookingConfirmationBackfill
{
    public static function bookingIdFromSummary(?string $summary): ?int
    {
        if ($summary !== null && preg_match('/Booking #(\\d+)/', $summary, $m) === 1) {
            return (int) $m[1];
        }

        return null;
    }
}
