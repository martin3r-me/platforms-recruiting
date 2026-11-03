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
 * Verarbeitung: siehe ZasDispoWebexportImporter (parse_status: viewable|unparseable|processed|failed).
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
        'processed_at',
    ];

    protected $casts = [
        'header_columns' => 'array',
        'size_bytes'     => 'integer',
        'row_count'      => 'integer',
        'is_test'        => 'boolean',
        'processed_at'   => 'datetime',
    ];

    /** @return array<string,mixed> Zusammenfassung des Importers (notes-JSON), leer wenn unverarbeitet. */
    public function notizen(): array
    {
        $n = json_decode((string) $this->notes, true);

        return is_array($n) ? $n : [];
    }

    /**
     * Einbuchungen ({Dispo}-Zeilen) dieser Lieferung; null = keine Dispo-Daten
     * (unverarbeitet, gescheitert oder nur fremde Bloecke wie {Zeiten} — dort
     * schreibt der Importer keine `stats`).
     */
    public function einbuchungen(): ?int
    {
        if ($this->parse_status !== 'processed') {
            return null;
        }
        $anzahl = (int) ($this->notizen()['stats']['assignments'] ?? 0);

        return $anzahl > 0 ? $anzahl : null;
    }

    /**
     * Echt-Datei, die der Hintergrund-Job noch verarbeiten wird: ohne Abschluss
     * und neuer als die letzte verarbeitete (ProcessZasDispoInboundFile::letzteVerarbeitete()).
     */
    public function wartet(int $letzteVerarbeitete): bool
    {
        return $this->ohneAbschluss() && (int) $this->id > $letzteVerarbeitete;
    }

    /** Echt-Datei ohne Abschluss, aber aelter als der Bestand — wird nie automatisch nachgeholt. */
    public function liegengeblieben(int $letzteVerarbeitete): bool
    {
        return $this->ohneAbschluss() && (int) $this->id <= $letzteVerarbeitete;
    }

    private function ohneAbschluss(): bool
    {
        return !$this->is_test && $this->processed_at === null && $this->parse_status === 'viewable';
    }

    protected static function booted(): void
    {
        static::creating(function (self $model) {
            if (empty($model->uuid)) {
                $model->uuid = (string) UuidV7::generate();
            }
        });
    }
}
