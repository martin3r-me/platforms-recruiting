<?php
namespace Platform\Recruiting\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Eine Absage-Pruefung je (eingehende Nachricht, Veranstaltung) — Spec
 * 2026-10-08. Die KI meldet nur; abgesagt wird ausschliesslich ueber das
 * bestehende Absage-Fenster. review_status = open heisst "Meldung wartet auf
 * einen Disponenten".
 */
class RecDispoDeclineCheck extends Model
{
    public const OUTCOME_DECLINE      = 'decline';
    public const OUTCOME_NO_DECLINE   = 'no_decline';
    public const OUTCOME_PATTERN_SKIP = 'pattern_skip';
    public const OUTCOME_FAILED       = 'failed';

    public const REVIEW_OPEN      = 'open';
    public const REVIEW_ACCEPTED  = 'accepted';
    public const REVIEW_DISMISSED = 'dismissed';

    protected $table = 'rec_dispo_decline_checks';

    protected $fillable = [
        'team_id', 'filial_nr', 'rec_dispo_event_id', 'rec_employee_id', 'comms_whatsapp_message_id',
        'outcome', 'used_llm', 'confidence', 'assignment_ids', 'reason', 'excerpt',
        'alarm_message_id', 'review_status', 'reviewed_by_user_id', 'reviewed_at',
    ];

    protected $casts = [
        'team_id'                   => 'integer',
        'filial_nr'                 => 'integer',
        'rec_dispo_event_id'        => 'integer',
        'rec_employee_id'           => 'integer',
        'comms_whatsapp_message_id' => 'integer',
        'used_llm'                  => 'boolean',
        'assignment_ids'            => 'array',
        'alarm_message_id'          => 'integer',
        'reviewed_by_user_id'       => 'integer',
        'reviewed_at'               => 'datetime',
    ];
}
