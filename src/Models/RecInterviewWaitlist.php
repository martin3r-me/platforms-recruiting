<?php

namespace Platform\Recruiting\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Symfony\Component\Uid\UuidV7;

class RecInterviewWaitlist extends Model
{
    use SoftDeletes;

    protected $table = 'rec_interview_waitlist';

    protected $fillable = [
        'uuid',
        'rec_applicant_id',
        'wunschorte',
        'enrolled_at',
        'notified_at',
        'fulfilled_at',
        'cancelled_at',
        'team_id',
        'created_by_user_id',
        'owned_by_user_id',
    ];

    protected $casts = [
        'wunschorte'   => 'array',
        'enrolled_at'  => 'datetime',
        'notified_at'  => 'datetime',
        'fulfilled_at' => 'datetime',
        'cancelled_at' => 'datetime',
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
    }

    public function applicant(): BelongsTo
    {
        return $this->belongsTo(RecApplicant::class, 'rec_applicant_id');
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(\Platform\Core\Models\Team::class, 'team_id');
    }

    public function scopeForTeam($query, $teamId)
    {
        return $query->where('team_id', $teamId);
    }

    /**
     * Offene Warteliste-Einträge: weder gebucht noch storniert.
     */
    public function scopeOpen($query)
    {
        return $query->whereNull('fulfilled_at')->whereNull('cancelled_at');
    }
}
