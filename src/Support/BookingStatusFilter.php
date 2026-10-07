<?php

namespace Platform\Recruiting\Support;

use Illuminate\Database\Eloquent\Builder;

/**
 * Status-Filter der Teilnehmerliste (Uebersicht und Nachbereitung).
 *
 *  - 'cancelled' = echte Stornierung (keine spaetere aktive Buchung beim Bewerber)
 *  - 'rebooked'  = umgebucht (cancelled + spaetere aktive Buchung)
 *  - 'standby'   = „Keine Reaktion": gebucht, Platz aber wieder freigegeben
 *                  (06.10.2026, Kundenwunsch — die Leute, denen man nachtelefoniert)
 *  - 'booked'    = gebucht MIT Platz; „Keine Reaktion" hat seinen eigenen Eintrag
 *  - sonst       = direkter Status-Match
 *
 * Standby-Regel wie SeatStandbyPolicy::statusLabel(): status booked + seat_released_at.
 */
final class BookingStatusFilter
{
    public const OPTIONS = [
        ['value' => 'all', 'label' => 'Alle Status'],
        ['value' => 'booked', 'label' => 'Gebucht'],
        ['value' => 'standby', 'label' => 'Keine Reaktion'],
        ['value' => 'registered', 'label' => 'Registriert'],
        ['value' => 'confirmed', 'label' => 'Bestätigt'],
        ['value' => 'attended', 'label' => 'Teilgenommen'],
        ['value' => 'cancelled', 'label' => 'Abgesagt'],
        ['value' => 'rebooked', 'label' => 'Umgebucht'],
        ['value' => 'no_show', 'label' => 'Nicht erschienen'],
        ['value' => 'rejected_on_site', 'label' => 'Vor Ort aussortiert'],
    ];

    public static function apply(Builder $query, ?string $filter): Builder
    {
        $filter = $filter ?: 'all';
        $table = $query->getModel()->getTable();

        $spaetereAktive = function ($sub) use ($table) {
            $sub->selectRaw('1')
                ->from($table . ' as later')
                ->whereColumn('later.rec_applicant_id', $table . '.rec_applicant_id')
                ->whereColumn('later.id', '>', $table . '.id')
                ->whereNotIn('later.status', ['cancelled']);
        };

        return match ($filter) {
            'all'       => $query,
            'cancelled' => $query->where($table . '.status', 'cancelled')->whereNotExists($spaetereAktive),
            'rebooked'  => $query->where($table . '.status', 'cancelled')->whereExists($spaetereAktive),
            'standby'   => $query->where($table . '.status', 'booked')->whereNotNull($table . '.seat_released_at'),
            'booked'    => $query->where($table . '.status', 'booked')->whereNull($table . '.seat_released_at'),
            default     => $query->where($table . '.status', $filter),
        };
    }
}
