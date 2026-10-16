<?php

namespace Platform\Recruiting\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Themen-Auswahl eines WhatsApp-Threads (S-Themen): unsere Frage, die Wahl des
 * Mitarbeiters und der Weg danach. Kein Fremdschluessel ueber die Modulgrenze.
 */
class RecConversationTopic extends Model
{
    public const TOPIC_DISPO = 'dispo';
    public const TOPIC_LOHN = 'lohn';
    public const TOPIC_HR = 'hr';

    public const STATE_ASKED = 'asked';
    public const STATE_CHOSEN = 'chosen';
    public const STATE_AKTE_HINT = 'akte_hint_sent';
    public const STATE_LOHN_INFO = 'lohn_info_sent';
    public const STATE_ROUTED = 'routed';

    protected $table = 'rec_conversation_topics';

    protected $fillable = [
        'team_id',
        'comms_whatsapp_thread_id',
        'topic',
        'state',
        'dispo_channel_id',
        'origin_message_id',
        'ask_message_id',
        'last_click_message_id',
        'asked_at',
        'chosen_at',
    ];

    protected $casts = [
        'asked_at' => 'datetime',
        'chosen_at' => 'datetime',
    ];
}
