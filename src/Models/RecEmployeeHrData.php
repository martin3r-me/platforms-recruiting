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
        // Iteration 4
        'linen_package_items',
        'star_rating',
        'qualifications',
        // Iteration 5 — fuenf Kriterien + Freitext (Spec §1/N1)
        'rating_erscheinungsbild',
        'rating_fachkompetenz',
        'rating_auffassungsgabe',
        'rating_auftreten',
        'rating_teamintegration',
        'evaluation_note',
    ];

    protected $casts = [
        'contract_signed_at'  => 'date',
        'contract_sent_date'  => 'date',
        'contract_end_date'   => 'date',
        'linen_package_items' => 'array',
        'qualifications'      => 'array',
        'star_rating'         => 'integer',
        'rating_erscheinungsbild' => 'integer',
        'rating_fachkompetenz'    => 'integer',
        'rating_auffassungsgabe'  => 'integer',
        'rating_auftreten'        => 'integer',
        'rating_teamintegration'  => 'integer',
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
