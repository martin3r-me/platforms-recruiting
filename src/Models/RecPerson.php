<?php

namespace Platform\Recruiting\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Symfony\Component\Uid\UuidV7;

/**
 * Ein Mensch. Seine Anstellungen zeigen auf ihn, nicht umgekehrt.
 *
 * Was zur PERSON gehoert (Nummer, spaeter Stammdaten und Nachweise), steht
 * hier einmal. Was zur ANSTELLUNG gehoert (Personalnummer, Vertrag,
 * Einsaetze, Lohn), bleibt an rec_employees — es sind arbeitsrechtlich zwei
 * Arbeitgeber (Spec 2026-09-28, Paragraph 4).
 *
 * In Stufe 1 traegt diese Zeile nur die Klammer. Die Anmeldespalten sind
 * angelegt, aber leer; das Konto kommt in Stufe 2.
 *
 * Geschrieben wird sie ausschliesslich ueber PersonLinker.
 */
class RecPerson extends Model
{
    protected $table = 'rec_persons';

    protected $fillable = [
        'uuid', 'team_id', 'phone', 'password_hash', 'email',
        'invited_at', 'registered_at', 'locked_at', 'merged_into_person_id',
    ];

    protected $casts = [
        'invited_at'    => 'datetime',
        'registered_at' => 'datetime',
        'locked_at'     => 'datetime',
    ];

    protected $hidden = ['password_hash'];

    protected static function booted(): void
    {
        static::creating(function (self $person) {
            if (empty($person->uuid)) {
                $person->uuid = (string) UuidV7::generate();
            }
        });
    }

    public function employees(): HasMany
    {
        return $this->hasMany(RecEmployee::class, 'rec_person_id');
    }

    /**
     * Beim Zusammenlegen verloren — die Zeile bleibt stehen, damit ihre
     * Nummer gesperrt bleibt und nichts verloren geht.
     */
    public function istStillgelegt(): bool
    {
        return $this->merged_into_person_id !== null;
    }
}
