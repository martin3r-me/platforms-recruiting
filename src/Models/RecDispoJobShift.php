<?php

namespace Platform\Recruiting\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RecDispoJobShift extends Model
{
    protected $table = 'rec_dispo_job_shifts';
    protected $fillable = ['request_id', 'rec_dispo_event_id', 'datum', 'von', 'bis', 'taetigkeit', 'gesucht', 'closed_at', 'gedeckt_manuell_at'];
    protected $casts = ['datum' => 'date:Y-m-d', 'gesucht' => 'integer', 'closed_at' => 'datetime', 'gedeckt_manuell_at' => 'datetime'];

    public function request(): BelongsTo { return $this->belongsTo(RecDispoJobRequest::class, 'request_id'); }
    public function event(): BelongsTo { return $this->belongsTo(RecDispoEvent::class, 'rec_dispo_event_id'); }
    public function offerShifts(): HasMany { return $this->hasMany(RecDispoJobOfferShift::class, 'shift_id'); }
}
