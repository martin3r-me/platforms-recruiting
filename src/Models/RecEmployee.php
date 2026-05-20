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
     * Editierbare Feldgruppen fuer das MA-Portal. Strukturierte Pairs
     * [section_label => [field_key => human_label]]. Felder die HIER
     * fehlen sind nicht ueber's Portal editierbar.
     *
     * BEWUSST AUSGESCHLOSSEN — Login-stabile Felder:
     *  - first_name, last_name (Identitaet → HR muss aendern)
     *  - birth_date (Login-Faktor 1)
     *  - identity_card_number (Login-Faktor 2 → Aenderung wuerde
     *    Bewerber aussperren)
     *  - is_eu_citizen, alle file_ids (Legal-Status → HR/HR-Schreibtisch)
     *  - zas_id (Backoffice-Feld)
     */
    public function editableFieldGroups(): array
    {
        return [
            'Kontakt' => [
                'email' => 'Email',
                'phone' => 'Telefon',
            ],
            'Adresse' => [
                'street'       => 'Strasse',
                'zip'          => 'PLZ',
                'city'         => 'Ort',
                'country_code' => 'Land (z.B. DE)',
            ],
            'Bankdaten' => [
                'iban' => 'IBAN',
                'bic'  => 'BIC',
            ],
            'Steuer & Versicherung' => [
                'steuer_id'                 => 'Steuer-ID',
                'sozialversicherungsnummer' => 'Sozialversicherungsnummer',
            ],
        ];
    }

    /**
     * Flat-Liste aller editierbaren Felder [field_key => label] —
     * fuer Whitelist-Checks im saveField()-Pfad.
     */
    public function editableFieldsFlat(): array
    {
        $flat = [];
        foreach ($this->editableFieldGroups() as $group => $fields) {
            foreach ($fields as $key => $label) {
                $flat[$key] = $label;
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
        foreach ($this->editableFieldsFlat() as $field => $label) {
            $value = $this->getAttribute($field);
            if ($value === null || $value === '') {
                $missing[$field] = $label;
            }
        }
        return $missing;
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
