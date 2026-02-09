<?php

namespace Platform\Recruiting\Models;

use Illuminate\Database\Eloquent\Model;

class RecAutoPilotState extends Model
{
    protected $table = 'rec_auto_pilot_states';

    protected $fillable = [
        'uuid', 'code', 'name', 'description', 'is_active', 'team_id',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    protected static function boot()
    {
        parent::boot();
        static::creating(function ($model) {
            if (empty($model->uuid)) {
                $model->uuid = \Str::uuid();
            }
        });
    }
}
