<?php

namespace Platform\Recruiting\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Platform\Crm\Models\CrmContactLink;
use Symfony\Component\Uid\UuidV7;

/**
 * Mitarbeiter-Entitaet — wird beim Phase-4-Abschluss eines Bewerbers
 * angelegt (Trigger: completion_config['creates_employee_on_completion']).
 *
 * Felder werden bei Anlage 1:1 aus extra_field_values + crm_contact +
 * rec_applicant_legal_statuses gemappt durch CreateEmployeeFromApplicantService.
 * Optionale/leere Felder werden im MA-Portal /mitarbeiter/{token} nachgepflegt.
 *
 * Strikt opt-in via Phase-Config-Flag — Production-Stationen ohne den Flag
 * bleiben unberuehrt.
 */
class RecEmployee extends Model
{
    protected $table = 'rec_employees';

    protected $fillable = [
        'uuid',
        'team_id',
        'rec_applicant_id',
        'rec_position_id',
        'zas_id',

        'first_name',
        'last_name',
        'birth_date',
        'identity_card_number',
        'email',
        'phone',

        'street',
        'zip',
        'city',
        'country_code',

        'beschaftigungsort',
        'art_der_tatigkeit',

        'iban',
        'bic',
        'steuer_id',
        'sozialversicherungsnummer',

        'is_eu_citizen',
        'nationalpass_file_id',
        'aufenthaltstitel_front_file_id',
        'aufenthaltstitel_back_file_id',
        'visumsblatt_file_id',
        'zusatzblatt_file_id',
        'immatrikulation_file_id',

        'portal_token',
        'portal_verified_at',

        'is_active',
        'employed_since',
        'employment_ended_at',

        'created_by_user_id',
    ];

    protected $casts = [
        'birth_date'              => 'date',
        'is_eu_citizen'           => 'boolean',
        'is_active'               => 'boolean',
        'employed_since'          => 'date',
        'employment_ended_at'     => 'datetime',
        'portal_verified_at'      => 'datetime',
        'art_der_tatigkeit'       => 'array',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $model) {
            if (empty($model->uuid)) {
                $model->uuid = UuidV7::generate();
            }
            if (empty($model->portal_token)) {
                $model->portal_token = (string) UuidV7::generate();
            }
        });
    }

    public function applicant(): BelongsTo
    {
        return $this->belongsTo(RecApplicant::class, 'rec_applicant_id');
    }

    public function position(): BelongsTo
    {
        return $this->belongsTo(RecPosition::class, 'rec_position_id');
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(\Platform\Core\Models\Team::class, 'team_id');
    }

    /**
     * Polymorph-Link zu CRM-Contacts — analog RecApplicant. Erlaubt
     * derselbe CRM-Contact mit Bewerber-Row UND Mitarbeiter-Row zu
     * verbinden ohne Schema-Aenderung am CRM-Modul.
     */
    public function crmContactLinks(): MorphMany
    {
        return $this->morphMany(CrmContactLink::class, 'linkable');
    }

    /**
     * Pruefung des Portal-Logins. Erwartet:
     *  - $birthDate als YYYY-MM-DD-String
     *  - $idCardLast4 als 4-stellige Endung der Ausweisnummer
     * Liefert true wenn beide matchen + Employee is_active.
     */
    public function verifyPortalAccess(string $birthDate, string $idCardLast4): bool
    {
        if (!$this->is_active) {
            return false;
        }
        if (!$this->birth_date || !$this->identity_card_number) {
            return false;
        }
        if ($this->birth_date->format('Y-m-d') !== trim($birthDate)) {
            return false;
        }
        $expectedLast4 = substr(preg_replace('/\s+/', '', $this->identity_card_number), -4);
        return strcasecmp($expectedLast4, trim($idCardLast4)) === 0;
    }

    /**
     * Liste der wichtigsten Felder die noch leer sind — fuer den
     * "fehlt noch"-Indikator im Portal-Dashboard. Liefert Pairs
     * [field_key => human_label].
     */
    public function missingFields(): array
    {
        $checks = [
            'iban'                       => 'IBAN',
            'bic'                        => 'BIC',
            'steuer_id'                  => 'Steuer-ID',
            'sozialversicherungsnummer'  => 'Sozialversicherungsnummer',
            'street'                     => 'Strasse',
            'zip'                        => 'PLZ',
            'city'                       => 'Ort',
        ];

        $missing = [];
        foreach ($checks as $field => $label) {
            $value = $this->getAttribute($field);
            if ($value === null || $value === '') {
                $missing[$field] = $label;
            }
        }
        return $missing;
    }
}
