<?php

namespace Platform\Recruiting\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Platform\Recruiting\Support\ProofTypes;
use Symfony\Component\Uid\UuidV7;

/**
 * Ein hochgeladener Nachweis — Ausweis, Immatrikulation, Aufenthaltstitel …
 *
 * Gelesen wird ueber `person_key`, geschrieben an `rec_employee_id`: Der
 * Upload haengt an der Anstellung, ueber die sich der Mensch angemeldet hat,
 * gilt aber fuer alle seine Anstellungen.
 *
 * Nichts wird geloescht. Eine neue Fassung setzt superseded_at auf der alten.
 */
class RecEmployeeProof extends Model
{
    protected $table = 'rec_employee_proofs';

    protected $fillable = [
        'uuid', 'team_id',
        'rec_employee_id', 'person_key', 'proof_type_code',
        'file_id', 'file_back_id',
        'valid_until', 'version', 'superseded_at', 'reminded_at',
        'confirmed_by_user_id', 'confirmed_at',
        'uploaded_via', 'uploaded_by_user_id',
    ];

    protected $casts = [
        'valid_until'   => 'date',
        'superseded_at' => 'datetime',
        'reminded_at'   => 'datetime',
        'confirmed_at'  => 'datetime',
        'version'       => 'integer',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $proof) {
            if (empty($proof->uuid)) {
                $proof->uuid = (string) UuidV7::generate();
            }
        });
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(RecEmployee::class, 'rec_employee_id');
    }

    public function confirmedByUser(): BelongsTo
    {
        return $this->belongsTo(\Platform\Core\Models\User::class, 'confirmed_by_user_id');
    }

    /** Nur die jeweils gueltige Fassung. */
    public function scopeAktuell(Builder $query): Builder
    {
        return $query->whereNull('superseded_at');
    }

    public function scopeVonArt(Builder $query, string $code): Builder
    {
        return $query->where('proof_type_code', $code);
    }

    /**
     * Nur die zwei Arten Aufenthaltstitel/Arbeitsgenehmigung, "zur Kenntnis"
     * fuer HR (KEINE Einsatzsperre — die gibt es fuer Mitarbeiter nicht,
     * siehe ProofTypes::needsHrConfirmation()), und nur solange noch niemand
     * bestaetigt hat (Altbestand vor Korrektur K3). Quelle der Arten ist
     * ausschliesslich ProofTypes::needsHrConfirmation() — keine zweite Liste,
     * die auseinanderlaufen kann.
     */
    public function scopeWartetAufBestaetigung(Builder $query): Builder
    {
        $arten = array_values(array_filter(ProofTypes::all(), fn (string $c) => ProofTypes::needsHrConfirmation($c)));

        return $query->whereIn('proof_type_code', $arten)->whereNull('confirmed_at');
    }

    public function label(): string
    {
        return ProofTypes::label($this->proof_type_code);
    }

    /** Abgelaufen? Arten ohne Gueltig-bis laufen nie ab. */
    public function isExpired(?\DateTimeInterface $stichtag = null): bool
    {
        if ($this->valid_until === null) {
            return false;
        }
        $stichtag ??= now();
        return $this->valid_until->startOfDay()->lt(\Illuminate\Support\Carbon::instance($stichtag)->startOfDay());
    }

    /**
     * Laeuft innerhalb der Vorlaufzeit seiner Art ab — und ist noch nicht
     * abgelaufen. Grundlage des Fristenlaufs.
     */
    public function isDueSoon(?\DateTimeInterface $stichtag = null): bool
    {
        $vorlauf = ProofTypes::leadDays($this->proof_type_code);
        if ($this->valid_until === null || $vorlauf === null) {
            return false;
        }
        $heute = \Illuminate\Support\Carbon::instance($stichtag ?? now())->startOfDay();

        return $this->valid_until->startOfDay()->gte($heute)
            && $this->valid_until->startOfDay()->lte($heute->copy()->addDays($vorlauf));
    }
}
