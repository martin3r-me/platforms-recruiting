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
    /** Letzter Eingang war ein Button-Klick (QuickReply) — keine Arbeit fuer HR. */
    public const REASON_QUICK_REPLY = 'quick_reply';

    /** Themen-Auswahl: Mitarbeiter wurde per Link an die Dispo weitergeleitet — keine Arbeit fuer HR. */
    public const REASON_ROUTED_DISPO = 'routed_dispo';

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
