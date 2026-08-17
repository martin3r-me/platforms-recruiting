<?php

namespace Platform\Recruiting\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Symfony\Component\Uid\UuidV7;

class RecInterview extends Model
{
    use SoftDeletes;

    protected $table = 'rec_interviews';

    protected $fillable = [
        'uuid',
        'interview_type_id',
        'rec_position_id',
        'rec_posting_id',
        'title',
        'description',
        'location',
        'starts_at',
        'ends_at',
        'min_participants',
        'max_participants',
        'status',
        'language',
        'is_active',
        'team_id',
        'reminder_wa_template_id',
        'reminder_hours_before',
        'reminder_wa_template_variables',
        'created_by_user_id',
        'owned_by_user_id',
    ];

    protected $casts = [
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'min_participants' => 'integer',
        'max_participants' => 'integer',
        'is_active' => 'boolean',
        'reminder_hours_before' => 'integer',
        'reminder_wa_template_variables' => 'array',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $model) {
            if (empty($model->uuid)) {
                do {
                    $uuid = UuidV7::generate();
                } while (self::where('uuid', $uuid)->exists());
                $model->uuid = $uuid;
            }
        });
    }

    public function interviewType(): BelongsTo
    {
        return $this->belongsTo(RecInterviewType::class, 'interview_type_id');
    }

    public function position(): BelongsTo
    {
        return $this->belongsTo(RecPosition::class, 'rec_position_id');
    }

    public function posting(): BelongsTo
    {
        return $this->belongsTo(RecPosting::class, 'rec_posting_id');
    }

    public function interviewers(): BelongsToMany
    {
        return $this->belongsToMany(
            \Platform\Core\Models\User::class,
            'rec_interview_user',
            'rec_interview_id',
            'user_id'
        );
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(RecInterviewBooking::class, 'rec_interview_id');
    }

    /** Belegte Plaetze nach zentraler Zaehlregel (Standby zaehlt nicht). */
    public function takenSeatsCount(): int
    {
        return $this->bookings()->seatTaking()->count();
    }

    public function hasFreeSeat(): bool
    {
        return !$this->max_participants || $this->takenSeatsCount() < $this->max_participants;
    }

    /** Freie Plaetze nach zentraler Zaehlregel; null = unbegrenzt (kein max_participants). */
    public function freeSeatsCount(): ?int
    {
        if (!$this->max_participants) {
            return null;
        }

        return max(0, $this->max_participants - $this->takenSeatsCount());
    }

    /** Standby-Buchungen (booked + seat_released_at) — fuer HR-Anzeige. */
    public function standbySeatsCount(): int
    {
        return $this->bookings()
            ->where('status', 'booked')
            ->whereNotNull('seat_released_at')
            ->count();
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(\Platform\Core\Models\Team::class, 'team_id');
    }

    /**
     * Available variable sources for template mapping.
     */
    public const TEMPLATE_VARIABLE_SOURCES = [
        'start_date' => 'Start-Datum (z.B. 25.03.2026)',
        'start_time' => 'Start-Uhrzeit (z.B. 14:00)',
        'start_date_time' => 'Start Datum + Uhrzeit',
        'end_date' => 'End-Datum (z.B. 25.03.2026)',
        'end_time' => 'End-Uhrzeit (z.B. 16:00)',
        'end_date_time' => 'End Datum + Uhrzeit',
        'interview_location' => 'Ort',
        'interview_title' => 'Titel',
        'job_title' => 'Stellenbezeichnung',
        'candidate_name' => 'Kandidatenname',
        'form_link' => 'Public-Form Link',
    ];

    /**
     * Resolve template components for the Meta API based on stored variable mapping.
     */
    public function resolveTemplateComponents(
        array $templateComponents,
        ?RecInterviewBooking $booking = null,
    ): array {
        $mapping = $this->reminder_wa_template_variables ?? [];
        if (empty($mapping)) {
            return [];
        }

        // Parse body variables and detect named vs positional params
        $bodyText = '';
        $namedParamDefs = [];
        $hasUrlButton = false;
        foreach ($templateComponents as $comp) {
            if (strtolower((string) ($comp['type'] ?? '')) === 'body') {
                $bodyText = (string) ($comp['text'] ?? '');
                $namedParamDefs = $comp['example']['body_text_named_params'] ?? [];
            }
            if (($comp['type'] ?? '') === 'BUTTONS') {
                foreach ($comp['buttons'] ?? [] as $btn) {
                    if (($btn['type'] ?? '') === 'URL' && str_contains($btn['url'] ?? '', '{{')) {
                        $hasUrlButton = true;
                    }
                }
            }
        }

        $isNamed = !empty($namedParamDefs);

        preg_match_all('/\{\{(\d+)\}\}/', $bodyText, $numMatches);
        preg_match_all('/\{\{(\w+)\}\}/', $bodyText, $namedMatches);
        $varCount = !empty($numMatches[1]) ? (int) max($numMatches[1]) : count(array_unique($namedMatches[1] ?? []));

        $components = [];

        if ($varCount > 0) {
            $parameters = [];
            if ($isNamed) {
                foreach ($namedParamDefs as $i => $paramDef) {
                    $paramName = $paramDef['param_name'] ?? '';
                    $source = $mapping['body_' . ($i + 1)] ?? '';
                    $parameters[] = [
                        'type' => 'text',
                        'parameter_name' => $paramName,
                        'text' => $this->resolveVariableValue($source, $booking),
                    ];
                }
            } else {
                for ($i = 1; $i <= $varCount; $i++) {
                    $source = $mapping["body_{$i}"] ?? '';
                    $parameters[] = [
                        'type' => 'text',
                        'text' => $this->resolveVariableValue($source, $booking),
                    ];
                }
            }
            $components[] = [
                'type' => 'body',
                'parameters' => $parameters,
            ];
        }

        // URL button parameter
        if ($hasUrlButton) {
            $urlSource = $mapping['url_button'] ?? '';
            $urlValue = $this->resolveVariableValue($urlSource, $booking);
            if ($urlValue !== '') {
                $components[] = [
                    'type' => 'button',
                    'sub_type' => 'url',
                    'index' => 0,
                    'parameters' => [['type' => 'text', 'text' => $urlValue]],
                ];
            }
        }

        return $components;
    }

    /**
     * Resolve a single variable value from its source key.
     */
    private function resolveVariableValue(string $source, ?RecInterviewBooking $booking = null): string
    {
        return match ($source) {
            'start_date' => $this->starts_at?->format('d.m.Y') ?? '',
            'start_time' => $this->starts_at?->format('H:i') ?? '',
            'start_date_time' => $this->starts_at?->format('d.m.Y H:i') ?? '',
            'end_date' => $this->ends_at?->format('d.m.Y') ?? '',
            'end_time' => $this->ends_at?->format('H:i') ?? '',
            'end_date_time' => $this->ends_at?->format('d.m.Y H:i') ?? '',
            'interview_location' => $this->location ?? '',
            'interview_title' => $this->title ?? '',
            'job_title' => $this->position?->title ?? '',
            'candidate_name' => $booking?->applicant?->crmContactLinks?->first()?->contact?->first_name ?? '',
            'form_link' => $booking?->applicant?->getPublicUrl() ?? '',
            default => '',
        };
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeForTeam($query, $teamId)
    {
        return $query->where('team_id', $teamId);
    }
}
