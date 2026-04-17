<?php

namespace Platform\Recruiting\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Platform\Core\Contracts\InheritsExtraFields;
use Platform\Core\Traits\HasExtraFields;
use Platform\Core\Traits\HasPublicFormLink;
use Platform\Recruiting\Traits\HasApplicantContact;
use Platform\Hcm\Traits\SyncsCrmContactFields;
use Symfony\Component\Uid\UuidV7;
use Platform\Recruiting\Models\RecAutoPilotLog;
use Platform\Recruiting\Models\RecAutoPilotState;

class RecApplicant extends Model implements InheritsExtraFields
{
    use HasApplicantContact;
    use HasExtraFields;
    use HasPublicFormLink;
    use SyncsCrmContactFields;

    protected $table = 'rec_applicants';

    protected $fillable = [
        'uuid', 'public_token', 'rec_applicant_status_id', 'rec_phase_id', 'progress', 'notes', 'applied_at',
        'is_active', 'is_parked', 'parked_at',
        'auto_pilot', 'auto_pilot_completed_at', 'auto_pilot_state_id',
        'auto_pilot_reminder_count', 'auto_pilot_last_reminder_at',
        'preferred_comms_channel_id', 'enrichment_status',
        'team_id', 'created_by_user_id', 'owned_by_user_id',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'is_parked' => 'boolean',
        'parked_at' => 'datetime',
        'auto_pilot' => 'boolean',
        'auto_pilot_completed_at' => 'datetime',
        'auto_pilot_state_id' => 'integer',
        'auto_pilot_reminder_count' => 'integer',
        'auto_pilot_last_reminder_at' => 'datetime',
        'progress' => 'integer',
        'applied_at' => 'date',
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
            if (empty($model->public_token)) {
                $model->public_token = $model->generatePublicToken();
            }
        });

        static::saving(function (self $model) {
            if ($model->isDirty('auto_pilot') && !$model->auto_pilot && $model->getOriginal('auto_pilot')) {
                if ($model->calculateProgress() < 100) {
                    $model->auto_pilot = true;
                }
            }
        });
    }

    public function generatePublicToken(): string
    {
        do {
            $token = bin2hex(random_bytes(16));
        } while (self::where('public_token', $token)->exists());

        return $token;
    }

    public function getPublicUrl(): string
    {
        return $this->getOrCreatePublicFormLink()->getUrl();
    }

    /**
     * Parent-Models von denen Extra-Field-Definitionen geerbt werden.
     * Applicants erben Extra-Felder von allen Phasen bis inkl. der aktuellen,
     * damit im Dashboard/API alle bisher gesammelten Daten sichtbar sind.
     */
    public function extraFieldParents(): array
    {
        $phase = $this->phase;
        if (!$phase) {
            return [];
        }

        return RecPhase::where('rec_position_id', $phase->rec_position_id)
            ->where('order', '<=', $phase->order)
            ->where('is_active', true)
            ->orderBy('order')
            ->get()
            ->all();
    }

    public function phase()
    {
        return $this->belongsTo(RecPhase::class, 'rec_phase_id');
    }

    public function postings()
    {
        return $this->belongsToMany(RecPosting::class, 'rec_applicant_posting', 'rec_applicant_id', 'rec_posting_id')
            ->using(RecApplicantPosting::class)
            ->withPivot(['applied_at', 'notes'])
            ->withTimestamps();
    }

    public function positions(): Collection
    {
        return $this->postings->map(fn ($posting) => $posting->position)->filter()->unique('id')->values();
    }

    public function applicantStatus()
    {
        return $this->belongsTo(RecApplicantStatus::class, 'rec_applicant_status_id');
    }

    public function preferredCommsChannel(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(\Platform\Crm\Models\CommsChannel::class, 'preferred_comms_channel_id');
    }

    public function autoPilotState()
    {
        return $this->belongsTo(RecAutoPilotState::class, 'auto_pilot_state_id');
    }

    public function team()
    {
        return $this->belongsTo(\Platform\Core\Models\Team::class, 'team_id');
    }

    public function createdByUser()
    {
        return $this->belongsTo(\Platform\Core\Models\User::class, 'created_by_user_id');
    }

    public function ownedByUser()
    {
        return $this->belongsTo(\Platform\Core\Models\User::class, 'owned_by_user_id');
    }

    public function autoPilotLogs()
    {
        return $this->hasMany(RecAutoPilotLog::class, 'rec_applicant_id');
    }

    public function interviewBookings()
    {
        return $this->hasMany(RecInterviewBooking::class, 'rec_applicant_id');
    }

    public function contracts()
    {
        return $this->hasMany(RecContract::class, 'rec_applicant_id');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true)->where('is_parked', false);
    }

    public function scopeForTeam($query, $teamId)
    {
        return $query->where('team_id', $teamId);
    }

    public function checkAutoPilotCompletion(): void
    {
        if (!$this->auto_pilot || $this->auto_pilot_completed_at !== null) {
            return;
        }

        $progress = $this->calculateProgress();

        if ($progress < 100) {
            return;
        }

        $phase = $this->phase;
        $nextPhase = $phase?->nextPhase();

        // Phase has a successor → advance to next phase
        if ($nextPhase && $phase?->auto_advance) {
            $this->rec_phase_id = $nextPhase->id;
            $this->auto_pilot_completed_at = null;
            $this->auto_pilot_reminder_count = 0;
            $this->auto_pilot_last_reminder_at = null;
            $this->progress = 0;
            $this->clearExtraFieldDefinitionsCache();
            $this->save();

            RecAutoPilotLog::create([
                'rec_applicant_id' => $this->id,
                'type' => 'phase_advanced',
                'summary' => "Phase \"{$phase->name}\" abgeschlossen — weiter zu \"{$nextPhase->name}\".",
            ]);
            return;
        }

        // Last phase or manual advance → mark as completed
        $completedStateId = RecAutoPilotState::where('code', 'completed')
            ->whereNull('team_id')
            ->value('id');

        $this->auto_pilot_state_id = $completedStateId;
        $this->auto_pilot_completed_at = now();
        $this->save();

        $phaseName = $phase?->name ?? 'Unbekannt';
        RecAutoPilotLog::create([
            'rec_applicant_id' => $this->id,
            'type' => 'completed',
            'summary' => "Alle Pflichtfelder in Phase \"{$phaseName}\" ausgefüllt — AutoPilot abgeschlossen.",
        ]);

        // Send interview booking WA template if configured
        $this->sendInterviewBookingNotification();
    }

    /**
     * Manually advance applicant to next phase (for auto_advance=false phases).
     */
    public function advanceToNextPhase(): bool
    {
        $phase = $this->phase;
        $nextPhase = $phase?->nextPhase();

        if (!$nextPhase) {
            return false;
        }

        $this->rec_phase_id = $nextPhase->id;
        $this->auto_pilot_completed_at = null;
        $this->auto_pilot_reminder_count = 0;
        $this->auto_pilot_last_reminder_at = null;
        $this->progress = 0;
        $this->clearExtraFieldDefinitionsCache();
        $this->save();

        RecAutoPilotLog::create([
            'rec_applicant_id' => $this->id,
            'type' => 'phase_advanced',
            'summary' => "Manuell weiter zu Phase \"{$nextPhase->name}\".",
        ]);

        return true;
    }

    /**
     * Send interview booking link via WhatsApp template (on AutoPilot completion).
     */
    public function sendInterviewBookingNotification(): bool
    {
        try {
            $this->loadMissing(['postings.position', 'crmContactLinks.contact.phoneNumbers']);

            // Resolve position settings → team settings cascade
            $position = $this->postings->sortBy('pivot.applied_at')->first()?->position;
            $positionSettings = $position?->auto_pilot_settings ?? [];
            $teamSettings = RecApplicantSettings::getOrCreateForTeam($this->team_id);

            $templateId = $positionSettings['interview_booking_wa_template_id']
                ?? $teamSettings->getSetting('interview_booking_wa_template_id');

            if (!$templateId) {
                return false;
            }

            if (!class_exists(\Platform\Integrations\Models\IntegrationsWhatsAppTemplate::class)) {
                return false;
            }

            $template = \Platform\Integrations\Models\IntegrationsWhatsAppTemplate::find($templateId);
            if (!$template || $template->status !== 'APPROVED') {
                return false;
            }

            // Resolve WA channel
            $waAccountId = $positionSettings['auto_pilot_wa_account_id']
                ?? $teamSettings->getSetting('auto_pilot_wa_account_id');

            if (!$waAccountId || !class_exists(\Platform\Integrations\Models\IntegrationsWhatsAppAccount::class)) {
                return false;
            }

            $account = \Platform\Integrations\Models\IntegrationsWhatsAppAccount::find($waAccountId);
            if (!$account || !$account->active) {
                return false;
            }

            $channel = \Platform\Crm\Models\CommsChannel::where('type', 'whatsapp')
                ->where('is_active', true)
                ->where('sender_identifier', $account->phone_number)
                ->first();

            if (!$channel) {
                return false;
            }

            // Find primary phone number
            $phoneNumber = null;
            foreach ($this->crmContactLinks as $link) {
                $contact = $link->contact;
                if (!$contact) continue;

                $phoneNumber = $contact->phoneNumbers
                    ->where('is_active', true)
                    ->where('is_primary', true)
                    ->whereNotNull('international')
                    ->first();

                if (!$phoneNumber) {
                    $phoneNumber = $contact->phoneNumbers
                        ->where('is_active', true)
                        ->whereNotNull('international')
                        ->first();
                }

                if ($phoneNumber) break;
            }

            if (!$phoneNumber) {
                return false;
            }

            // Build components
            $components = [];

            // Body parameters
            $bodyParams = [];
            foreach ($template->components ?? [] as $component) {
                if (($component['type'] ?? '') !== 'BODY') continue;

                $text = $component['text'] ?? '';
                $examplesByName = [];
                foreach ($component['example']['body_text_named_params'] ?? [] as $np) {
                    $examplesByName[$np['param_name']] = $np['example'] ?? '';
                }
                $positionalExamples = $component['example']['body_text'][0] ?? [];

                preg_match_all('/\{\{(\w+)\}\}/', $text, $matches);
                foreach ($matches[1] as $i => $paramName) {
                    $bodyParams[] = [
                        'name' => $paramName,
                        'example' => $examplesByName[$paramName] ?? $positionalExamples[$i] ?? '',
                    ];
                }
            }

            if (!empty($bodyParams)) {
                $contactName = $this->getContact()?->full_name ?? 'Bewerber/in';
                $bodyParameters = [];
                foreach ($bodyParams as $param) {
                    $value = match (strtolower($param['name'])) {
                        '1', 'name', 'vorname' => $contactName,
                        default => $param['example'] ?: $contactName,
                    };
                    $paramEntry = ['type' => 'text', 'text' => $value];
                    if (!is_numeric($param['name'])) {
                        $paramEntry['parameter_name'] = $param['name'];
                    }
                    $bodyParameters[] = $paramEntry;
                }
                $components[] = ['type' => 'body', 'parameters' => $bodyParameters];
            }

            // URL button with interview booking token (public_token)
            $hasUrlButton = collect($template->components ?? [])
                ->where('type', 'BUTTONS')
                ->flatMap(fn ($c) => $c['buttons'] ?? [])
                ->contains('type', 'URL');

            if ($hasUrlButton && $this->public_token) {
                $components[] = [
                    'type' => 'button',
                    'sub_type' => 'url',
                    'index' => 0,
                    'parameters' => [['type' => 'text', 'text' => $this->public_token]],
                ];
            }

            // Send
            $service = app(\Platform\Crm\Services\Comms\WhatsAppMetaService::class);
            $message = $service->sendTemplate(
                channel: $channel,
                to: $phoneNumber->international,
                templateName: $template->name,
                components: $components,
                languageCode: $template->language,
            );

            // Link thread to applicant
            if ($thread = $message->thread ?? null) {
                $thread->addContext($this->getMorphClass(), $this->id, 'interview_booking');
            }

            RecAutoPilotLog::create([
                'rec_applicant_id' => $this->id,
                'type' => 'interview_booking_sent',
                'summary' => 'Interview-Buchungslink per WhatsApp gesendet.',
            ]);

            return true;
        } catch (\Throwable $e) {
            try {
                RecAutoPilotLog::create([
                    'rec_applicant_id' => $this->id,
                    'type' => 'error',
                    'summary' => 'Interview-Booking WA-Fehler: ' . $e->getMessage(),
                ]);
            } catch (\Throwable) {}

            return false;
        }
    }

    public function calculateProgress(): int
    {
        $definitions = $this->getExtraFieldDefinitions();
        $requiredDefinitions = $definitions->where('is_required', true);

        if ($requiredDefinitions->isEmpty()) {
            return 100;
        }

        $values = $this->extraFieldValues()
            ->whereIn('definition_id', $requiredDefinitions->pluck('id'))
            ->get()
            ->keyBy('definition_id');

        $filled = 0;
        foreach ($requiredDefinitions as $def) {
            $val = $values->get($def->id);
            if ($val !== null && $val->value !== null && $val->value !== '' && $val->value !== '[]') {
                $filled++;
            }
        }

        return (int) round(($filled / $requiredDefinitions->count()) * 100);
    }
}
