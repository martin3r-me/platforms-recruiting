<?php

namespace Platform\Recruiting\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Symfony\Component\Uid\UuidV7;

/**
 * Ein bereitgestelltes Dokument (Spec 2026-10-08, §2.1). Datei-Lifecycle
 * laeuft ausschliesslich ueber DokumentSpeicher/DokumentService — das Model
 * hat bewusst keinen Storage-Hook (Integrationstests ohne Filesystem).
 * Zeitstempel der Zustellungen schreibt der Query Builder, nie dieses Model.
 */
class RecDocument extends Model
{
    use SoftDeletes;

    protected $table = 'rec_documents';

    protected $fillable = [
        'team_id', 'uuid', 'title', 'category', 'action',
        'disk', 'stored_path', 'original_filename', 'file_sha256', 'file_size',
        'rec_dispo_event_id', 'created_by_user_id',
    ];

    protected $casts = [
        'team_id'            => 'integer',
        'file_size'          => 'integer',
        'rec_dispo_event_id' => 'integer',
        'created_by_user_id' => 'integer',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $model) {
            if (empty($model->uuid)) {
                $model->uuid = (string) UuidV7::generate();
            }
        });
    }

    public function recipients(): HasMany
    {
        return $this->hasMany(RecDocumentRecipient::class, 'rec_document_id');
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(RecDispoEvent::class, 'rec_dispo_event_id');
    }
}
