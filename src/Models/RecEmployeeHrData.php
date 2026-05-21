<?php

namespace Platform\Recruiting\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Symfony\Component\Uid\UuidV7;

/**
 * HR-only-Daten zu einem RecEmployee (1:1 Relation). Diese Tabelle
 * ist nur fuer's HR-Backend gedacht — MA-Portal hat keinen Zugriff.
 *
 * Felder werden iterativ vom User ergaenzt. Migrations: jeweils
 * ALTER TABLE rec_employee_hr_data ADD COLUMN ... fuer neue Felder.
 */
class RecEmployeeHrData extends Model
{
    protected $table = 'rec_employee_hr_data';

    protected $fillable = [
        'uuid',
        'rec_employee_id',
        'team_id',
        // Iteration 3 — vollstaendiges HR-Field-Set
        'contract_signed_at',
        'contract_sent_date',
        'contract_end_date',
        'export_status',
        'employment_classification',
    ];

    protected $casts = [
        'contract_signed_at' => 'date',
        'contract_sent_date' => 'date',
        'contract_end_date'  => 'date',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $model) {
            if (empty($model->uuid)) {
                $model->uuid = UuidV7::generate();
            }
        });
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(RecEmployee::class, 'rec_employee_id');
    }
}
