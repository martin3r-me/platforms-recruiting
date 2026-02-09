<?php

namespace Platform\Recruiting\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RecAutoPilotLog extends Model
{
    public $timestamps = false;

    protected $table = 'rec_auto_pilot_logs';

    protected $fillable = [
        'rec_applicant_id', 'type', 'summary', 'details',
    ];

    protected $casts = [
        'details' => 'array',
        'created_at' => 'datetime',
    ];

    public function applicant(): BelongsTo
    {
        return $this->belongsTo(RecApplicant::class, 'rec_applicant_id');
    }
}
