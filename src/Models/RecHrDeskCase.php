<?php

namespace Platform\Recruiting\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Symfony\Component\Uid\UuidV7;

class RecHrDeskCase extends Model
{
    protected $table = 'rec_hr_desk_cases';

    protected $fillable = [
        'uuid',
        'rec_applicant_id',
        'team_id',
        'reason',
        'status',
        'notes',
        'opened_at',
        'opened_by_user_id',
        'resolved_at',
        'resolved_by_user_id',
        'resolution_notes',
    ];

    protected $casts = [
        'opened_at' => 'datetime',
        'resolved_at' => 'datetime',
    ];

    public const STATUS_OPEN = 'open';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';

    public const REASON_NON_EU_CITIZEN = 'non_eu_citizen';
    public const REASON_NO_GERMAN_KNOWLEDGE = 'no_german_knowledge';
    public const REASON_APPLICANT_CANCELLED_TRAINING = 'applicant_cancelled_training';

    /** Map reason-codes auf sprechende deutsche Labels für UI-Anzeige. */
    public const REASON_LABELS = [
        self::REASON_NON_EU_CITIZEN => 'Nicht-EU-Bürger',
        self::REASON_NO_GERMAN_KNOWLEDGE => 'Keine grundlegenden Deutschkenntnisse',
        self::REASON_APPLICANT_CANCELLED_TRAINING => 'Schulung vom Bewerber abgesagt',
    ];

    public function reasonLabel(): string
    {
        return self::REASON_LABELS[$this->reason] ?? $this->reason;
    }

    protected static function booted(): void
    {
        static::creating(function (self $model) {
            if (empty($model->uuid)) {
                $model->uuid = UuidV7::generate();
            }
        });
    }

    public function applicant(): BelongsTo
    {
        return $this->belongsTo(RecApplicant::class, 'rec_applicant_id');
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(\Platform\Core\Models\Team::class, 'team_id');
    }

    public function openedByUser(): BelongsTo
    {
        return $this->belongsTo(\Platform\Core\Models\User::class, 'opened_by_user_id');
    }

    public function resolvedByUser(): BelongsTo
    {
        return $this->belongsTo(\Platform\Core\Models\User::class, 'resolved_by_user_id');
    }

    public function scopeOpen($query)
    {
        return $query->where('status', self::STATUS_OPEN);
    }

    public function scopeForTeam($query, $teamId)
    {
        return $query->where('team_id', $teamId);
    }

    public function isOpen(): bool
    {
        return $this->status === self::STATUS_OPEN;
    }

    public function isResolved(): bool
    {
        return in_array($this->status, [self::STATUS_APPROVED, self::STATUS_REJECTED]);
    }
}
