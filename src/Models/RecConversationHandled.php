<?php

namespace Platform\Recruiting\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Ein Thread, den HR abgehakt hat. Fehlt die Zeile, ist nichts abgehakt.
 * Ob der Stempel noch gilt, entscheidet NICHT dieses Modell, sondern
 * ConversationHandledState — ein spaeterer Eingang hebt ihn auf.
 */
class RecConversationHandled extends Model
{
    public const REASON_MANUAL = 'manual';
    public const REASON_BACKFILL = 'backfill';

    protected $table = 'rec_conversation_handled';

    protected $fillable = [
        'team_id',
        'comms_whatsapp_thread_id',
        'handled_at',
        'handled_by_user_id',
        'handled_reason',
    ];

    protected $casts = [
        'handled_at' => 'datetime',
    ];
}
