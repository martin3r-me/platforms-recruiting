<?php

namespace Platform\Recruiting\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Symfony\Component\Uid\UuidV7;

class RecPostingExternalRef extends Model
{
    protected $table = 'rec_posting_external_refs';

    protected $fillable = [
        'uuid', 'rec_posting_id', 'rec_source_platform_id', 'external_ref', 'team_id',
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

    public function posting(): BelongsTo
    {
        return $this->belongsTo(RecPosting::class, 'rec_posting_id');
    }

    public function sourcePlatform(): BelongsTo
    {
        return $this->belongsTo(RecSourcePlatform::class, 'rec_source_platform_id');
    }
}
