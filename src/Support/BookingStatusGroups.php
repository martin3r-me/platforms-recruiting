<?php

namespace Platform\Recruiting\Support;

/**
 * Status-Gruppierung der Statistik-Seite (Spec §4). Referenziert
 * SeatStandbyPolicy als einzige Wahrheit fuers Platz-Freigeben.
 * OFFENE VERZWEIGUNG (Auftrag ②): KNOWN spiegelt die heute dokumentierte
 * Werteliste aus den zwei $validStatuses-Duplikaten; sobald Auftrag ② die
 * zentrale Konstante liefert, referenziert KNOWN diese.
 */
final class BookingStatusGroups
{
    public const KNOWN = ['booked', 'registered', 'confirmed', 'attended', 'cancelled', 'no_show'];

    /** Rang-Modell (Spec §4): kumulativ, no_show = Rang 2 (Abzweig, keine Stufe 3) */
    private const RANK = ['booked' => 1, 'registered' => 1, 'confirmed' => 2, 'no_show' => 2, 'attended' => 3];

    public static function isKnown(?string $status): bool
    {
        return in_array($status, self::KNOWN, true);
    }

    public static function isCohortAssigned(?string $status): bool
    {
        return self::isKnown($status)
            && !in_array($status, SeatStandbyPolicy::SEAT_FREEING_STATUSES, true);
    }

    public static function isUnknownActive(?string $status): bool
    {
        return !self::isKnown($status)
            && !in_array($status, SeatStandbyPolicy::SEAT_FREEING_STATUSES, true);
    }

    public static function rank(?string $status): ?int
    {
        return self::RANK[$status] ?? null;
    }
}
