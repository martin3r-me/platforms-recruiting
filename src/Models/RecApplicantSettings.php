<?php

namespace Platform\Recruiting\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RecApplicantSettings extends Model
{
    protected $table = 'rec_applicant_settings';

    protected $fillable = [
        'team_id', 'settings',
    ];

    protected $casts = [
        'settings' => 'array',
    ];

    const DEFAULT_SETTINGS = [
        'use_informal_address' => false,
        'default_status_id' => null,
        'auto_assign_owner' => false,
        'default_contact_user_id' => null,
    ];

    public function team(): BelongsTo
    {
        return $this->belongsTo(\Platform\Core\Models\Team::class, 'team_id');
    }

    public function serviceHours(): HasMany
    {
        return $this->hasMany(RecServiceHours::class, 'rec_applicant_settings_id');
    }

    public static function getOrCreateForTeam(int $teamId): self
    {
        return static::firstOrCreate(
            ['team_id' => $teamId],
            ['settings' => self::DEFAULT_SETTINGS]
        );
    }

    public function getSetting(string $key, $default = null)
    {
        $settings = $this->settings ?? self::DEFAULT_SETTINGS;
        return $settings[$key] ?? $default ?? (self::DEFAULT_SETTINGS[$key] ?? null);
    }

    public function setSetting(string $key, $value): void
    {
        $settings = $this->settings ?? self::DEFAULT_SETTINGS;
        $settings[$key] = $value;
        $this->settings = $settings;
    }
}
