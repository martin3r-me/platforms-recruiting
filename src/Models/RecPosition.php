<?php

namespace Platform\Recruiting\Models;

use Illuminate\Database\Eloquent\Model;
use Platform\Core\Traits\HasExtraFields;
use Platform\Hcm\Models\HcmJobTitle;
use Platform\Recruiting\Models\RecPhase;
use Symfony\Component\Uid\UuidV7;

class RecPosition extends Model
{
    use HasExtraFields;

    protected $table = 'rec_positions';

    protected $fillable = [
        'uuid', 'team_id', 'title', 'description', 'department', 'location',
        'beschaftigungsort_lookup_value', 'cost_center',
        'hcm_job_title_id', 'is_active', 'is_direct_hire', 'auto_pilot_settings', 'created_by_user_id', 'owned_by_user_id',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'is_direct_hire' => 'boolean',
        'auto_pilot_settings' => 'array',
        'cost_center' => 'integer',
    ];

    /**
     * Position-overridable AutoPilot setting keys.
     */
    const AUTO_PILOT_OVERRIDABLE_KEYS = [
        'auto_pilot_enabled',
        'auto_pilot_channel_priority',
        'auto_pilot_wa_account_id',
        'auto_pilot_wa_initial_template_id',
        'auto_pilot_wa_reminder_template_id',
        'auto_pilot_reminder_interval_hours',
        'auto_pilot_max_reminders',
        'auto_start_auto_pilot',
        'interview_booking_wa_template_id',
    ];

    /**
     * Get a single AutoPilot setting from position overrides, or null if not set.
     */
    public function getAutoPilotSetting(string $key, $default = null)
    {
        $settings = $this->auto_pilot_settings ?? [];
        return $settings[$key] ?? $default;
    }

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

        static::created(function (self $model) {
            if (!$model->phases()->exists()) {
                $model->phases()->create([
                    'team_id' => $model->team_id,
                    'name' => 'Bewerbung',
                    'order' => 1,
                    'is_active' => true,
                    'auto_advance' => true,
                ]);
            }
        });
    }

    public function jobTitle()
    {
        return $this->belongsTo(HcmJobTitle::class, 'hcm_job_title_id');
    }

    public function phases()
    {
        return $this->hasMany(RecPhase::class, 'rec_position_id')->orderBy('order');
    }

    public function firstPhase(): ?RecPhase
    {
        return $this->phases()->where('is_active', true)->orderBy('order')->first();
    }

    public function postings()
    {
        return $this->hasMany(RecPosting::class, 'rec_position_id');
    }

    public function activePostings()
    {
        return $this->postings()->where('is_active', true);
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

    public function applicantCount(): int
    {
        return RecApplicant::whereHas('postings', function ($q) {
            $q->where('rec_position_id', $this->id);
        })->count();
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeForTeam($query, $teamId)
    {
        return $query->where('team_id', $teamId);
    }

    public function scopeDirectHire($query)
    {
        return $query->where('is_direct_hire', true);
    }

    public function scopeNotDirectHire($query)
    {
        return $query->where('is_direct_hire', false);
    }
}
