<?php

namespace Platform\Recruiting\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Symfony\Component\Uid\UuidV7;

class RecHrDeskCase extends Model
{
    protected $table = 'rec_hr_desk_cases';

    // rec_employee_id steht hier BEWUSST drin: den HR-Fall legt der
    // Einsatz-Trigger regulaer per create() an — anders als die
    // Trigger-Zustandsspalten, die am Beobachter vorbei geschrieben werden
    // muessen. An rec_hr_desk_cases haengt kein ZAS-Export-Marker.
    protected $fillable = [
        'uuid',
        'rec_applicant_id',
        'rec_employee_id',
        'team_id',
        'reason',
        'status',
        'notes',
        'opened_at',
        'opened_by_user_id',
        'resolved_at',
        'resolved_by_user_id',
        'resolution_notes',
    ];

    protected $casts = [
        'opened_at' => 'datetime',
        'resolved_at' => 'datetime',
    ];

    public const STATUS_OPEN = 'open';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';

    public const REASON_NON_EU_CITIZEN = 'non_eu_citizen';
    public const REASON_NO_GERMAN_KNOWLEDGE = 'no_german_knowledge';
    public const REASON_APPLICANT_CANCELLED_TRAINING = 'applicant_cancelled_training';
    public const REASON_MINOR = 'minor';

    // Schulungsleiter markiert eine Buchung „zur Klaerung an HR" (Gehaltswunsch,
    // Nachfrage, fehlendes Dokument ausserhalb der Non-EU-Pruefung) — angelegt
    // aus der Anwesenheitspflege (InterviewBookings\Index::submitClarification).
    public const REASON_TRAINING_CLARIFICATION = 'training_clarification';

    // Die Arbeitserlaubnis fehlt oder ist abgelaufen (Canvas-Abgleich Folie 14).
    // Angelegt vom Einsatz-Trigger, nicht aus dem Bewerberprozess — deshalb
    // haengt dieser Grund am MITARBEITER und nicht am Bewerber.
    public const REASON_WORK_PERMIT = 'work_permit';

    /** Map reason-codes auf sprechende deutsche Labels für UI-Anzeige. */
    /**
     * Offene Faelle dieser Reasons blockieren den VERTRAGSVERSAND aus der
     * Nachbereitung (Einzel- und Bulk-Pfad, InterviewBookings\Index): alles
     * Pflicht-Pruefungen bzw. ausdrueckliche „erst HR"-Markierungen. Die
     * uebrigen Reasons (Absage, Deutschkenntnisse) blocken dort nicht — bei
     * ihnen ist der Versand gar nicht das Thema.
     */
    public const CONTRACT_BLOCKING_REASONS = [
        self::REASON_NON_EU_CITIZEN,
        self::REASON_MINOR,
        self::REASON_TRAINING_CLARIFICATION,
    ];

    public const REASON_LABELS = [
        self::REASON_NON_EU_CITIZEN => 'Nicht-EU-Bürger',
        self::REASON_NO_GERMAN_KNOWLEDGE => 'Keine grundlegenden Deutschkenntnisse',
        self::REASON_APPLICANT_CANCELLED_TRAINING => 'Schulung vom Bewerber abgesagt',
        self::REASON_MINOR => 'Minderjährig (unter 18)',
        self::REASON_TRAINING_CLARIFICATION => 'Klärung aus der Schulung',
        self::REASON_WORK_PERMIT => 'Arbeitserlaubnis fehlt oder ist abgelaufen',
    ];

    public function reasonLabel(): string
    {
        return self::REASON_LABELS[$this->reason] ?? $this->reason;
    }

    protected static function booted(): void
    {
        static::creating(function (self $model) {
            if (empty($model->uuid)) {
                $model->uuid = UuidV7::generate();
            }
        });
    }

    public function applicant(): BelongsTo
    {
        return $this->belongsTo(RecApplicant::class, 'rec_applicant_id');
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(\Platform\Core\Models\Team::class, 'team_id');
    }

    public function openedByUser(): BelongsTo
    {
        return $this->belongsTo(\Platform\Core\Models\User::class, 'opened_by_user_id');
    }

    public function resolvedByUser(): BelongsTo
    {
        return $this->belongsTo(\Platform\Core\Models\User::class, 'resolved_by_user_id');
    }

    public function scopeOpen($query)
    {
        return $query->where('status', self::STATUS_OPEN);
    }

    public function scopeForTeam($query, $teamId)
    {
        return $query->where('team_id', $teamId);
    }

    public function isOpen(): bool
    {
        return $this->status === self::STATUS_OPEN;
    }

    public function isResolved(): bool
    {
        return in_array($this->status, [self::STATUS_APPROVED, self::STATUS_REJECTED]);
    }
}
