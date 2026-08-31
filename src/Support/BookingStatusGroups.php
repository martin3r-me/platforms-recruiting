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
    public const KNOWN = ['booked', 'registered', 'confirmed', 'attended', 'cancelled', 'no_show', 'rejected_on_site'];

    /**
     * Rang-Modell (Spec §4): kumulativ, no_show = Rang 2 (Abzweig, keine Stufe 3).
     * rejected_on_site („Vor Ort aussortiert", 31.08.2026) ist mechanisch der
     * Zwilling von no_show: erschienen zaehlt als gebucht, aber es gibt keine
     * Stufe 3 — der eigene Abzweig haelt ihn aus der No-Show-Quote heraus.
     */
    private const RANK = ['booked' => 1, 'registered' => 1, 'confirmed' => 2, 'no_show' => 2, 'rejected_on_site' => 2, 'attended' => 3];

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
