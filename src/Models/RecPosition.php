<?php

namespace Platform\Recruiting\Models;

use Illuminate\Database\Eloquent\Model;
use Platform\Core\Traits\HasExtraFields;
use Symfony\Component\Uid\UuidV7;

class RecPosition extends Model
{
    use HasExtraFields;

    protected $table = 'rec_positions';

    protected $fillable = [
        'uuid', 'team_id', 'title', 'description', 'department', 'location',
        'is_active', 'created_by_user_id', 'owned_by_user_id',
    ];

    protected $casts = [
        'is_active' => 'boolean',
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
}
