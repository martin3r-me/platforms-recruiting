<?php
// src/Models/RecContractSendReservation.php
namespace Platform\Recruiting\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Vormerkung fuer den automatischen Vertragsversand (Spec Versand vormerken §2).
 * Offen = completed_at UND cancelled_at leer. Historie bleibt stehen.
 */
class RecContractSendReservation extends Model
{
    public const SOURCE_NACHBEREITUNG = 'nachbereitung';
    public const SOURCE_HR_DESK = 'hr_desk';

    protected $table = 'rec_contract_send_reservations';
    protected $dateFormat = 'Y-m-d H:i:s';

    protected $fillable = [
        'team_id', 'rec_applicant_id', 'rec_interview_booking_id',
        'vertragsbeginn', 'vertragsende', 'source',
        'reserved_by_user_id', 'reserved_by_name', 'reserved_at',
        'last_reminder_at', 'last_attempt_at', 'last_attempt_result',
        'completed_at', 'cancelled_at', 'cancel_reason', 'claimed_at',
    ];

    protected $casts = [
        'vertragsbeginn' => 'date',
        'vertragsende' => 'date',
        'reserved_at' => 'datetime',
        'last_reminder_at' => 'datetime',
        'last_attempt_at' => 'datetime',
        'completed_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'claimed_at' => 'datetime',
    ];

    public function scopeOffen($query)
    {
        return $query->whereNull('completed_at')->whereNull('cancelled_at');
    }

    public function istOffen(): bool
    {
        return $this->completed_at === null && $this->cancelled_at === null;
    }

    public function applicant(): BelongsTo
    {
        return $this->belongsTo(RecApplicant::class, 'rec_applicant_id');
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(RecInterviewBooking::class, 'rec_interview_booking_id');
    }

    /** Vertragsdaten in der Form, die ContractDispatchService erwartet. */
    public function contractFields(): ?array
    {
        if ($this->vertragsbeginn === null && $this->vertragsende === null) {
            return null;
        }

        return [
            'vertragsbeginn' => $this->vertragsbeginn?->format('Y-m-d'),
            'vertragsende' => $this->vertragsende?->format('Y-m-d'),
        ];
    }
}
