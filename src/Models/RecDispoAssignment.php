<?php

namespace Platform\Recruiting\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Symfony\Component\Uid\UuidV7;

/**
 * Einbuchung aus dem ZAS-Webexport ({Dispo}). ds_ref = DS-ID ZAS (unique),
 * der Schluessel fuer die Step-2-Loeschmarkierung.
 *
 * rec_employee_id ist das Matching-Ergebnis (null = offene Referenz, wird
 * bei jedem Import-Lauf erneut versucht). Bewusst kein DB-FK (Spec).
 */
class RecDispoAssignment extends Model
{
    public const STATUS_ANGEBOT = 0;
    public const STATUS_AUFTRAG = 1;
    public const STATUS_BEENDET = 2;
    public const STATUS_STORNO  = 3;

    protected $table = 'rec_dispo_assignments';

    protected $fillable = [
        'uuid', 'ds_ref', 'rec_dispo_event_id', 'pnr_raw', 'rec_employee_id',
        'datum', 'von', 'bis', 'status_id', 'taetigkeit', 'individual_note', 'individual_note_updated_at',
        'last_seen_at', 'missing_since',
        'reminder_sent_at',
        'reminder_message_id',
        'confirmed_at',
        'confirmed_datum',
        'confirmed_von',
        'confirmed_bis',
        'reconfirm_required_at',
        'reconfirm_previous',
        'deletion_marked_at',
        'declined_at', 'declined_reason', 'declined_note', 'declined_by_user_id',
        'declined_portal_locked', 'declined_hr_at', 'declined_hr_done_at',
        'declined_hr_done_by_user_id', 'zas_removed_at', 'zas_removed_by_user_id',
        'escalation_due_1_at', 'escalation_due_2_at', 'escalation_due_3_at',
        'deletion_confirmed_at',
        'escalation_1_at',
        'escalation_2_at',
        'escalation_1_message_id',
        'escalation_2_message_id',
        'source_meta',
    ];

    protected $casts = [
        'datum'         => 'date:Y-m-d',
        'status_id'     => 'integer',
        'last_seen_at'  => 'datetime',
        'missing_since' => 'datetime',
        'reminder_sent_at'      => 'datetime',
        'individual_note_updated_at' => 'datetime',
        'reminder_message_id'   => 'integer',
        'confirmed_at'          => 'datetime',
        'confirmed_datum'       => 'date:Y-m-d',
        'reconfirm_required_at' => 'datetime',
        'reconfirm_previous'    => 'array',
        'deletion_marked_at'    => 'datetime',
        'declined_at'            => 'datetime',
        'declined_portal_locked' => 'boolean',
        'declined_hr_at'         => 'datetime',
        'declined_hr_done_at'    => 'datetime',
        'zas_removed_at'         => 'datetime',
        'escalation_due_1_at'    => 'datetime',
        'escalation_due_2_at'    => 'datetime',
        'escalation_due_3_at'    => 'datetime',
        'deletion_confirmed_at' => 'datetime',
        'escalation_1_at'       => 'datetime',
        'escalation_2_at'       => 'datetime',
        'escalation_1_message_id' => 'integer',
        'escalation_2_message_id' => 'integer',
        'source_meta'   => 'array',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $model) {
            if (empty($model->uuid)) {
                $model->uuid = (string) UuidV7::generate();
            }
        });
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(RecDispoEvent::class, 'rec_dispo_event_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(RecEmployee::class, 'rec_employee_id');
    }

    /** Versendete Bestaetigungs-Nachricht — fuer die Zustell-Status-Anzeige. */
    public function reminderMessage(): BelongsTo
    {
        return $this->belongsTo(\Platform\Crm\Models\CommsWhatsAppMessage::class, 'reminder_message_id');
    }

    /** Eskalations-Nachricht Stufe 1 (14-Uhr-Reminder) — fuer die Zustell-Status-Anzeige. */
    public function escalation1Message(): BelongsTo
    {
        return $this->belongsTo(\Platform\Crm\Models\CommsWhatsAppMessage::class, 'escalation_1_message_id');
    }

    /** Eskalations-Nachricht Stufe 2 (15-Uhr-Final) — fuer die Zustell-Status-Anzeige. */
    public function escalation2Message(): BelongsTo
    {
        return $this->belongsTo(\Platform\Crm\Models\CommsWhatsAppMessage::class, 'escalation_2_message_id');
    }
}
