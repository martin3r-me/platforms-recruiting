<?php

namespace Platform\Recruiting\Support;

use Illuminate\Support\Collection;

/**
 * Reihenfolge der Buchungen eines Bewerbers fuer die Anzeige: neueste Schulung
 * zuerst, Buchungen ohne Termin ans Ende.
 *
 * Bewusst EIN Callback mit zusammengesetztem Schluessel statt der
 * mehrspaltigen sortBy-Form: die ist mit Closures in Laravel ein stilles
 * No-op, die Liste kaeme unsortiert zurueck ohne jede Fehlermeldung.
 *
 * Gelesen werden nur `->interview?->starts_at` und `->id` — kein Type-Hint auf
 * das Modell, damit die Sortierung isoliert pruefbar bleibt.
 */
final class InterviewBookingOrder
{
    /** Tiefster moeglicher Zeitstempel — Buchungen ohne Termin fallen damit im Desc-Sort ans Ende. */
    private const NO_DATE = '00000000000000';

    /**
     * @param Collection<int, object> $bookings
     * @return Collection<int, object>
     */
    public static function newestFirst(Collection $bookings): Collection
    {
        return $bookings
            ->sortByDesc(static function (object $booking): string {
                $startsAt = $booking->interview?->starts_at;

                // Gleicher Termin → hoehere Buchungs-ID zuerst. Ohne diesen
                // Anteil waere die Reihenfolge bei Doppelbuchungen zufaellig.
                return ($startsAt ? $startsAt->format('YmdHis') : self::NO_DATE)
                    . sprintf('%012d', (int) $booking->id);
            })
            ->values();
    }
}
