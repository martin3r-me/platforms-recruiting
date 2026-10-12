<?php

namespace Platform\Recruiting\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * HR hat das Zustellproblem eines Threads abgehakt (z. B. angerufen).
 * Gilt nur bis zur naechsten gescheiterten Nachricht — siehe
 * UndeliveredQuery: abgehakt ist, solange comms_whatsapp_message_id
 * mindestens die ID der zuletzt gescheiterten Nachricht ist.
 */
class RecDeliveryFailureAck extends Model
{
    protected $table = 'rec_delivery_failure_acks';

    protected $fillable = [
        'team_id',
        'comms_whatsapp_thread_id',
        'comms_whatsapp_message_id',
        'acknowledged_at',
        'acknowledged_by_user_id',
    ];

    protected $casts = [
        'acknowledged_at' => 'datetime',
    ];
}
