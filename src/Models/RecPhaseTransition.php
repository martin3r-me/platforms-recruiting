<?php

namespace Platform\Recruiting\Models;

use Illuminate\Database\Eloquent\Model;

class RecPhaseTransition extends Model
{
    protected $table = 'rec_phase_transitions';

    protected $fillable = [
        'team_id', 'rec_applicant_id', 'rec_position_id',
        'from_phase_id', 'to_phase_id', 'from_phase_name', 'to_phase_name',
        'trigger', 'source', 'source_log_id', 'occurred_at',
    ];

    protected $casts = [
        'occurred_at' => 'datetime',
    ];
}
