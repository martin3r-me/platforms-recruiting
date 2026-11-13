<?php

namespace Platform\Recruiting\Models;

use Illuminate\Database\Eloquent\Model;

/** Web-Push-Abo eines Geraets eines Mitarbeiters (Markus 13.11.2026). */
class RecPushAbo extends Model
{
    protected $table = 'rec_push_abos';
    protected $fillable = ['rec_employee_id', 'endpoint', 'endpoint_hash', 'p256dh', 'auth', 'user_agent', 'last_ok_at'];
    protected $casts = ['rec_employee_id' => 'integer', 'last_ok_at' => 'datetime'];

    public static function hash(string $endpoint): string
    {
        return hash('sha256', $endpoint);
    }
}
