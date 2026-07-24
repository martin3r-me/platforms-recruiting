<?php

namespace Platform\Recruiting\Support;

/**
 * Standby-Modell für Schulungsplätze: Eine Buchung belegt einen Platz,
 * solange der Bewerber aktiv dran ist. Gibt der Auto-Pilot auf
 * (max_reminders_reached) und die Buchung steht noch auf 'booked', wird
 * seat_released_at gesetzt — die Buchung bleibt bestehen ("Standby"),
 * zählt aber nicht mehr gegen max_participants.
 *
 * Pure Entscheidungslogik ohne Framework — testbar per reinem PHPUnit
 * (Muster FirstAiderDateGuard). Das Eloquent-Wiring (Scope, saving-Guard,
 * Labels) delegiert hierher.
 */
final class SeatStandbyPolicy
{
    /** @var list<string> Status, die nie einen Platz belegen. */
    public const SEAT_FREEING_STATUSES = ['cancelled'];

    public const RECLAIM_UPGRADE = 'upgrade'; // kein Standby — normales Upgrade
    public const RECLAIM_OK      = 'reclaim'; // Standby, Platz noch frei
    public const RECLAIM_FAILED  = 'failed';  // Standby, Termin voll oder vergangen

    public static function countsAsSeat(?string $status, bool $seatReleased): bool
    {
        return !in_array($status, self::SEAT_FREEING_STATUSES, true) && !$seatReleased;
    }

    public static function shouldRelease(?string $status, bool $seatReleased): bool
    {
        return $status === 'booked' && !$seatReleased;
    }

    public static function reclaimOutcome(bool $seatReleased, int $takenSeats, ?int $maxParticipants, bool $startsInFuture): string
    {
        if (!$seatReleased) {
            return self::RECLAIM_UPGRADE;
        }
        if (!$startsInFuture) {
            return self::RECLAIM_FAILED;
        }
        if ($maxParticipants !== null && $takenSeats >= $maxParticipants) {
            return self::RECLAIM_FAILED;
        }
        return self::RECLAIM_OK;
    }

    /**
     * Invariante: seat_released_at existiert nur auf status='booked'.
     * Wird als saving-Guard im Model erzwungen.
     */
    public static function mustClearReleaseMarker(?string $status): bool
    {
        return $status !== 'booked';
    }

    public static function statusLabel(?string $status, bool $seatReleased): ?string
    {
        return ($status === 'booked' && $seatReleased) ? 'Standby' : null;
    }
}
