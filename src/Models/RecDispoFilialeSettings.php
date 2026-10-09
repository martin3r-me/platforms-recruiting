<?php
namespace Platform\Recruiting\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** Per-Filiale-Konfiguration (Versand-Kanal + Diensthandy), team-scoped. */
class RecDispoFilialeSettings extends Model
{
    protected $table = 'rec_dispo_filiale_settings';

    protected $fillable = ['team_id', 'filial_nr', 'comms_channel_id', 'duty_phone', 'decline_check_enabled_at'];

    protected $casts = [
        'team_id'          => 'integer',
        'filial_nr'        => 'integer',
        'comms_channel_id' => 'integer',
        'decline_check_enabled_at' => 'datetime',
    ];

    /**
     * Absage-Erkennung gilt nur am Kalendertag des Einschaltens (Kunde 09.10.:
     * der Haken wird sonst vergessen) — ab dem Einschalten bis 23:59, danach
     * ist sie ohne Zeitschaltung von selbst aus.
     */
    public static function isDeclineCheckActive(?\DateTimeInterface $enabledAt, \DateTimeInterface $at): bool
    {
        return $enabledAt !== null
            && $enabledAt <= $at
            && $enabledAt->format('Y-m-d') === $at->format('Y-m-d');
    }

    /**
     * Neuer Stempel beim Speichern der Filial-Zeile. Am selben Tag bleibt der
     * Start erhalten. Ist der Stempel abgelaufen und war die Checkbox schon
     * beim Laden gesetzt, ist das ein Haken von gestern auf einer ueber Nacht
     * offenen Seite — kein neues Einschalten, also aus. Nur ein frischer Klick
     * (beim Laden leer, jetzt gesetzt) schaltet neu ein.
     */
    public static function declineCheckStampOnSave(bool $checked, bool $checkedOnLoad, ?\DateTimeInterface $previous, \DateTimeInterface $now): ?\DateTimeInterface
    {
        if (!$checked) {
            return null;
        }
        if (self::isDeclineCheckActive($previous, $now)) {
            return $previous;
        }

        return $checkedOnLoad ? null : $now;
    }

    /** Filialen, deren Absage-Erkennung zum Zeitpunkt $at aktiv ist (Regel siehe isDeclineCheckActive). */
    public function scopeDeclineCheckActiveAt(Builder $query, \DateTimeInterface $at): Builder
    {
        $at = \Illuminate\Support\Carbon::instance($at);

        return $query->whereNotNull('decline_check_enabled_at')
            ->where('decline_check_enabled_at', '<=', $at)
            ->where('decline_check_enabled_at', '>=', $at->copy()->startOfDay());
    }
}
