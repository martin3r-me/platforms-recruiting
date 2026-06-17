<?php

namespace Platform\Recruiting\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Symfony\Component\Uid\UuidV7;

/**
 * Eine von ZAS eingegangene CSV-Datei (POST /recruiting/zas/inbound).
 *
 * Haelt nur Metadaten + erkannte Struktur — die Rohdatei selbst liegt auf
 * dem Storage-Disk unter `disk`/`stored_path`. Siehe ZasInboundController
 * und die Migration create_rec_zas_inbound_files_table fuer Details.
 *
 * Phase 1: nur annehmen + wegspeichern. Verarbeitung (Spalten-Mapping) folgt,
 * sobald klar ist welche Spalten ZAS tatsaechlich liefert.
 */
class RecZasInboundFile extends Model
{
    protected $table = 'rec_zas_inbound_files';

    protected $fillable = [
        'uuid',
        'original_filename',
        'disk',
        'stored_path',
        'mime_type',
        'size_bytes',
        'delimiter',
        'header_columns',
        'row_count',
        'is_test',
        'status',
        'processed_at',
        'notes',
        'received_ip',
    ];

    protected $casts = [
        'header_columns' => 'array',
        'size_bytes'     => 'integer',
        'row_count'      => 'integer',
        'is_test'        => 'boolean',
        'processed_at'   => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $model) {
            if (empty($model->uuid)) {
                $model->uuid = (string) UuidV7::generate();
            }
        });
    }

    public function employees(): HasMany
    {
        return $this->hasMany(RecEmployee::class, 'rec_zas_inbound_file_id');
    }
}
