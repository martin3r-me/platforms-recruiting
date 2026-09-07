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

    /**
     * Explizit statt aus der Connection-Grammar geraten: das MySQL-Format ist
     * ohnehin dieses, aber so laesst sich das Model auch OHNE Datenbank
     * instanziieren (der confirmed_at-Stempel-Test liest den datetime-Cast,
     * und getDateFormat() wuerde sonst eine Connection aufloesen).
     */
    protected $dateFormat = 'Y-m-d H:i:s';

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
        'confirmed_at',
        'created_by_user_id',
        'owned_by_user_id',
    ];

    protected $casts = [
        'booked_at' => 'datetime',
        'reminder_sent_at' => 'datetime',
        'seat_released_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'confirmed_at' => 'datetime',
        'is_active' => 'boolean',
    ];

    /**
     * confirmed_at-Stempel: gesetzt beim Wechsel AUF 'confirmed', danach nie
     * wieder angefasst — der Status wird nach der Schulung mit attended/no_show
     * ueberschrieben, und genau an diesem Verlust ist die alte
     * „Bestaetigt"-Statistikspalte gestorben (Befund 25.08.2026).
     *
     * Als MUTATOR statt saving-Hook: greift auch ohne Event-Dispatcher
     * (Integration-Suite, unsetEventDispatcher) und deckt jeden Schreibweg ab
     * (Reminder-"Ja", HR-Dropdown, MCP-Tool, Phasen-Hook), ohne dass einer
     * davon angefasst wird. Query-Builder-Massenupdates umgeht er — die gibt es
     * fuers Setzen von 'confirmed' nicht (einziger Bulk-Update-Pfad setzt
     * 'cancelled', Applicant\Show::cancelBookings).
     */
    public function setStatusAttribute(?string $value): void
    {
        if ($value === 'confirmed'
            && ($this->attributes['status'] ?? null) !== 'confirmed'
            && empty($this->attributes['confirmed_at'])) {
            $this->attributes['confirmed_at'] = \Illuminate\Support\Carbon::now()->format('Y-m-d H:i:s');
        }

        $this->attributes['status'] = $value;
    }

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
            'rejected_on_site' => 'Vor Ort aussortiert',
            default      => (string) $this->status,
        };
    }
}
