<?php

namespace Platform\Recruiting\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;

class RecApplicantPosting extends Pivot
{
    protected $table = 'rec_applicant_posting';

    public $incrementing = true;

    protected $casts = [
        'applied_at' => 'date',
    ];

    public function applicant()
    {
        return $this->belongsTo(RecApplicant::class, 'rec_applicant_id');
    }

    public function posting()
    {
        return $this->belongsTo(RecPosting::class, 'rec_posting_id');
    }
}
