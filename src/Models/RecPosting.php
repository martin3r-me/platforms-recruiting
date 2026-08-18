<?php

namespace Platform\Recruiting\Models;

use Illuminate\Database\Eloquent\Model;
use Symfony\Component\Uid\UuidV7;

class RecPosting extends Model
{
    protected $table = 'rec_postings';

    protected $fillable = [
        'uuid', 'rec_position_id', 'team_id', 'title', 'description', 'activity',
        'status', 'published_at', 'closes_at', 'is_active', 'created_by_user_id',
        'bedarf', 'bewerbungs_faktor',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'published_at' => 'datetime',
        'closes_at' => 'datetime',
        'bedarf' => 'integer',
        'bewerbungs_faktor' => 'float',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $model) {
            if (empty($model->uuid)) {
                do {
                    $uuid = UuidV7::generate();
                } while (self::where('uuid', $uuid)->exists());
                $model->uuid = $uuid;
            }
        });

        // Jede neue Ausschreibung bekommt sofort einen RG-Code — Grundlage
        // für die deterministische Zuordnung (Matching Stufe 1) statt LLM.
        static::created(function (self $model) {
            app(\Platform\Recruiting\Services\PostingRefCodeService::class)->ensure($model);
        });
    }

    /**
     * KLEINSTE SINNVOLLE WERTE der beiden Ziel-Felder — eine Definition fuer das
     * Model UND fuer die Validierungsregeln des Formulars (Posting\Show::rules).
     * Lagen sie getrennt, waere die Regel irgendwann strenger oder laxer als das
     * Feld, und genau daran haengt hier ein Formular, das sich nicht speichern
     * laesst.
     */
    public const BEDARF_MIN = 1;
    public const FAKTOR_MIN = 0.1;

    /**
     * „NICHT GEPFLEGT" ist EIN Zustand, und er heisst NULL — am Feld, nicht in
     * fuenf Lesern.
     *
     * Was hier alles als „nicht gepflegt" ankommt: null, '' und reine Leerzeichen
     * (ein per wire:model geleertes Formularfeld) UND die 0. Die 0 ist der Teil,
     * der sich mit Task 10 geaendert hat, und zwar in beide Richtungen (Setter und
     * Getter), weil sie sonst nur halb verschwindet:
     *
     *  - FACHLICH gibt es keinen Bedarf 0. Alle Leser der Statistik behandeln ihn
     *    laengst als nicht gepflegt (graue Ampel, „–", nicht im Nenner der Quote) —
     *    eine speicherbare 0 war damit ein Wert, der sich wie „leer" verhaelt, aber
     *    wie eine Angabe aussieht.
     *  - PRAKTISCH blockierte sie das Formular: mit `min:1` in den Regeln wirft
     *    save() auf `posting.bedarf`, und weil Livewire die Validierung fuer das
     *    ganze Formular macht, verwirft es dabei die Aenderung an einem voellig
     *    anderen Feld (gemessen: Titel geaendert, Titel weg, Fehler am Bedarf). Der
     *    Getter raeumt deshalb auch den BESTAND auf, den es schon gibt.
     *
     * Der Getter uebernimmt zugleich die Aufgabe des `integer`-Casts: sobald ein
     * Get-Mutator existiert, wendet Eloquent den Cast NICHT mehr an (siehe
     * transformModelValue) — die Umwandlung passiert hier von Hand.
     *
     * ACHTUNG, frueher stand hier das Gegenteil („eine bewusste 0 bleibt 0"). Das
     * war die Entscheidung von Task 5 und ist mit Task 10 aufgehoben: die Anzeige
     * hatte sie ohnehin nie als Angabe gelesen. Bitte nicht zurueckdrehen, ohne die
     * fuenf Leser mitzudrehen.
     */
    public function setBedarfAttribute($value): void
    {
        $this->attributes['bedarf'] = self::alsGepflegteZahl($value, (float) self::BEDARF_MIN);
    }

    public function getBedarfAttribute($value): ?int
    {
        $gepflegt = self::alsGepflegteZahl($value, (float) self::BEDARF_MIN);

        return $gepflegt === null ? null : (int) $gepflegt;
    }

    /**
     * Gleicher Bauplan wie beim Bedarf — mit der Schwelle des Faktors (0,1).
     *
     * Die Wirkung eines fehlenden Setters war hier immer schon gravierender: ''
     * wuerde ueber den float-Cast zu 0.0, das scheitert an min:0.1, und save()
     * bricht fuer das GESAMTE Formular ab (Titel, Status, Datum inklusive), sobald
     * der Faktor einmal gefuellt war und wieder geleert wird. Ein Bestandswert
     * unter der Schwelle (0, 0.05) hat dieselbe Wirkung — deshalb liest der Getter
     * ihn als nicht gepflegt statt ihn ins Formular zu tragen.
     */
    public function setBewerbungsFaktorAttribute($value): void
    {
        $this->attributes['bewerbungs_faktor'] = self::alsGepflegteZahl($value, self::FAKTOR_MIN);
    }

    public function getBewerbungsFaktorAttribute($value): ?float
    {
        $gepflegt = self::alsGepflegteZahl($value, self::FAKTOR_MIN);

        return $gepflegt === null ? null : (float) $gepflegt;
    }

    /**
     * Leer oder unterhalb der Schwelle → null, sonst der Wert unveraendert.
     *
     * Bewusst EINE Stelle fuer beide Felder und beide Richtungen (Setter/Getter):
     * vier Kopien derselben Bedingung waeren vier Stellen, an denen „nicht
     * gepflegt" verschieden ausfallen kann.
     */
    private static function alsGepflegteZahl(mixed $value, float $min): mixed
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        return ((float) $value) < $min ? null : $value;
    }

    /**
     * Taetigkeit: getrimmt, und leer heisst NULL — nicht ''.
     *
     * Der Anlege-Dialog normalisierte schon von Hand (`$this->activity ?: null`),
     * das Detail-Formular bindet dagegen direkt an das Attribut und wuerde beim
     * Leeren einen Leerstring schreiben. Damit gaebe es zwei Schreibweisen fuer
     * „nicht gepflegt" in derselben Spalte — und die Leser muessten beide kennen
     * (die Statistik prueft heute `whereNotNull` UND `!= ''`, genau deswegen).
     * Am Feld normalisiert gilt die Regel fuer jeden Schreibweg: beide Formulare,
     * MCP-Werkzeuge, Importe.
     *
     * Nur Setter, kein Getter: anders als beim Bedarf gibt es hier keinen
     * Bestandswert, der ein Formular blockieren koennte — die Regel ist
     * `nullable|string|max:60`, und ein '' aus dem Bestand besteht sie.
     */
    public function setActivityAttribute($value): void
    {
        $getrimmt = trim((string) ($value ?? ''));

        $this->attributes['activity'] = $getrimmt === '' ? null : $getrimmt;
    }

    public function position()
    {
        return $this->belongsTo(RecPosition::class, 'rec_position_id');
    }

    public function applicants()
    {
        return $this->belongsToMany(RecApplicant::class, 'rec_applicant_posting', 'rec_posting_id', 'rec_applicant_id')
            ->using(RecApplicantPosting::class)
            ->withPivot(['applied_at', 'notes', 'matched_via', 'match_confidence'])
            ->withTimestamps();
    }

    public function commsChannels()
    {
        return $this->belongsToMany(\Platform\Crm\Models\CommsChannel::class, 'rec_posting_comms_channel', 'rec_posting_id', 'comms_channel_id')
            ->using(RecPostingCommsChannel::class)
            ->withTimestamps();
    }

    public function team()
    {
        return $this->belongsTo(\Platform\Core\Models\Team::class, 'team_id');
    }

    public function createdByUser()
    {
        return $this->belongsTo(\Platform\Core\Models\User::class, 'created_by_user_id');
    }

    public function scopePublished($query)
    {
        return $query->where('status', 'published');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeOpen($query)
    {
        return $query->where('status', 'published')
            ->where('is_active', true)
            ->where(function ($q) {
                $q->whereNull('closes_at')
                    ->orWhere('closes_at', '>', now());
            });
    }

    /**
     * IST DIESE AUSSCHREIBUNG ONLINE? Veroeffentlicht UND aktiv — und sonst
     * nichts. „Geschlossen" ist das exakte Gegenteil davon.
     *
     * DIE EINE Definition fuer alle Leser (Statistik-Seite: Zeilen-Flag
     * `posting_closed`, Status-Filter, Kennzeichnung in der Auswahlliste). Sie lag
     * vorher als woertliche Kopie an zwei Stellen; zwei auseinanderdriftende
     * Begriffe von „geschlossen" waeren genau der Widerspruch, den die
     * Statistik-Seite abschafft.
     *
     * ABGRENZUNG zu scopeOpen(): dort zaehlt `closes_at` mit. Hier bewusst NICHT —
     * eine abgelaufene, aber noch veroeffentlichte Ausschreibung ist online
     * erreichbar, und genau so liest sie der Kunde. „Offen" (bewerbbar) und
     * „online" (sichtbar) sind zwei Fragen; wer die Laufzeit braucht, nimmt
     * scopeOpen.
     */
    public function isOnline(): bool
    {
        return $this->status === 'published' && (bool) $this->is_active;
    }

    public function externalRefs()
    {
        return $this->hasMany(RecPostingExternalRef::class, 'rec_posting_id');
    }

    public function scopeForTeam($query, $teamId)
    {
        return $query->where('team_id', $teamId);
    }
}
