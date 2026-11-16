<?php

namespace Platform\Recruiting\Zeit;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Symfony\Component\Uid\UuidV7;

/** Ein Zeitdatensatz je Person und Bezug (Spec 2026-11-16 §2.1). */
class ZeitEintrag extends Model
{
    protected $table = 'rec_zeit_eintraege';

    protected $guarded = [];

    protected $casts = [
        'tag'           => 'date',
        'soll_von_at'   => 'datetime',
        'soll_bis_at'   => 'datetime',
        'kommen_at'     => 'datetime',
        'gehen_at'      => 'datetime',
        'gesperrt_at'   => 'datetime',
        'pause_minuten' => 'integer',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $model) {
            if (empty($model->uuid)) {
                $model->uuid = (string) UuidV7::generate();
            }
        });
    }

    /** Neueste zuerst; id statt created_at, weil mehrere Zeilen in derselben Sekunde entstehen. */
    public function verlauf(): HasMany
    {
        return $this->hasMany(ZeitVerlauf::class, 'rec_zeit_eintrag_id')->orderByDesc('id');
    }

    /** erledigt (gesperrt) | ausgecheckt (gehen) | eingecheckt (kommen) | offen */
    public function zustand(): string
    {
        if ($this->gesperrt_at !== null) {
            return 'erledigt';
        }
        if ($this->gehen_at !== null) {
            return 'ausgecheckt';
        }
        if ($this->kommen_at !== null) {
            return 'eingecheckt';
        }

        return 'offen';
    }

    public function istGesperrt(): bool
    {
        return $this->gesperrt_at !== null;
    }

    public static function finde(string $personTyp, int $personId, string $bezugTyp, int $bezugId): ?self
    {
        return static::query()
            ->where('person_typ', $personTyp)
            ->where('person_id', $personId)
            ->where('bezug_typ', $bezugTyp)
            ->where('bezug_id', $bezugId)
            ->first();
    }
}
