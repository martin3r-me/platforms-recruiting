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
    /**
     * Lohn bei unvollstaendiger Akte (Variante B, 26.10.2026): der Chat wartet
     * ausserhalb von „Ungelesen“, bis die Akte vollstaendig ist (LohnParken).
     * STATE_AKTE_HINT bleibt fuer Zeilen von vorher.
     */
    public const STATE_GEPARKT = 'lohn_parked';
    /** Akte nachgereicht — der Chat ist wieder normal bei HR. */
    public const STATE_FREIGEGEBEN = 'lohn_released';

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

    /**
     * Aktuelles Anliegen je Thread fuer die Anzeige (Spec 2.7, Nachtrag
     * 16.10.): die JUENGSTE Zeile des Threads, egal ob mit Thema oder nicht.
     * Ein Chip gehoert nur dazu, wenn diese Zeile ein Thema hat — eine neue,
     * noch unbeantwortete Frage (topic NULL) loest damit die alte Wahl ab.
     * Bewusst KEIN Ablauf nach der Ruhezeit: ein offener Lohn-Chat behaelt
     * seinen Chip, bis eine neue Frage kommt.
     *
     * NICHT im Subselect nach topic oder asked_at filtern — das wuerde eine
     * aeltere Wahl ueber eine neuere Frage heben.
     *
     * Eine Abfrage: MAX(id) je Thread ueber das Team (Zeilen werden nur
     * angehaengt, die hoechste ID ist die juengste); fremde Threads fallen
     * beim Zuordnen weg, damit die ~1000 IDs nicht als IN-Liste mitlaufen.
     *
     * @param list<int> $threadIds
     * @return array<int, self> threadId => juengste Zeile
     */
    public static function aktuelleJeThread(int $teamId, array $threadIds): array
    {
        if ($threadIds === []) {
            return [];
        }

        $rows = static::query()
            ->whereIn('id', function ($q) use ($teamId) {
                $q->from('rec_conversation_topics')
                    ->selectRaw('MAX(id)')
                    ->where('team_id', $teamId)
                    ->groupBy('comms_whatsapp_thread_id');
            })
            ->get(['id', 'comms_whatsapp_thread_id', 'topic', 'state', 'dispo_channel_id', 'asked_at', 'chosen_at']);

        $gesucht = array_fill_keys($threadIds, true);
        $out = [];
        foreach ($rows as $row) {
            $threadId = (int) $row->comms_whatsapp_thread_id;
            if (isset($gesucht[$threadId])) {
                $out[$threadId] = $row;
            }
        }

        return $out;
    }
}
