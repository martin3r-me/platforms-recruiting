<?php

namespace Platform\Recruiting\Livewire\Employees;

use Livewire\Attributes\Computed;
use Livewire\Component;
use Livewire\WithFileUploads;
use Platform\Core\Models\CoreLookup;
use Platform\Core\Models\ContextFile;
use Platform\Core\Services\ContextFileService;
use Platform\Recruiting\Models\RecEmployee;
use Platform\Recruiting\Models\RecPosition;

/**
 * HR-Backend Detail-Edit-View fuer einen RecEmployee.
 *
 * Anders als das MA-Portal: HR sieht UND editiert ALLE Felder, auch
 * die im Portal Login-stabilen (first_name, last_name, birth_date,
 * identity_card_number). Plus Lifecycle-Felder + Legal-Status +
 * (spaeter) HR-only-Felder aus rec_employee_hr_data.
 */
class Show extends Component
{
    use WithFileUploads;

    public int $employeeId;
    public array $fieldValues = [];
    public array $hrFieldValues = [];
    public ?string $flash = null;

    // File-Upload-Properties (separat, eine pro File-Field)
    public $uploadIdentityFront = null;
    public $uploadIdentityBack = null;
    public $uploadSelfie = null;
    public $uploadHealthInsuranceCard = null;
    public $uploadNationalpass = null;
    public $uploadAufenthaltstitelFront = null;
    public $uploadAufenthaltstitelBack = null;
    public $uploadVisumsblatt = null;
    public $uploadZusatzblatt = null;
    public $uploadZusatzblattBack = null;
    public $uploadImmatrikulation = null;
    public $uploadSchulbescheinigung = null;
    public $uploadFiktionFront = null;
    public $uploadFiktionBack = null;

    private const FILE_UPLOAD_MAP = [
        'identity_card_front_file_id'   => 'uploadIdentityFront',
        'identity_card_back_file_id'    => 'uploadIdentityBack',
        'selfie_file_id'                => 'uploadSelfie',
        'health_insurance_card_file_id' => 'uploadHealthInsuranceCard',
        'nationalpass_file_id'          => 'uploadNationalpass',
        'aufenthaltstitel_front_file_id' => 'uploadAufenthaltstitelFront',
        'aufenthaltstitel_back_file_id'  => 'uploadAufenthaltstitelBack',
        'visumsblatt_file_id'           => 'uploadVisumsblatt',
        'zusatzblatt_file_id'           => 'uploadZusatzblatt',
        'zusatzblatt_back_file_id'      => 'uploadZusatzblattBack',
        'immatrikulation_file_id'       => 'uploadImmatrikulation',
        'schulbescheinigung_file_id'    => 'uploadSchulbescheinigung',
        'fiktionsbescheinigung_front_file_id' => 'uploadFiktionFront',
        'fiktionsbescheinigung_back_file_id'  => 'uploadFiktionBack',
    ];

    public function mount(int $employee): void
    {
        $this->employeeId = $employee;
        $emp = $this->employee();
        if ($emp) {
            $this->loadFieldValues($emp);
        }
    }

    #[Computed]
    public function employee(): ?RecEmployee
    {
        return RecEmployee::with(['position', 'applicant'])
            ->where('team_id', auth()->user()->currentTeam->id)
            ->find($this->employeeId);
    }

    /**
     * Signed contracts of the linked applicant (PDF-Download fuer HR im
     * Backend). Identische Logik wie EmployeePortal::contracts() — wir
     * nutzen den Applicant-Token, weil der ContractPdfController via
     * CorePublicFormLink validiert.
     */
    #[Computed]
    public function signedContracts(): array
    {
        $emp = $this->employee();
        if (!$emp?->applicant) {
            return [];
        }
        $applicantToken = $emp->applicant->getOrCreatePublicFormLink()->token;
        return $emp->applicant->contracts
            ->filter(fn ($c) => $c->status === 'completed' && $c->signed_at)
            ->map(function ($c) use ($applicantToken) {
                $code = $c->contractTemplate?->code;
                $displayName = match (true) {
                    $code !== null && str_starts_with($code, 'AV-') => 'Arbeitsvertrag (' . $code . ')',
                    $code === 'IFSG'                                => 'Infektionsschutzgesetz',
                    $code !== null && str_starts_with($code, 'AT-') => 'Zusatzvereinbarung (' . $code . ')',
                    default                                         => $c->contractTemplate?->name ?? 'Vertrag',
                };
                return [
                    'id'           => $c->id,
                    'display_name' => $displayName,
                    'signed_at'    => $c->signed_at,
                    'pdf_url'      => route('recruiting.public.contract-pdf', ['token' => $applicantToken, 'contractId' => $c->id]),
                ];
            })
            ->values()
            ->toArray();
    }

    #[Computed]
    public function positions()
    {
        return RecPosition::forTeam(auth()->user()->currentTeam->id)
            ->orderBy('title')
            ->get(['id', 'title']);
    }

    /**
     * Field-Definition aller editierbaren MA-Felder im HR-Backend.
     * Erweitert die MA-Portal-Sicht um Identity- + Lifecycle- +
     * Legal-Status-Felder die im Portal verboten sind.
     */
    public function fieldGroups(): array
    {
        // Non-EU-spezifische Felder werden nur bei is_eu_citizen=false
        // (oder null = unklar) angezeigt. Bei EU=true werden sie verborgen
        // damit die Ansicht uebersichtlicher bleibt.
        $emp = $this->employee();
        $showNonEuFields = ($emp?->is_eu_citizen !== true);

        $legalStatusFields = [
            'is_eu_citizen' => ['type' => 'bool', 'label' => 'EU-Buerger'],
        ];
        // Nationalpass (Reisepass aus Herkunftsland) ist semantisch ein
        // non-EU-Feld — EU-Buerger nutzen den Personalausweis (identity_card_*).
        if ($showNonEuFields) {
            $legalStatusFields['nationalpass_file_id']           = ['type' => 'file', 'label' => 'Nationalpass'];
            $legalStatusFields['aufenthaltstitel_front_file_id'] = ['type' => 'file', 'label' => 'Aufenthaltstitel Vorderseite'];
            $legalStatusFields['aufenthaltstitel_back_file_id']  = ['type' => 'file', 'label' => 'Aufenthaltstitel Rueckseite'];
            $legalStatusFields['visumsblatt_file_id']            = ['type' => 'file', 'label' => 'Visum'];
            $legalStatusFields['zusatzblatt_file_id']            = ['type' => 'file', 'label' => 'Zusatzblatt Vorderseite'];
            $legalStatusFields['zusatzblatt_back_file_id']       = ['type' => 'file', 'label' => 'Zusatzblatt Rueckseite'];
            $legalStatusFields['fiktionsbescheinigung_front_file_id'] = ['type' => 'file', 'label' => 'Fiktionsbescheinigung Vorderseite'];
            $legalStatusFields['fiktionsbescheinigung_back_file_id']  = ['type' => 'file', 'label' => 'Fiktionsbescheinigung Rueckseite'];
            $legalStatusFields['residence_permit_valid_until']   = ['type' => 'date', 'label' => 'Aufenthaltserlaubnis bis'];
            $legalStatusFields['work_permit_valid_until']        = ['type' => 'date', 'label' => 'Arbeitsgenehmigung bis'];
        }

        return [
            'Stammdaten' => [
                'first_name' => ['type' => 'text', 'label' => 'Vorname'],
                'last_name'  => ['type' => 'text', 'label' => 'Nachname'],
                'birth_name' => ['type' => 'text', 'label' => 'Geburtsname'],
                'birth_date' => ['type' => 'date', 'label' => 'Geburtsdatum'],
                'birth_place' => ['type' => 'text', 'label' => 'Geburtsort'],
                'birth_country' => ['type' => 'lookup', 'label' => 'Geburtsland', 'lookup' => 'geburtsland'],
                'gender' => ['type' => 'lookup', 'label' => 'Geschlecht', 'lookup' => 'geschlecht'],
                'marital_status' => ['type' => 'lookup', 'label' => 'Familienstand', 'lookup' => 'familienstand'],
            ],
            'Identifikation' => [
                'identity_card_number' => ['type' => 'text', 'label' => 'Ausweisnummer'],
                'identity_card_valid_until' => ['type' => 'date', 'label' => 'Ausweis gueltig bis'],
                'identity_card_front_file_id' => ['type' => 'file', 'label' => 'Ausweis Vorderseite'],
                'identity_card_back_file_id'  => ['type' => 'file', 'label' => 'Ausweis Rueckseite'],
                'selfie_file_id'              => ['type' => 'file', 'label' => 'Selfie'],
            ],
            'Kontakt' => [
                'email' => ['type' => 'text', 'label' => 'Email'],
                'phone' => ['type' => 'text', 'label' => 'Telefon'],
            ],
            'Adresse' => [
                'street' => ['type' => 'text', 'label' => 'Strasse'],
                'house_number' => ['type' => 'text', 'label' => 'Hausnummer'],
                'zip' => ['type' => 'text', 'label' => 'PLZ'],
                'city' => ['type' => 'text', 'label' => 'Ort'],
                'country_code' => ['type' => 'text', 'label' => 'Land'],
            ],
            'Persoenliches' => [
                'religion'           => ['type' => 'lookup', 'label' => 'Religion', 'lookup' => 'religion'],
                'number_of_children' => ['type' => 'text', 'label' => 'Anzahl Kinder'],
            ],
            'Stelle & Taetigkeit' => [
                'rec_position_id' => ['type' => 'position', 'label' => 'Stelle'],
                'beschaftigungsort' => ['type' => 'multi_lookup', 'label' => 'Beschaeftigungsort', 'lookup' => 'beschaeftigungsort'],
                'employment_type' => ['type' => 'lookup', 'label' => 'Ich bin (MA-Self-Deklaration)', 'lookup' => 'beschaeftigung_art'],
                'umfang_der_tatigkeit' => ['type' => 'lookup', 'label' => 'Umfang der Taetigkeit', 'lookup' => 'umfang_taetigkeit'],
            ],
            'Bankdaten' => [
                'iban' => ['type' => 'text', 'label' => 'IBAN'],
                'bic' => ['type' => 'text', 'label' => 'BIC'],
                'bank_institute' => ['type' => 'text', 'label' => 'Bank'],
                'account_holder' => ['type' => 'text', 'label' => 'Kontoinhaber'],
            ],
            'Steuer & Versicherung' => [
                'tax_class' => ['type' => 'inline_select', 'label' => 'Steuerklasse', 'options' => ['1','2','3','4','5','6']],
                'steuer_id' => ['type' => 'text', 'label' => 'Steuer-ID'],
                'sozialversicherungsnummer' => ['type' => 'text', 'label' => 'Sozialversicherungsnummer'],
                'health_insurance' => ['type' => 'lookup', 'label' => 'Krankenkasse', 'lookup' => 'krankenkasse'],
                'health_insurance_card_file_id' => ['type' => 'file', 'label' => 'Foto Versichertenkarte'],
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
                'pants_size' => ['type' => 'text', 'label' => 'Hosengroesse'],
                'shoe_size'  => ['type' => 'text', 'label' => 'Schuhgroesse'],
            ],
            'Legal-Status (EU/Non-EU)' => $legalStatusFields,
            'Sonstiges' => [
                'drivers_license_class' => ['type' => 'text', 'label' => 'Fuehrerschein-Klasse'],
                'has_car' => ['type' => 'bool', 'label' => 'PKW vorhanden'],
                'recruited_by_personnel_number' => ['type' => 'text', 'label' => 'Geworben von (Personalnummer)'],
            ],
            'Lifecycle' => [
                'is_active' => ['type' => 'bool', 'label' => 'Aktiv'],
                'employed_since' => ['type' => 'date', 'label' => 'Beschaeftigt seit'],
                'employment_ended_at' => ['type' => 'datetime', 'label' => 'Beschaeftigung beendet am'],
            ],
            // Liegt auf rec_employees (nicht hr_data), wird aber als HR-only
            // gerendert (gelb) — speist Lohn-Export + ZAS. MA-Portal sieht es NIE.
            'Personalnummer (HR-only, ZAS)' => [
                'personnel_number' => ['type' => 'text', 'label' => 'Personalnummer (ZAS)'],
            ],
        ];
    }

    /**
     * HR-only-Feldgruppen aus rec_employee_hr_data. Separate Entity,
     * MA-Portal sieht das NIE.
     */
    public function hrFieldGroups(): array
    {
        return [
            'Vertrags-Status (HR-only, ZAS-Export)' => [
                'export_status'        => ['type' => 'inline_select', 'label' => 'Status (immer GO)', 'options' => ['GO'], 'readonly' => true],
                'contract_sent_date'   => ['type' => 'date', 'label' => 'Vertrags-Datum (Snapshot)'],
                'contract_signed_at'   => ['type' => 'date', 'label' => 'Vertrag zurueck am'],
                'contract_end_date'    => ['type' => 'date', 'label' => 'Befristet bis'],
                'employment_classification' => ['type' => 'lookup', 'label' => 'Anstellungsart', 'lookup' => 'anstellungsart'],
            ],
            'Ausstattung' => [
                'linen_package_items' => ['type' => 'multi_lookup', 'label' => 'Waeschepaket erhalten', 'lookup' => 'waeschepaket'],
            ],
            'Bewertung & Qualifikation' => [
                'star_rating'    => ['type' => 'inline_select', 'label' => 'Sternebewertung', 'options' => ['1','2','3','4','5']],
                'qualifications' => ['type' => 'multi_lookup', 'label' => 'Qualifikation', 'lookup' => 'qualifikation'],
            ],
        ];
    }

    public function hrFieldsFlat(): array
    {
        $flat = [];
        foreach ($this->hrFieldGroups() as $section => $fields) {
            foreach ($fields as $key => $meta) {
                $flat[$key] = $meta;
            }
        }
        return $flat;
    }

    public function fieldsFlat(): array
    {
        $flat = [];
        foreach ($this->fieldGroups() as $section => $fields) {
            foreach ($fields as $key => $meta) {
                $flat[$key] = $meta;
            }
        }
        return $flat;
    }

    private function loadFieldValues(RecEmployee $employee): void
    {
        $values = [];
        foreach ($this->fieldsFlat() as $field => $meta) {
            $type = $meta['type'] ?? '';
            if ($type === 'file') {
                continue;
            }
            $raw = $employee->getAttribute($field);
            if ($type === 'multi_lookup') {
                $values[$field] = is_array($raw) ? $raw : [];
                continue;
            }
            if ($raw instanceof \DateTimeInterface) {
                $raw = $raw->format(($type === 'datetime') ? 'Y-m-d\TH:i' : 'Y-m-d');
            } elseif (is_bool($raw)) {
                $raw = $raw ? '1' : '0';
            }
            $values[$field] = $raw === null ? '' : (string) $raw;
        }
        $this->fieldValues = $values;

        // HR-Felder aus hrData laden (Lazy-Create wenn nicht vorhanden)
        $hrData = $employee->ensureHrData()->fresh();
        $hrValues = [];
        foreach ($this->hrFieldsFlat() as $field => $meta) {
            $raw = $hrData->getAttribute($field);
            $type = $meta['type'] ?? 'text';

            if ($type === 'multi_lookup') {
                $hrValues[$field] = is_array($raw) ? $raw : [];
                continue;
            }
            if ($raw instanceof \DateTimeInterface) {
                $raw = $raw->format('Y-m-d');
            } elseif (is_bool($raw)) {
                $raw = $raw ? '1' : '0';
            }
            $hrValues[$field] = $raw === null ? '' : (string) $raw;
        }
        $this->hrFieldValues = $hrValues;
    }

    public function saveAll(): void
    {
        $employee = $this->employee();
        if (!$employee) {
            return;
        }

        // rec_employees Updates
        $allowed = $this->fieldsFlat();
        $updates = [];
        foreach ($this->fieldValues as $field => $value) {
            if (!array_key_exists($field, $allowed)) {
                continue;
            }
            $meta = $allowed[$field];
            $type = $meta['type'];

            if ($type === 'multi_lookup') {
                $updates[$field] = (is_array($value) && !empty($value))
                    ? array_values(array_filter($value, fn ($v) => $v !== '' && $v !== null))
                    : null;
                continue;
            }

            $value = is_string($value) ? trim($value) : $value;

            if ($type === 'bool') {
                $updates[$field] = match ((string) $value) {
                    '1', 'true', 'ja' => true,
                    '0', 'false', 'nein' => false,
                    default => null,
                };
            } elseif ($type === 'position') {
                $updates[$field] = is_numeric($value) && (int) $value > 0 ? (int) $value : null;
            } else {
                $updates[$field] = ($value === '' || $value === null) ? null : $value;
            }
        }

        // rec_employee_hr_data Updates
        $hrAllowed = $this->hrFieldsFlat();
        $hrUpdates = [];
        foreach ($this->hrFieldValues as $field => $value) {
            if (!array_key_exists($field, $hrAllowed)) {
                continue;
            }
            $meta = $hrAllowed[$field];
            // readonly-Felder (z.B. export_status) nicht durchschleifen
            if (($meta['readonly'] ?? false) === true) {
                continue;
            }
            $type = $meta['type'] ?? 'text';
            if ($type === 'multi_lookup') {
                $hrUpdates[$field] = (is_array($value) && !empty($value))
                    ? array_values(array_filter($value, fn ($v) => $v !== '' && $v !== null))
                    : null;
                continue;
            }
            $value = is_string($value) ? trim($value) : $value;
            $hrUpdates[$field] = ($value === '' || $value === null) ? null : $value;
        }

        $changesCount = 0;
        if (!empty($updates)) {
            $employee->update($updates);
            $changesCount++;
        }
        if (!empty($hrUpdates)) {
            $employee->ensureHrData()->update($hrUpdates);
            $changesCount++;
        }

        if ($changesCount === 0) {
            $this->flash = 'Keine Aenderungen.';
            return;
        }

        $this->flash = 'Aenderungen gespeichert.';
        $this->loadFieldValues($employee->fresh());
        unset($this->employee);
    }

    // File-Upload Hooks
    public function updatedUploadIdentityFront(): void { $this->handleFileUpload('identity_card_front_file_id', 'uploadIdentityFront'); }
    public function updatedUploadIdentityBack(): void { $this->handleFileUpload('identity_card_back_file_id', 'uploadIdentityBack'); }
    public function updatedUploadSelfie(): void { $this->handleFileUpload('selfie_file_id', 'uploadSelfie'); }
    public function updatedUploadHealthInsuranceCard(): void { $this->handleFileUpload('health_insurance_card_file_id', 'uploadHealthInsuranceCard'); }
    public function updatedUploadNationalpass(): void { $this->handleFileUpload('nationalpass_file_id', 'uploadNationalpass'); }
    public function updatedUploadAufenthaltstitelFront(): void { $this->handleFileUpload('aufenthaltstitel_front_file_id', 'uploadAufenthaltstitelFront'); }
    public function updatedUploadAufenthaltstitelBack(): void { $this->handleFileUpload('aufenthaltstitel_back_file_id', 'uploadAufenthaltstitelBack'); }
    public function updatedUploadVisumsblatt(): void { $this->handleFileUpload('visumsblatt_file_id', 'uploadVisumsblatt'); }
    public function updatedUploadZusatzblatt(): void { $this->handleFileUpload('zusatzblatt_file_id', 'uploadZusatzblatt'); }
    public function updatedUploadZusatzblattBack(): void { $this->handleFileUpload('zusatzblatt_back_file_id', 'uploadZusatzblattBack'); }
    public function updatedUploadImmatrikulation(): void { $this->handleFileUpload('immatrikulation_file_id', 'uploadImmatrikulation'); }
    public function updatedUploadSchulbescheinigung(): void { $this->handleFileUpload('schulbescheinigung_file_id', 'uploadSchulbescheinigung'); }
    public function updatedUploadFiktionFront(): void { $this->handleFileUpload('fiktionsbescheinigung_front_file_id', 'uploadFiktionFront'); }
    public function updatedUploadFiktionBack(): void { $this->handleFileUpload('fiktionsbescheinigung_back_file_id', 'uploadFiktionBack'); }

    private function handleFileUpload(string $employeeField, string $propertyName): void
    {
        $employee = $this->employee();
        if (!$employee) {
            return;
        }
        $file = $this->{$propertyName};
        if (!$file) {
            return;
        }
        try {
            $result = app(ContextFileService::class)->uploadForContext(
                $file,
                'rec_employee',
                $employee->id,
                [
                    'team_id' => $employee->team_id,
                    'user_id' => auth()->id(),
                ]
            );
            $employee->update([$employeeField => (int) $result['id']]);
            $this->flash = "Datei hochgeladen.";
        } catch (\Throwable $e) {
            $this->flash = 'Upload-Fehler: ' . $e->getMessage();
        }
        $this->{$propertyName} = null;
        unset($this->employee);
    }

    public function uploadPropertyFor(string $fieldKey): ?string
    {
        return self::FILE_UPLOAD_MAP[$fieldKey] ?? null;
    }

    public function lookupOptionsFor(string $lookupName): array
    {
        $lookup = CoreLookup::where('name', $lookupName)->first();
        return $lookup ? $lookup->getOptionsArray() : [];
    }

    public function fileNameFor(?int $fileId): ?string
    {
        if (!$fileId) {
            return null;
        }
        return ContextFile::find($fileId)?->original_name;
    }

    public function render()
    {
        return view('recruiting::livewire.employees.show')
            ->layout('platform::layouts.app');
    }
}
