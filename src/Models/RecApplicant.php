<?php

namespace Platform\Recruiting\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Platform\Core\Contracts\InheritsExtraFields;
use Platform\Core\Traits\HasExtraFields;
use Platform\Core\Traits\HasPublicFormLink;
use Platform\Recruiting\Traits\HasApplicantContact;
use Symfony\Component\Uid\UuidV7;
use Platform\Recruiting\Models\RecAutoPilotLog;
use Platform\Recruiting\Models\RecAutoPilotState;
use Platform\Crm\Models\CrmCountry;

class RecApplicant extends Model implements InheritsExtraFields
{
    use HasApplicantContact;
    use HasExtraFields;
    use HasPublicFormLink;

    protected $table = 'rec_applicants';

    protected $fillable = [
        'uuid', 'public_token', 'rec_applicant_status_id', 'progress', 'notes', 'applied_at',
        'is_active', 'auto_pilot', 'auto_pilot_completed_at', 'auto_pilot_state_id',
        'auto_pilot_reminder_count', 'auto_pilot_last_reminder_at',
        'preferred_comms_channel_id', 'enrichment_status',
        'team_id', 'created_by_user_id', 'owned_by_user_id',
    ];

    protected $casts = [
        'is_active' => 'boolean',
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
     * Applicants erben Extra-Felder von den Positionen ihrer Postings.
     */
    public function extraFieldParents(): array
    {
        return $this->positions()->all();
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

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
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

        if ($progress >= 100) {
            $completedStateId = RecAutoPilotState::where('code', 'completed')
                ->whereNull('team_id')
                ->value('id');

            $this->auto_pilot_state_id = $completedStateId;
            $this->auto_pilot_completed_at = now();
            $this->save();

            RecAutoPilotLog::create([
                'rec_applicant_id' => $this->id,
                'type' => 'completed',
                'summary' => 'Alle Pflichtfelder ausgefüllt (Public Form) — AutoPilot abgeschlossen.',
            ]);
        }
    }

    public function syncExtraFieldsToCrmContact(): void
    {
        $contact = $this->getContact();
        if (!$contact) {
            return;
        }

        $values = $this->extraFieldValues()->with('definition')->get();

        foreach ($values as $fieldValue) {
            $definition = $fieldValue->definition;
            if (!$definition) {
                continue;
            }

            $typed = $fieldValue->typed_value;
            if ($typed === null || $typed === '' || $typed === []) {
                continue;
            }

            match ($definition->type) {
                'phone' => $this->syncPhoneToCrmContact($contact, $typed),
                'email' => $this->syncEmailToCrmContact($contact, $typed),
                'address' => $this->syncAddressToCrmContact($contact, $typed),
                'date' => $this->syncDateToCrmContact($contact, $definition, $typed),
                default => null,
            };
        }
    }

    private function syncPhoneToCrmContact($contact, $value): void
    {
        if (!is_array($value) || empty($value['e164'])) {
            return;
        }

        $exists = $contact->phoneNumbers()->where('international', $value['international'] ?? $value['e164'])->exists();
        if ($exists) {
            return;
        }

        $hasPrimary = $contact->phoneNumbers()->where('is_primary', true)->exists();

        $contact->phoneNumbers()->create([
            'raw_input' => $value['raw'] ?? $value['e164'],
            'international' => $value['international'] ?? $value['e164'],
            'country_code' => $value['country'] ?? null,
            'is_primary' => !$hasPrimary,
            'is_active' => true,
        ]);
    }

    private function syncEmailToCrmContact($contact, $value): void
    {
        $email = is_string($value) ? $value : ($value['email'] ?? null);
        if (empty($email)) {
            return;
        }

        $exists = $contact->emailAddresses()->where('email_address', $email)->exists();
        if ($exists) {
            return;
        }

        $hasPrimary = $contact->emailAddresses()->where('is_primary', true)->exists();

        $contact->emailAddresses()->create([
            'email_address' => $email,
            'is_primary' => !$hasPrimary,
            'is_active' => true,
        ]);
    }

    private function syncAddressToCrmContact($contact, $value): void
    {
        if (!is_array($value) || empty($value['street']) || empty($value['city'])) {
            return;
        }

        $exists = $contact->postalAddresses()
            ->where('street', $value['street'])
            ->where('city', $value['city'])
            ->exists();
        if ($exists) {
            return;
        }

        $countryId = null;
        if (!empty($value['country'])) {
            $countryId = CrmCountry::where('code', $value['country'])->value('id');
        }

        $hasPrimary = $contact->postalAddresses()->where('is_primary', true)->exists();

        $contact->postalAddresses()->create([
            'street' => $value['street'],
            'house_number' => '',
            'postal_code' => $value['zip'] ?? null,
            'city' => $value['city'],
            'country_id' => $countryId,
            'is_primary' => !$hasPrimary,
            'is_active' => true,
        ]);
    }

    private function syncDateToCrmContact($contact, $definition, $value): void
    {
        $syncTarget = $definition->options['crm_sync_target'] ?? null;
        if ($syncTarget !== 'birth_date') {
            return;
        }

        $date = is_string($value) ? $value : null;
        if (!$date) {
            return;
        }

        $contact->birth_date = $date;
        $contact->save();
    }

    public function syncCrmContactToExtraFields(): void
    {
        $contact = $this->getContact();
        if (!$contact) {
            return;
        }

        $definitions = $this->getExtraFieldDefinitions();
        if ($definitions->isEmpty()) {
            return;
        }

        $existingValues = $this->extraFieldValues()->pluck('value', 'definition_id');
        $morphClass = $this->getMorphClass();

        foreach ($definitions as $def) {
            // Skip if value already exists
            $current = $existingValues[$def->id] ?? null;
            if ($current !== null && $current !== '' && $current !== '[]') {
                continue;
            }

            match ($def->type) {
                'phone' => $this->syncCrmPhoneToExtraField($contact, $def, $morphClass),
                'email' => $this->syncCrmEmailToExtraField($contact, $def, $morphClass),
                'address' => $this->syncCrmAddressToExtraField($contact, $def, $morphClass),
                'date' => $this->syncCrmDateToExtraField($contact, $def, $morphClass),
                default => null,
            };
        }
    }

    private function syncCrmPhoneToExtraField($contact, $def, string $morphClass): void
    {
        $phone = $contact->phoneNumbers()->where('is_active', true)
            ->orderByDesc('is_primary')->first();
        if (!$phone || !$phone->international) {
            return;
        }

        $value = new \Platform\Core\Models\CoreExtraFieldValue([
            'definition_id' => $def->id,
            'fieldable_type' => $morphClass,
            'fieldable_id' => $this->id,
        ]);
        $value->definition = $def;
        $value->setTypedValue([
            'raw' => $phone->raw_input ?: $phone->international,
            'country' => $phone->country_code ?: 'DE',
            'e164' => preg_replace('/[^+0-9]/', '', $phone->international),
            'international' => $phone->international,
        ]);
        $value->save();
    }

    private function syncCrmEmailToExtraField($contact, $def, string $morphClass): void
    {
        $email = $contact->emailAddresses()->where('is_active', true)
            ->orderByDesc('is_primary')->first();
        if (!$email || !$email->email_address) {
            return;
        }

        $value = new \Platform\Core\Models\CoreExtraFieldValue([
            'definition_id' => $def->id,
            'fieldable_type' => $morphClass,
            'fieldable_id' => $this->id,
        ]);
        $value->definition = $def;
        $value->setTypedValue($email->email_address);
        $value->save();
    }

    private function syncCrmAddressToExtraField($contact, $def, string $morphClass): void
    {
        $address = $contact->postalAddresses()->where('is_active', true)
            ->orderByDesc('is_primary')->first();
        if (!$address || (!$address->street && !$address->city)) {
            return;
        }

        $countryCode = null;
        if ($address->country_id) {
            $countryCode = CrmCountry::where('id', $address->country_id)->value('code');
        }

        $value = new \Platform\Core\Models\CoreExtraFieldValue([
            'definition_id' => $def->id,
            'fieldable_type' => $morphClass,
            'fieldable_id' => $this->id,
        ]);
        $value->definition = $def;
        $value->setTypedValue([
            'street' => $address->street ?: '',
            'street2' => $address->house_number ?: '',
            'zip' => $address->postal_code ?: '',
            'city' => $address->city ?: '',
            'state' => '',
            'country' => $countryCode ?: '',
        ]);
        $value->save();
    }

    private function syncCrmDateToExtraField($contact, $def, string $morphClass): void
    {
        $syncTarget = $def->options['crm_sync_target'] ?? null;
        if ($syncTarget !== 'birth_date') {
            return;
        }

        if (!$contact->birth_date) {
            return;
        }

        $value = new \Platform\Core\Models\CoreExtraFieldValue([
            'definition_id' => $def->id,
            'fieldable_type' => $morphClass,
            'fieldable_id' => $this->id,
        ]);
        $value->definition = $def;
        $value->setTypedValue($contact->birth_date->format('Y-m-d'));
        $value->save();
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
