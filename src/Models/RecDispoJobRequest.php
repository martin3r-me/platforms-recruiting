<?php

namespace Platform\Recruiting\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Symfony\Component\Uid\UuidV7;

/** Anfrage „Jobs anbieten“ (Spec 2026-10-12). */
class RecDispoJobRequest extends Model
{
    public const MODUS_SAMMELN = 'sammeln';
    public const MODUS_ZUERST = 'zuerst';
    public const STATUS_ENTWURF = 'entwurf';
    public const STATUS_LAEUFT = 'laeuft';
    public const STATUS_ABGESCHLOSSEN = 'abgeschlossen';

    protected $table = 'rec_dispo_job_requests';
    protected $fillable = ['uuid', 'team_id', 'filial_nr', 'zeitraum_von', 'zeitraum_bis', 'modus', 'anlass', 'whatsapp_template_id', 'status', 'created_by_user_id', 'closed_at'];
    protected $casts = ['filial_nr' => 'integer', 'zeitraum_von' => 'date:Y-m-d', 'zeitraum_bis' => 'date:Y-m-d', 'whatsapp_template_id' => 'integer', 'closed_at' => 'datetime'];

    protected static function booted(): void
    {
        static::creating(function (self $m) {
            if (empty($m->uuid)) {
                $m->uuid = (string) UuidV7::generate();
            }
        });
    }

    public function shifts(): HasMany { return $this->hasMany(RecDispoJobShift::class, 'request_id'); }
    public function waves(): HasMany { return $this->hasMany(RecDispoJobWave::class, 'request_id'); }
    public function offers(): HasMany { return $this->hasMany(RecDispoJobOffer::class, 'request_id'); }

    public function laeuft(): bool { return $this->status === self::STATUS_LAEUFT && $this->closed_at === null; }
}
