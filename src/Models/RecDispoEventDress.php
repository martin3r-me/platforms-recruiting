<?php

namespace Platform\Recruiting\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Symfony\Component\Uid\UuidV7;

/**
 * Zuordnung Waeschepaket → Veranstaltung, optional je Taetigkeit.
 *
 * taetigkeit === self::ALL ('') heisst: gilt fuer alle, die keine eigene
 * Zeile haben.
 */
class RecDispoEventDress extends Model
{
    /** Sentinel: gilt VA-weit (siehe Migration — NOT NULL wegen Unique-Index). */
    public const ALL = '';

    protected $table = 'rec_dispo_event_dress';

    protected $fillable = ['uuid', 'rec_dispo_event_id', 'taetigkeit', 'rec_dispo_dress_package_id'];

    protected static function booted(): void
    {
        static::creating(function (self $model) {
            if (empty($model->uuid)) {
                $model->uuid = (string) UuidV7::generate();
            }
        });
    }

    public function package(): BelongsTo
    {
        return $this->belongsTo(RecDispoDressPackage::class, 'rec_dispo_dress_package_id');
    }
}
