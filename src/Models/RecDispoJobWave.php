<?php

namespace Platform\Recruiting\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RecDispoJobWave extends Model
{
    protected $table = 'rec_dispo_job_waves';
    protected $fillable = ['request_id', 'nr', 'ma_bloecke', 'gruppen', 'stati', 'firmen', 'auto', 'nur_passende_taetigkeit', 'scheduled_for', 'started_at', 'finished_at', 'job_uuid', 'summary'];
    protected $casts = ['nr' => 'integer', 'ma_bloecke' => 'array', 'gruppen' => 'array', 'stati' => 'array', 'firmen' => 'array', 'auto' => 'boolean', 'nur_passende_taetigkeit' => 'boolean',
        'scheduled_for' => 'datetime', 'started_at' => 'datetime', 'finished_at' => 'datetime', 'summary' => 'array'];

    public function request(): BelongsTo { return $this->belongsTo(RecDispoJobRequest::class, 'request_id'); }
    public function offers(): HasMany { return $this->hasMany(RecDispoJobOffer::class, 'wave_id'); }
}
