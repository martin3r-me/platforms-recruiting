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

    // phone, password_hash, registered_at und merged_into_person_id sind
    // bewusst NICHT hier (I4, Schlusspruefung — dieselbe Tuer, die Ruling
    // T3-D fuer rec_employees.rec_person_id schon geschlossen hat): sonst
    // waere "geschrieben wird ausschliesslich ueber PersonLinker" nur eine
    // Verabredung, und nichts hielte sie. Die vier Spalten tragen
    // Entscheidungen, die NEBENWIRKUNGEN haben, welche nur PersonLinker
    // kennt:
    //  - phone ist der spaetere Benutzername und muss auf ALLE Anstellungen
    //    mitwandern (setzeNummer). Ein RecPerson::find($id)->update(
    //    ['phone' => $neu]) uebergeht das still — der Einmalcode ginge dann
    //    an die alte Nummer, und der Mensch sperrt sich selbst aus.
    //  - password_hash und registered_at sind die Anmeldung; sie werden beim
    //    Stilllegen geraeumt und gehoeren Stufe 2, nicht einem
    //    Massen-Update.
    //  - merged_into_person_id ist das Stilllegen selbst — mit den vier
    //    Wachen aus fuehreZusammen(), sonst entsteht ein Ring, an dem sich
    //    niemand mehr anmelden kann.
    // PersonLinker schreibt per Query Builder und braucht $fillable nicht.
    protected $fillable = [
        'uuid', 'team_id', 'email', 'invited_at', 'locked_at',
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
