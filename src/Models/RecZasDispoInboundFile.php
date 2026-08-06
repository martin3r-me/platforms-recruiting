<?php

namespace Platform\Recruiting\Models;

use Illuminate\Database\Eloquent\Model;
use Symfony\Component\Uid\UuidV7;

/**
 * Eine von ZAS eingegangene Dispo-Datei (POST /recruiting/zas/dispo-inbound).
 *
 * Haelt nur Metadaten + erkannte Struktur — die Rohdatei selbst liegt auf
 * dem Storage-Disk unter `disk`/`stored_path`. Siehe ZasDispoInboundController
 * und die Migration create_rec_zas_dispo_inbound_files_table fuer Details.
 *
 * Phase 1: nur annehmen + wegspeichern + sichten. Verarbeitung folgt, sobald
 * klar ist welche Spalten ZAS tatsaechlich liefert.
 */
class RecZasDispoInboundFile extends Model
{
    protected $table = 'rec_zas_dispo_inbound_files';

    protected $fillable = [
        'uuid',
        'source',
        'original_filename',
        'disk',
        'stored_path',
        'mime_type',
        'size_bytes',
        'detected_format',
        'delimiter',
        'header_columns',
        'row_count',
        'is_test',
        'parse_status',
        'notes',
        'received_ip',
    ];

    protected $casts = [
        'header_columns' => 'array',
        'size_bytes'     => 'integer',
        'row_count'      => 'integer',
        'is_test'        => 'boolean',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $model) {
            if (empty($model->uuid)) {
                $model->uuid = (string) UuidV7::generate();
            }
        });
    }
}
