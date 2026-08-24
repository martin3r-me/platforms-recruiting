<?php
namespace Platform\Recruiting\Models;

use Illuminate\Database\Eloquent\Model;

/** Per-Filiale-Konfiguration (Versand-Kanal + Diensthandy), team-scoped. */
class RecDispoFilialeSettings extends Model
{
    protected $table = 'rec_dispo_filiale_settings';

    protected $fillable = ['team_id', 'filial_nr', 'comms_channel_id', 'duty_phone'];

    protected $casts = [
        'team_id'          => 'integer',
        'filial_nr'        => 'integer',
        'comms_channel_id' => 'integer',
    ];
}
