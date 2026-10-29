<?php

namespace Platform\Recruiting\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Ein Dispoposten aus der ZAS-Bedarfsliste ({Dispo2}): Bedarf fuer eine
 * Taetigkeit an einem Tag einer VA. `anzahl` = Plaetze des Postens gesamt,
 * `zeiten` = alle Zeitvarianten, von/bis = die frueheste. Spec 2026-10-29.
 */
class RecDispoBedarf extends Model
{
    public const STATUS_STORNO = 3;

    protected $table = 'rec_dispo_bedarfe';

    protected $fillable = [
        'rec_dispo_event_id', 'dispoposten_id', 'datum', 'von', 'bis', 'zeiten',
        'taetigkeit', 'taetigkeit_id', 'anzahl', 'status_id', 'last_seen_at', 'missing_since',
    ];

    protected $casts = [
        'datum'         => 'date:Y-m-d',
        'zeiten'        => 'array',
        'anzahl'        => 'integer',
        'status_id'     => 'integer',
        'last_seen_at'  => 'datetime',
        'missing_since' => 'datetime',
    ];

    public function event(): BelongsTo
    {
        return $this->belongsTo(RecDispoEvent::class, 'rec_dispo_event_id');
    }
}
