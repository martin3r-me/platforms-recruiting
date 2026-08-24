<?php

namespace Platform\Recruiting\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Platform\Recruiting\Support\Filialen;
use Symfony\Component\Uid\UuidV7;

/**
 * Veranstaltung aus dem ZAS-Webexport ({Dispo2}, gruppiert ueber Einsatz-ID).
 *
 * Entkopplungs-Leitplanke: Dispo-Klassen duerfen Recruiting kennen —
 * Recruiting darf NIE auf Dispo-Klassen zeigen (Einbahnstrasse, Spec).
 */
class RecDispoEvent extends Model
{
    protected $table = 'rec_dispo_events';

    protected $fillable = [
        'uuid', 'einsatz_ref', 'name', 'venue_text', 'ort', 'einsatzfirma', 'filiale', 'filial_nr',
        'starts_on', 'ends_on', 'anfahrt', 'dresscode',
        'vorlauf_minuten',
        'ansprechpartner',
        'alarm_message_id',
        'source_meta',
    ];

    protected $casts = [
        'starts_on'   => 'date:Y-m-d',
        'ends_on'     => 'date:Y-m-d',
        'filial_nr'   => 'integer',
        'vorlauf_minuten' => 'integer',
        'alarm_message_id' => 'integer',
        'source_meta' => 'array',
    ];

    /**
     * Anzeige-Label der Filiale: kanonisch aus der Nummer (zentrale Map),
     * Fallback auf den ZAS-Roh-Text, sonst null.
     */
    public function getFilialeLabelAttribute(): ?string
    {
        return Filialen::code($this->filial_nr) ?? ($this->filiale ?: null);
    }

    protected static function booted(): void
    {
        static::creating(function (self $model) {
            if (empty($model->uuid)) {
                $model->uuid = (string) UuidV7::generate();
            }
        });
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(RecDispoAssignment::class, 'rec_dispo_event_id');
    }
}
