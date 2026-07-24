<?php

namespace Platform\Recruiting\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Platform\Recruiting\Support\SeatStandbyPolicy;
use Symfony\Component\Uid\UuidV7;

class RecInterviewBooking extends Model
{
    use SoftDeletes;

    protected $table = 'rec_interview_bookings';

    protected $fillable = [
        'uuid',
        'rec_interview_id',
        'rec_applicant_id',
        'status',
        'notes',
        'booked_at',
        'is_active',
        'team_id',
        'reminder_sent_at',
        'seat_released_at',
        'cancelled_by',
        'cancelled_at',
        'created_by_user_id',
        'owned_by_user_id',
    ];

    protected $casts = [
        'booked_at' => 'datetime',
        'reminder_sent_at' => 'datetime',
        'seat_released_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'is_active' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $model) {
            if (empty($model->uuid)) {
                do {
                    $uuid = UuidV7::generate();
                } while (self::where('uuid', $uuid)->exists());
                $model->uuid = $uuid;
            }
        });

        // Invariante: seat_released_at existiert nur auf status='booked'.
        // Jeder Statuswechsel weg von 'booked' (Upgrade, Storno, HR-Set)
        // raeumt den Marker automatisch ab — egal ueber welchen Pfad.
        static::saving(function (self $model) {
            if (SeatStandbyPolicy::mustClearReleaseMarker($model->status)) {
                $model->seat_released_at = null;
            }
        });
    }

    public function interview(): BelongsTo
    {
        return $this->belongsTo(RecInterview::class, 'rec_interview_id');
    }

    public function applicant(): BelongsTo
    {
        return $this->belongsTo(RecApplicant::class, 'rec_applicant_id');
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(\Platform\Core\Models\Team::class, 'team_id');
    }

    /**
     * Platz-belegende Buchungen: nicht storniert UND kein Standby.
     * DIE zentrale Zaehlregel — alle Kapazitaets-Checks laufen hierueber.
     */
    public function scopeSeatTaking($query)
    {
        return $query
            ->whereNotIn('status', SeatStandbyPolicy::SEAT_FREEING_STATUSES)
            ->whereNull('seat_released_at');
    }

    public function getTakesSeatAttribute(): bool
    {
        return SeatStandbyPolicy::countsAsSeat($this->status, $this->seat_released_at !== null);
    }

    public function getIsStandbyAttribute(): bool
    {
        return SeatStandbyPolicy::statusLabel($this->status, $this->seat_released_at !== null) !== null;
    }

    /**
     * Wahr wenn diese Buchung "abgesagt" wurde, der Bewerber aber spaeter
     * eine andere (nicht-cancelled) Buchung gemacht hat — er hat also
     * umgebucht statt komplett abzusagen. Wird im UI verwendet um
     * "Umgebucht" vs. "Abgesagt" sauber zu unterscheiden.
     */
    public function getIsRebookedAttribute(): bool
    {
        if ($this->status !== 'cancelled') {
            return false;
        }
        return self::query()
            ->where('rec_applicant_id', $this->rec_applicant_id)
            ->where('id', '>', $this->id)
            ->whereNotIn('status', ['cancelled'])
            ->exists();
    }

    /**
     * UI-Label fuer den effektiven Status. Mappt 'cancelled' auf
     * 'Umgebucht' wenn eine spaetere aktive Buchung existiert.
     */
    public function getStatusLabelAttribute(): string
    {
        if ($label = SeatStandbyPolicy::statusLabel($this->status, $this->seat_released_at !== null)) {
            return $label;
        }
        if ($this->is_rebooked) {
            return 'Umgebucht';
        }
        return match ($this->status) {
            'booked'     => 'Gebucht',
            'registered' => 'Registriert',
            'confirmed'  => 'Bestätigt',
            'attended'   => 'Teilgenommen',
            'cancelled'  => 'Abgesagt',
            'no_show'    => 'Nicht erschienen',
            default      => (string) $this->status,
        };
    }
}
