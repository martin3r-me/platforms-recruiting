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
     * Leere Eingabe ('', null, reine Leerzeichen) wird schon beim SCHREIBEN
     * zu NULL, nicht erst beim Lesen.
     *
     * Grund: der `integer`-Cast in $casts wirkt nur beim LESEN
     * (getAttribute), nicht beim Schreiben. Ohne diesen Setter landet ein
     * per wire:model geleertes Formularfeld ('' auf dem Attribut) roh in
     * $attributes; Livewire liest den Wert vor dem Speichern zurueck, der
     * Cast macht daraus sofort int(0) — und 0 bedeutet fachlich "Ziel
     * erreicht mit null Personen", nicht "nicht gepflegt" (Spec: nichts
     * wird geraten, fehlt ein Wert, fehlt die Ampel). Eine bewusste "0" ist
     * KEINE leere Eingabe und bleibt 0. Bitte NICHT als Redundanz zum Cast
     * entfernen — der Cast kann das Schreiben nicht abdecken.
     */
    public function setBedarfAttribute($value): void
    {
        $this->attributes['bedarf'] = ($value === null || trim((string) $value) === '') ? null : $value;
    }

    /**
     * Gleicher Mechanismus wie setBedarfAttribute() — siehe dort fuer die
     * ausfuehrliche Begruendung (Cast wirkt nur beim Lesen).
     *
     * Hier ist die Wirkung eines fehlenden Setters gravierender: '' wuerde
     * ueber den `float`-Cast zu float(0.0), das scheitert an der
     * Validierungsregel min:0.1 — und weil `save()` bei fehlgeschlagener
     * Validierung komplett abbricht, liesse sich dann das GESAMTE Formular
     * nicht mehr speichern (Titel, Status, Datum inklusive), sobald der
     * Faktor einmal gefuellt war und wieder geleert wird.
     */
    public function setBewerbungsFaktorAttribute($value): void
    {
        $this->attributes['bewerbungs_faktor'] = ($value === null || trim((string) $value) === '') ? null : $value;
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
