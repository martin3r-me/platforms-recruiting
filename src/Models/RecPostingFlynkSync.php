<?php

namespace Platform\Recruiting\Models;

use Illuminate\Database\Eloquent\Model;

class RecPostingFlynkSync extends Model
{
    protected $table = 'rec_posting_flynk_syncs';

    protected $fillable = [
        'rec_posting_id', 'generation', 'event_type', 'seq', 'content_hash', 'closes_key', 'closes_assumed',
        'flynk_task_id', 'status', 'http_status', 'attempts', 'permanent_failure',
        'last_error', 'sent_at',
    ];

    protected $casts = [
        'generation' => 'integer',
        'seq' => 'integer',
        'attempts' => 'integer',
        'http_status' => 'integer',
        'permanent_failure' => 'boolean',
        'closes_assumed' => 'boolean',
        'sent_at' => 'datetime',
    ];

    public function posting()
    {
        return $this->belongsTo(RecPosting::class, 'rec_posting_id');
    }
}
