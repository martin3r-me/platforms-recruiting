<?php

namespace Platform\Recruiting\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Symfony\Component\Uid\UuidV7;

/**
 * Eine Zustellung je Anstellung (Spec 2026-10-08, §2.2). Alle Zeitstempel
 * (notified_at, first_viewed_at, acknowledged_at, signed_at, withdrawn_at)
 * werden ueber DB::table geschrieben — erster Zeitpunkt gewinnt, und kein
 * Beobachter darf daran haengen.
 */
class RecDocumentRecipient extends Model
{
    protected $table = 'rec_document_recipients';

    protected $fillable = [
        'team_id', 'uuid', 'rec_document_id', 'rec_employee_id', 'person_key',
    ];

    protected $casts = [
        'team_id'         => 'integer',
        'rec_document_id' => 'integer',
        'rec_employee_id' => 'integer',
        'notified_at'     => 'datetime',
        'first_viewed_at' => 'datetime',
        'acknowledged_at' => 'datetime',
        'signed_at'       => 'datetime',
        'withdrawn_at'    => 'datetime',
    ];

    /**
     * Alle Spalten AUSSER signature_data (10–60 KB je Zeile). Listen, Akte,
     * Portal und Zaehler laden nur diese; das Unterschriftsbild liest allein
     * das Nachweisblatt (DokumentHrController::nachweis), geschrieben wird es
     * ueber DB::table in DokumentUnterschrift.
     */
    public const LISTEN_SPALTEN = [
        'id', 'team_id', 'uuid', 'rec_document_id', 'rec_employee_id', 'person_key',
        'notified_at', 'notify_error', 'first_viewed_at', 'acknowledged_at', 'signed_at',
        'withdrawn_at', 'created_at', 'updated_at',
    ];

    /** Scope: Zeilen ohne das Unterschriftsbild — fuer jede Liste. */
    public function scopeOhneUnterschriftsbild($query)
    {
        return $query->select(array_map(static fn (string $c) => 'rec_document_recipients.' . $c, self::LISTEN_SPALTEN));
    }

    protected static function booted(): void
    {
        static::creating(function (self $model) {
            if (empty($model->uuid)) {
                $model->uuid = (string) UuidV7::generate();
            }
        });
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(RecDocument::class, 'rec_document_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(RecEmployee::class, 'rec_employee_id');
    }

    /** Die Zeitstempel als Y-m-d H:i:s-Strings oder null — die Form, die DokumentStatus::fuer() liest. */
    public function zeitstempel(): array
    {
        return [
            'withdrawn_at'    => $this->withdrawn_at?->format('Y-m-d H:i:s'),
            'signed_at'       => $this->signed_at?->format('Y-m-d H:i:s'),
            'acknowledged_at' => $this->acknowledged_at?->format('Y-m-d H:i:s'),
            'first_viewed_at' => $this->first_viewed_at?->format('Y-m-d H:i:s'),
        ];
    }
}
