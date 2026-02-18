<?php

namespace Platform\Recruiting\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;

class RecPostingCommsChannel extends Pivot
{
    protected $table = 'rec_posting_comms_channel';

    public $incrementing = true;

    public function posting()
    {
        return $this->belongsTo(RecPosting::class, 'rec_posting_id');
    }

    public function commsChannel()
    {
        return $this->belongsTo(\Platform\Crm\Models\CommsChannel::class, 'comms_channel_id');
    }
}
