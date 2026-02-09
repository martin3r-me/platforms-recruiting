<?php

namespace Platform\Recruiting\Models;

use Illuminate\Database\Eloquent\Model;
use Symfony\Component\Uid\UuidV7;

class RecPosting extends Model
{
    protected $table = 'rec_postings';

    protected $fillable = [
        'uuid', 'rec_position_id', 'team_id', 'title', 'description',
        'status', 'published_at', 'closes_at', 'is_active', 'created_by_user_id',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'published_at' => 'datetime',
        'closes_at' => 'datetime',
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
        return $this->belongsToMany(RecApplicant::class, 'rec_applicant_posting', 'rec_posting_id', 'rec_applicant_id')
            ->using(RecApplicantPosting::class)
            ->withPivot(['applied_at', 'notes'])
            ->withTimestamps();
    }

    public function team()
    {
        return $this->belongsTo(\Platform\Core\Models\Team::class, 'team_id');
    }

    public function createdByUser()
    {
        return $this->belongsTo(\Platform\Core\Models\User::class, 'created_by_user_id');
    }

    public function scopePublished($query)
    {
        return $query->where('status', 'published');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeOpen($query)
    {
        return $query->where('status', 'published')
            ->where('is_active', true)
            ->where(function ($q) {
                $q->whereNull('closes_at')
                    ->orWhere('closes_at', '>', now());
            });
    }

    public function scopeForTeam($query, $teamId)
    {
        return $query->where('team_id', $teamId);
    }
}
