<?php

namespace Platform\Recruiting\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Platform\Core\Models\CoreExtraFieldDefinition;
use Platform\Core\Traits\HasExtraFields;
use Platform\Recruiting\Traits\HasApplicantContact;
use Symfony\Component\Uid\UuidV7;

class RecApplicant extends Model
{
    use HasApplicantContact;
    use HasExtraFields;

    protected $table = 'rec_applicants';

    protected $fillable = [
        'uuid', 'public_token', 'rec_applicant_status_id', 'progress', 'notes', 'applied_at',
        'is_active', 'auto_pilot', 'auto_pilot_completed_at', 'auto_pilot_state_id',
        'team_id', 'created_by_user_id', 'owned_by_user_id',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'auto_pilot' => 'boolean',
        'auto_pilot_completed_at' => 'datetime',
        'auto_pilot_state_id' => 'integer',
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
        return url('/recruiting/a/' . $this->public_token);
    }

    public function getExtraFieldDefinitions(): Collection
    {
        $teamId = $this->getTeamIdForExtraFields();
        if (!$teamId) {
            return collect();
        }

        $positionIds = $this->postings()
            ->join('rec_positions', 'rec_positions.id', '=', 'rec_postings.rec_position_id')
            ->pluck('rec_positions.id')
            ->unique()->filter()->values();

        return CoreExtraFieldDefinition::query()
            ->forTeam($teamId)
            ->where('context_type', RecPosition::class)
            ->where(function ($q) use ($positionIds) {
                $q->whereNull('context_id');
                if ($positionIds->isNotEmpty()) {
                    $q->orWhereIn('context_id', $positionIds->toArray());
                }
            })
            ->orderBy('order')->orderBy('label')->get();
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
            if ($val !== null && $val->value !== null && $val->value !== '') {
                $filled++;
            }
        }

        return (int) round(($filled / $requiredDefinitions->count()) * 100);
    }
}
