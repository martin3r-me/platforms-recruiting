<?php

namespace Platform\Recruiting\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * "HR muss nachfassen"-Markierung an einem WhatsApp-Thread. Offen, solange
 * cleared_at leer ist. Kein Fremdschluessel ueber die Modulgrenze.
 */
class RecConversationFollowup extends Model
{
    protected $table = 'rec_conversation_followups';

    protected $fillable = [
        'team_id',
        'comms_whatsapp_thread_id',
        'marked_by_user_id',
        'marked_at',
        'cleared_at',
        'cleared_by_user_id',
    ];

    protected $casts = [
        'marked_at' => 'datetime',
        'cleared_at' => 'datetime',
    ];
}
