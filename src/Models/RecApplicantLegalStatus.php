<?php

namespace Platform\Recruiting\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Platform\Recruiting\Services\HrDeskRoutingService;

class RecApplicantLegalStatus extends Model
{
    protected $table = 'rec_applicant_legal_statuses';

    protected $fillable = [
        'rec_applicant_id',
        'team_id',
        'is_eu_citizen',
    ];

    protected $casts = [
        'is_eu_citizen' => 'boolean',
    ];

    public function applicant(): BelongsTo
    {
        return $this->belongsTo(RecApplicant::class, 'rec_applicant_id');
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(\Platform\Core\Models\Team::class, 'team_id');
    }

    public function setEuCitizen(?bool $value, ?int $userId = null): void
    {
        $oldValue = $this->is_eu_citizen;
        $this->is_eu_citizen = $value;
        $this->save();

        if ($oldValue !== $value) {
            app(HrDeskRoutingService::class)->handleEuStatusChange(
                $this->applicant,
                $value,
                $userId,
            );
        }
    }
}
