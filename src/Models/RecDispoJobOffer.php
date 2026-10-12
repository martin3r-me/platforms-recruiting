<?php

namespace Platform\Recruiting\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Ein Angebot = eine Person in einer Welle (die Nachricht). Die Schichten haengen in offer_shifts. */
class RecDispoJobOffer extends Model
{
    protected $table = 'rec_dispo_job_offers';
    protected $fillable = ['wave_id', 'request_id', 'rec_employee_id', 'anchor_employee_id', 'message_id', 'sent_at', 'send_error'];
    protected $casts = ['message_id' => 'integer', 'sent_at' => 'datetime'];

    public function request(): BelongsTo { return $this->belongsTo(RecDispoJobRequest::class, 'request_id'); }
    public function wave(): BelongsTo { return $this->belongsTo(RecDispoJobWave::class, 'wave_id'); }
    public function employee(): BelongsTo { return $this->belongsTo(RecEmployee::class, 'rec_employee_id'); }
    public function shifts(): HasMany { return $this->hasMany(RecDispoJobOfferShift::class, 'offer_id'); }
}
