<?php

namespace Platform\Recruiting\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Symfony\Component\Uid\UuidV7;

/**
 * Datei-Anhang pro Mitarbeiter und Veranstaltung (Runde 3, #8). Die Datei liegt
 * auf einer privaten Disk; der MA erreicht sie nur ueber seine Einsatz-Seite
 * (Token) + diese uuid. Datei-Lifecycle (anlegen/ersetzen/loeschen) laeuft
 * ausschliesslich ueber DispoAttachmentStore — das Model hat bewusst keinen
 * Storage-Hook (Integration-Tests ohne Filesystem-Container).
 */
class RecDispoAttachment extends Model
{
    protected $table = 'rec_dispo_attachments';

    protected $fillable = [
        'uuid', 'rec_dispo_event_id', 'rec_employee_id',
        'disk', 'stored_path', 'original_filename', 'mime_type', 'size_bytes',
        'uploaded_by_user_id',
    ];

    protected $casts = [
        'size_bytes' => 'integer',
        'rec_employee_id' => 'integer',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $model) {
            if (empty($model->uuid)) {
                $model->uuid = (string) UuidV7::generate();
            }
        });
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(RecDispoEvent::class, 'rec_dispo_event_id');
    }
}
