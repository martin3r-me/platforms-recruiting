<?php

namespace Platform\Recruiting\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Symfony\Component\Uid\UuidV7;

class RecIntakeChannel extends Model
{
    protected $table = 'rec_intake_channels';

    protected $fillable = [
        'uuid', 'comms_channel_id', 'team_id', 'default_posting_id', 'is_active',
    ];

    protected $casts = [
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
    }

    public function channel(): BelongsTo
    {
        return $this->belongsTo(\Platform\Crm\Models\CommsChannel::class, 'comms_channel_id');
    }

    public function defaultPosting(): BelongsTo
    {
        return $this->belongsTo(RecPosting::class, 'default_posting_id');
    }

    public static function isIntake(int $commsChannelId, int $teamId): bool
    {
        return static::where('comms_channel_id', $commsChannelId)
            ->where('team_id', $teamId)
            ->where('is_active', true)
            ->exists();
    }
}
