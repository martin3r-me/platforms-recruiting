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
    public const WEG_WHATSAPP = 'whatsapp';
    public const WEG_PORTAL = 'portal';
    public const STATUS_ENTWURF = 'entwurf';
    public const STATUS_LAEUFT = 'laeuft';
    public const STATUS_ABGESCHLOSSEN = 'abgeschlossen';

    protected $table = 'rec_dispo_job_requests';
    protected $fillable = ['uuid', 'team_id', 'filial_nr', 'zeitraum_von', 'zeitraum_bis', 'modus', 'anlass', 'whatsapp_template_id', 'status', 'created_by_user_id', 'closed_at', 'welle_max', 'welle_abstand_minuten',
        'versandweg', 'freigabe_pflicht', 'freigabe_beantragt_at', 'freigabe_beantragt_by_user_id', 'freigegeben_at', 'freigegeben_by_user_id',
        'freigabe_abgelehnt_at', 'freigabe_abgelehnt_by_user_id', 'freigabe_ablehnungsgrund'];
    protected $casts = ['filial_nr' => 'integer', 'zeitraum_von' => 'date:Y-m-d', 'zeitraum_bis' => 'date:Y-m-d', 'whatsapp_template_id' => 'integer', 'closed_at' => 'datetime', 'welle_max' => 'integer', 'welle_abstand_minuten' => 'integer',
        'freigabe_pflicht' => 'boolean', 'freigabe_beantragt_at' => 'datetime', 'freigegeben_at' => 'datetime', 'freigabe_abgelehnt_at' => 'datetime',
        'freigabe_beantragt_by_user_id' => 'integer', 'freigegeben_by_user_id' => 'integer', 'freigabe_abgelehnt_by_user_id' => 'integer'];

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

    public function istPortal(): bool { return $this->versandweg === self::WEG_PORTAL; }

    /** Ohne Freigabe-Pflicht oder nach der Freigabe darf versendet werden. */
    public function darfSenden(): bool { return ! $this->freigabe_pflicht || $this->freigegeben_at !== null; }

    public function wartetAufFreigabe(): bool
    {
        return $this->freigabe_pflicht && $this->freigegeben_at === null && $this->freigabe_abgelehnt_at === null && $this->closed_at === null;
    }

    public function freigabeAbgelehnt(): bool
    {
        return $this->freigabe_pflicht && $this->freigegeben_at === null && $this->freigabe_abgelehnt_at !== null;
    }
}
