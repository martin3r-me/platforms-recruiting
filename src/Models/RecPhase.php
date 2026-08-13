<?php

namespace Platform\Recruiting\Models;

use Illuminate\Database\Eloquent\Model;
use Platform\Core\Traits\HasExtraFields;
use Symfony\Component\Uid\UuidV7;

class RecPhase extends Model
{
    use HasExtraFields;

    protected $table = 'rec_phases';

    protected $fillable = [
        'uuid', 'team_id', 'rec_position_id', 'name', 'order',
        'auto_pilot_settings', 'auto_advance', 'is_active',
        'completion_type', 'completion_config', 'show_in_dashboard',
        'allow_manual_booking',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'order' => 'integer',
        'auto_pilot_settings' => 'array',
        'auto_advance' => 'boolean',
        'completion_config' => 'array',
        'show_in_dashboard' => 'boolean',
        'allow_manual_booking' => 'boolean',
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

    public function position()
    {
        return $this->belongsTo(RecPosition::class, 'rec_position_id');
    }

    public function applicants()
    {
        return $this->hasMany(RecApplicant::class, 'rec_phase_id');
    }

    public function team()
    {
        return $this->belongsTo(\Platform\Core\Models\Team::class, 'team_id');
    }

    public function scopeForTeam($query, $teamId)
    {
        return $query->where('team_id', $teamId);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('order');
    }

    public function nextPhase(): ?self
    {
        return static::where('rec_position_id', $this->rec_position_id)
            ->where('order', '>', $this->order)
            ->where('is_active', true)
            ->orderBy('order')
            ->first();
    }

    public function isLastPhase(): bool
    {
        return $this->nextPhase() === null;
    }

    public function getAutoPilotSetting(string $key, $default = null)
    {
        $settings = $this->auto_pilot_settings ?? [];
        return $settings[$key] ?? $default;
    }
}
