<?php

namespace Platform\Recruiting\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
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
        'birth_name',
        'birth_date',
        'birth_place',
        'birth_country',
        'identity_card_number',
        'identity_card_valid_until',
        'identity_card_front_file_id',
        'identity_card_back_file_id',
        'selfie_file_id',
        'email',
        'phone',

        'street',
        'house_number',
        'zip',
        'city',
        'country_code',

        'beschaftigungsort',
        'art_der_tatigkeit',
        'umfang_der_tatigkeit',
        'employment_type',

        'iban',
        'bic',
        'bank_institute',
        'steuer_id',
        'sozialversicherungsnummer',

        'gender',
        'marital_status',
        'health_insurance',
        'health_insurance_card_file_id',
        'drivers_license_class',
        'has_car',
        'recruited_by_personnel_number',
        'personnel_number',

        // Iteration 3 — vollstaendiges HR-Field-Set
        'tax_class',
        'number_of_children',
        'account_holder',
        'religion',
        'school_certificate_valid_until',
        'has_infection_protection_certificate',
        'infection_protection_first_issued_at',
        'shirt_size',
        'pants_size',
        'shoe_size',
        'residence_permit_valid_until',
        'work_permit_valid_until',

        'is_eu_citizen',
        'nationalpass_file_id',
        'aufenthaltstitel_front_file_id',
        'aufenthaltstitel_back_file_id',
        'visumsblatt_file_id',
        'zusatzblatt_file_id',
        'zusatzblatt_back_file_id',
        'immatrikulation_file_id',
        'schulbescheinigung_file_id',
        'fiktionsbescheinigung_front_file_id',
        'fiktionsbescheinigung_back_file_id',

        'portal_token',
        'portal_verified_at',

        'is_active',
        'employed_since',
        'employment_ended_at',

        // ZAS-Export-Marker
        'zas_initial_exported_at',
        'zas_changed_at',

        'created_by_user_id',
    ];

    protected $casts = [
        'birth_date'                => 'date',
        'identity_card_valid_until' => 'date',
        'is_eu_citizen'             => 'boolean',
        'is_active'                 => 'boolean',
        'has_car'                   => 'boolean',
        'employed_since'            => 'date',
        'employment_ended_at'       => 'datetime',
        'portal_verified_at'        => 'datetime',
        'art_der_tatigkeit'         => 'array',
        'beschaftigungsort'         => 'array',
        // Iteration 3
        'school_certificate_valid_until'        => 'date',
        'has_infection_protection_certificate'  => 'boolean',
        'infection_protection_first_issued_at'  => 'date',
        'residence_permit_valid_until'          => 'date',
        'work_permit_valid_until'               => 'date',
        'number_of_children'                    => 'integer',
        'pants_size'                            => 'integer',
        'shoe_size'                             => 'integer',
        // ZAS-Export-Marker
        'zas_initial_exported_at'               => 'datetime',
        'zas_changed_at'                        => 'datetime',
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
     * HR-only-Daten (separate Entity, 1:1). MA-Portal liest diese
     * Relation NIE — sie ist nur fuer's HR-Backend gedacht. Boolean-
     * Test ueber Authorization-Safety: $employee->toArray() leakt
     * kein HR-Feld weil sie eine Relation sind, kein Direkt-Attribut.
     */
    public function hrData(): HasOne
    {
        return $this->hasOne(RecEmployeeHrData::class, 'rec_employee_id');
    }

    /**
     * Lazy-Get der hrData-Row mit firstOrCreate-Fallback. Sicherstellung
     * dass die HR-Backend-View nie auf null-hrData stoesst.
     */
    public function ensureHrData(): RecEmployeeHrData
    {
        return $this->hrData()->firstOrCreate(
            ['rec_employee_id' => $this->id],
            ['team_id' => $this->team_id]
        );
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
     * Editierbare Feldgruppen fuer das MA-Portal. Strukturierte Pairs
     * [section_label => [field_key => [type, label, lookup?]]].
     *
     * Type-Codes:
     *  - text   → Text-Input
     *  - date   → Date-Picker
     *  - bool   → Ja/Nein-Select
     *  - lookup → Lookup-Dropdown (mit lookup-Name)
     *  - file   → File-Upload
     *
     * BEWUSST AUSGESCHLOSSEN — Login-stabile Felder:
     *  - first_name, last_name (Identitaet → HR-Aenderung in eigener UI)
     *  - birth_date (Login-Faktor 1)
     *  - identity_card_number (Login-Faktor 2 → Aenderung wuerde Aussperren)
     *  - is_eu_citizen, legal-status file_ids (Legal-Status → HR/Schreibtisch)
     *  - recruited_by_personnel_number (read-only, einmalig in P3 gesetzt)
     *  - zas_id (Backoffice-Feld)
     */
    public function editableFieldGroups(): array
    {
        $isNonEu = ($this->is_eu_citizen === false);

        $groups = [
            'Kontakt' => [
                'email' => ['type' => 'text', 'label' => 'Email'],
                'phone' => ['type' => 'text', 'label' => 'Telefon'],
            ],
            'Adresse' => [
                'street'       => ['type' => 'text', 'label' => 'Strasse'],
                'house_number' => ['type' => 'text', 'label' => 'Hausnummer'],
                'zip'          => ['type' => 'text', 'label' => 'PLZ'],
                'city'         => ['type' => 'text', 'label' => 'Ort'],
                'country_code' => ['type' => 'text', 'label' => 'Land'],
                'birth_country' => ['type' => 'lookup', 'label' => 'Geburtsland', 'lookup' => 'geburtsland'],
            ],
            'Persoenliches' => [
                'birth_name'         => ['type' => 'text', 'label' => 'Geburtsname'],
                'birth_place'        => ['type' => 'text', 'label' => 'Geburtsort'],
                'gender'             => ['type' => 'lookup', 'label' => 'Geschlecht', 'lookup' => 'geschlecht'],
                'marital_status'     => ['type' => 'lookup', 'label' => 'Familienstand', 'lookup' => 'familienstand'],
                'employment_type'    => ['type' => 'lookup', 'label' => 'Ich bin', 'lookup' => 'beschaeftigung_art'],
                'religion'           => ['type' => 'lookup', 'label' => 'Religion', 'lookup' => 'religion'],
                'number_of_children' => ['type' => 'text', 'label' => 'Anzahl Kinder'],
            ],
            'Bankdaten' => [
                'iban'           => ['type' => 'text', 'label' => 'IBAN'],
                'bic'            => ['type' => 'text', 'label' => 'BIC'],
                'bank_institute' => ['type' => 'text', 'label' => 'Bank'],
                'account_holder' => ['type' => 'text', 'label' => 'Kontoinhaber'],
            ],
            'Steuer & Versicherung' => [
                'tax_class'                     => ['type' => 'inline_select', 'label' => 'Steuerklasse', 'options' => ['1','2','3','4','5','6']],
                'steuer_id'                     => ['type' => 'text', 'label' => 'Steuer-ID'],
                'sozialversicherungsnummer'     => ['type' => 'text', 'label' => 'Sozialversicherungsnummer'],
                'health_insurance'              => ['type' => 'lookup', 'label' => 'Krankenkasse', 'lookup' => 'krankenkasse'],
                'health_insurance_card_file_id' => ['type' => 'file', 'label' => 'Foto Versichertenkarte'],
            ],
            'Ausweis' => [
                'identity_card_valid_until'   => ['type' => 'date', 'label' => 'Ausweis gueltig bis'],
                'identity_card_front_file_id' => ['type' => 'file', 'label' => 'Ausweis Vorderseite'],
                'identity_card_back_file_id'  => ['type' => 'file', 'label' => 'Ausweis Rueckseite'],
                'selfie_file_id'              => ['type' => 'file', 'label' => 'Selfie'],
            ],
            'Schul-/Immatrikulationsbescheinigung' => [
                'immatrikulation_file_id'         => ['type' => 'file', 'label' => 'Immatrikulationsbescheinigung'],
                'schulbescheinigung_file_id'      => ['type' => 'file', 'label' => 'Schulbescheinigung'],
                'school_certificate_valid_until'  => ['type' => 'date', 'label' => 'Gueltig bis'],
            ],
            'Gesundheit' => [
                'has_infection_protection_certificate' => ['type' => 'bool', 'label' => 'Infektionsschutzbescheinigung vorhanden?'],
                'infection_protection_first_issued_at' => ['type' => 'date', 'label' => 'Erstbescheinigung am'],
            ],
            'Arbeitskleidung' => [
                'shirt_size' => ['type' => 'inline_select', 'label' => 'Hemd / Bluse', 'options' => ['S','M','L','XL']],
                'pants_size' => ['type' => 'text', 'label' => 'Hosengroesse (Zahl)'],
                'shoe_size'  => ['type' => 'text', 'label' => 'Schuhgroesse (Zahl)'],
            ],
            'Sonstiges' => [
                'drivers_license_class' => ['type' => 'text', 'label' => 'Fuehrerschein-Klasse'],
                'has_car'               => ['type' => 'bool', 'label' => 'PKW vorhanden'],
            ],
        ];

        // Non-EU-Sektion nur bei is_eu_citizen=false aufgenommen
        if ($isNonEu) {
            $groups['Aufenthalt (Non-EU)'] = [
                'residence_permit_valid_until' => ['type' => 'date', 'label' => 'Aufenthaltserlaubnis bis'],
                'work_permit_valid_until'      => ['type' => 'date', 'label' => 'Arbeitsgenehmigung bis'],
            ];
        }

        return $groups;
    }

    /**
     * Flat-Liste aller editierbaren Felder [field_key => meta-array] —
     * fuer Whitelist-Checks im saveField()-Pfad und fuer's Render.
     */
    public function editableFieldsFlat(): array
    {
        $flat = [];
        foreach ($this->editableFieldGroups() as $group => $fields) {
            foreach ($fields as $key => $meta) {
                $flat[$key] = $meta;
            }
        }
        return $flat;
    }

    /**
     * Liste der Felder die aktuell leer sind — fuer "fehlt noch"-Highlight
     * im Portal-Dashboard. Filtert auf editierbare Felder.
     */
    public function missingFields(): array
    {
        $missing = [];
        foreach ($this->editableFieldsFlat() as $field => $meta) {
            $value = $this->getAttribute($field);
            if ($value === null || $value === '' || $value === []) {
                $missing[$field] = $meta['label'];
            }
        }
        return $missing;
    }

    /**
     * Read-only-Felder die im Portal angezeigt (nicht editiert) werden
     * koennen. Returnt nur Felder mit Wert — leere werden ausgeblendet.
     *
     * Felder die HIER stehen sind im Portal sichtbar aber nicht editierbar:
     *  - identity_card_number: Login-Faktor 2 → Aenderung wuerde MA aussperren
     *  - recruited_by_personnel_number: einmalig bei Bewerbung gesetzt
     */
    public function readOnlyDisplayFields(): array
    {
        $candidates = [
            'identity_card_number'           => 'Ausweisnummer',
            'recruited_by_personnel_number'  => 'Geworben von (Personalnummer)',
        ];
        $out = [];
        foreach ($candidates as $field => $label) {
            $value = $this->getAttribute($field);
            if ($value !== null && $value !== '') {
                $out[$field] = ['label' => $label, 'value' => $value];
            }
        }
        return $out;
    }

    /**
     * Schickt das WhatsApp-"Mitarbeiter-Portal aktivieren"-Template an
     * den frisch angelegten Mitarbeiter — analog zu
     * RecApplicant::sendContractPortalNotification aber:
     *
     *  - Nutzt eigene Settings (employee_portal_wa_template_id +
     *    employee_portal_wa_account_id) damit HR eigenes Wording
     *    konfigurieren kann ("Willkommen im Team — ...")
     *  - Uebergibt den portal_token als URL-Button-Parameter, der zur
     *    Mitarbeiter-Portal-Route /mitarbeiter/{token} fuehrt
     *  - Telefonnummer kommt aus crmContactLinks-Mirror, die der
     *    CreateEmployeeFromApplicantService beim MA-Anlegen dupliziert hat
     *
     * Wird automatisch aufgerufen am Ende von
     * CreateEmployeeFromApplicantService::createOrUpdate(). Idempotenz:
     * wird beim Service nur einmal pro Anlage getriggert (Service ist
     * selber idempotent), HR kann via Re-Send-Button im UI nachsenden.
     *
     * @return array{ok: bool, message: ?string}
     */
    public function sendPortalNotification(): array
    {
        try {
            $this->loadMissing(['crmContactLinks.contact.phoneNumbers', 'applicant.contracts.contractTemplate']);

            $teamSettings = \Platform\Recruiting\Models\RecApplicantSettings::getOrCreateForTeam($this->team_id);
            $templateId = $teamSettings->getSetting('employee_portal_wa_template_id');
            $accountId  = $teamSettings->getSetting('employee_portal_wa_account_id');

            if (!$templateId) {
                return ['ok' => false, 'message' => 'Kein employee_portal_wa_template_id-Setting konfiguriert.'];
            }

            if (!class_exists(\Platform\Integrations\Models\IntegrationsWhatsAppTemplate::class)) {
                return ['ok' => false, 'message' => 'WhatsApp-Integrations-Modul nicht verfuegbar.'];
            }

            $template = \Platform\Integrations\Models\IntegrationsWhatsAppTemplate::find($templateId);
            if (!$template || $template->status !== 'APPROVED') {
                return ['ok' => false, 'message' => 'Template nicht gefunden oder nicht genehmigt.'];
            }

            if (!$accountId) {
                $accountId = $template->whatsapp_account_id;
            }

            if (!$accountId || !class_exists(\Platform\Integrations\Models\IntegrationsWhatsAppAccount::class)) {
                return ['ok' => false, 'message' => 'Kein WhatsApp-Account konfiguriert.'];
            }

            $account = \Platform\Integrations\Models\IntegrationsWhatsAppAccount::find($accountId);
            if (!$account || !$account->active) {
                return ['ok' => false, 'message' => 'WhatsApp-Account nicht aktiv.'];
            }

            $channel = \Platform\Crm\Models\CommsChannel::where('type', 'whatsapp')
                ->where('is_active', true)
                ->where('sender_identifier', $account->phone_number)
                ->first();

            if (!$channel) {
                return ['ok' => false, 'message' => 'Kein aktiver WhatsApp-Kanal fuer den Account.'];
            }

            $phoneNumber = null;
            foreach ($this->crmContactLinks as $link) {
                $contact = $link->contact;
                if (!$contact) continue;
                $phoneNumber = $contact->phoneNumbers
                    ->where('is_active', true)
                    ->where('is_primary', true)
                    ->whereNotNull('international')
                    ->first();
                if (!$phoneNumber) {
                    $phoneNumber = $contact->phoneNumbers
                        ->where('is_active', true)
                        ->whereNotNull('international')
                        ->first();
                }
                if ($phoneNumber) break;
            }

            if (!$phoneNumber) {
                return ['ok' => false, 'message' => 'Keine Telefonnummer am CRM-Kontakt.'];
            }

            $employeeName = trim(($this->first_name ?? '') . ' ' . ($this->last_name ?? '')) ?: 'Mitarbeiter/in';
            $portalUrl    = route('recruiting.public.employee-portal', ['token' => $this->portal_token]);

            $variableValues = [
                'employee_name' => $employeeName,
                'candidate_name' => $employeeName,
                'name'           => $employeeName,
                'vorname'        => $this->first_name ?? $employeeName,
                'portal_link'    => $portalUrl,
                'token'          => $this->portal_token,
            ];

            // Body-Param-Mapping — wir benutzen den gleichen Mechanismus
            // wie sendContractPortalNotification (positional + named).
            $autoMapDefaults = ['employee_name', 'portal_link'];
            $components = [];
            $bodyParams = [];
            foreach ($template->components ?? [] as $component) {
                if (($component['type'] ?? '') !== 'BODY') continue;
                preg_match_all('/\{\{(\w+)\}\}/', $component['text'] ?? '', $matches);
                $examplesByName = [];
                foreach ($component['example']['body_text_named_params'] ?? [] as $np) {
                    $examplesByName[$np['param_name']] = $np['example'] ?? '';
                }
                $positionalExamples = $component['example']['body_text'][0] ?? [];
                foreach ($matches[1] as $i => $paramName) {
                    $bodyParams[] = [
                        'name'    => $paramName,
                        'example' => $examplesByName[$paramName] ?? $positionalExamples[$i] ?? '',
                        'index'   => $i,
                    ];
                }
            }

            if (!empty($bodyParams)) {
                $bodyParameters = [];
                foreach ($bodyParams as $param) {
                    $sourceKey = $autoMapDefaults[$param['index']] ?? null;
                    $value = $sourceKey ? ($variableValues[$sourceKey] ?? '') : '';
                    if ($value === '') {
                        $value = $variableValues[strtolower($param['name'])] ?? $param['example'] ?? '';
                    }
                    $entry = ['type' => 'text', 'text' => (string) $value];
                    if (!is_numeric($param['name'])) {
                        $entry['parameter_name'] = $param['name'];
                    }
                    $bodyParameters[] = $entry;
                }
                $components[] = ['type' => 'body', 'parameters' => $bodyParameters];
            }

            $hasUrlButton = collect($template->components ?? [])
                ->where('type', 'BUTTONS')
                ->flatMap(fn ($c) => $c['buttons'] ?? [])
                ->contains('type', 'URL');

            if ($hasUrlButton) {
                $components[] = [
                    'type'       => 'button',
                    'sub_type'   => 'url',
                    'index'      => 0,
                    'parameters' => [['type' => 'text', 'text' => $this->portal_token]],
                ];
            }

            $service = app(\Platform\Crm\Services\Comms\WhatsAppMetaService::class);
            $message = $service->sendTemplate(
                channel:      $channel,
                to:           $phoneNumber->international,
                templateName: $template->name,
                components:   $components,
                languageCode: $template->language,
            );

            if ($thread = $message->thread ?? null) {
                $thread->addContext($this->getMorphClass(), $this->id, 'employee_portal_send');
            }

            // AutoPilot-Log am rec_applicant_id (RecAutoPilotLog FK).
            if ($this->rec_applicant_id) {
                try {
                    RecAutoPilotLog::create([
                        'rec_applicant_id' => $this->rec_applicant_id,
                        'type'             => 'employee_portal_sent',
                        'summary'          => "MA-Portal-Link per WhatsApp an {$phoneNumber->international} gesendet (Employee #{$this->id}).",
                    ]);
                } catch (\Throwable) {}
            }

            return ['ok' => true, 'message' => "An {$phoneNumber->international} gesendet."];
        } catch (\Throwable $e) {
            if ($this->rec_applicant_id) {
                try {
                    RecAutoPilotLog::create([
                        'rec_applicant_id' => $this->rec_applicant_id,
                        'type'             => 'error',
                        'summary'          => 'MA-Portal WA-Fehler: ' . $e->getMessage(),
                    ]);
                } catch (\Throwable) {}
            }
            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }
}
