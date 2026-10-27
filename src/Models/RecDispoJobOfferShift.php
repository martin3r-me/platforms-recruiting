<?php

namespace Platform\Recruiting\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RecDispoJobOfferShift extends Model
{
    protected $table = 'rec_dispo_job_offer_shifts';
    protected $fillable = ['offer_id', 'shift_id', 'accepted_at', 'zas_entered_at', 'zas_entered_by_user_id', 'zas_assignment_id', 'declined_at', 'declined_reason', 'declined_note'];
    protected $casts = ['accepted_at' => 'datetime', 'zas_entered_at' => 'datetime', 'zas_assignment_id' => 'integer', 'declined_at' => 'datetime'];

    public function offer(): BelongsTo { return $this->belongsTo(RecDispoJobOffer::class, 'offer_id'); }
    public function shift(): BelongsTo { return $this->belongsTo(RecDispoJobShift::class, 'shift_id'); }
}
