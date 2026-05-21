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
    public $uploadImmatrikulation = null;

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
        'immatrikulation_file_id'       => 'uploadImmatrikulation',
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
            'Stelle & Taetigkeit' => [
                'rec_position_id' => ['type' => 'position', 'label' => 'Stelle'],
                'beschaftigungsort' => ['type' => 'text', 'label' => 'Beschaeftigungsort'],
                'employment_type' => ['type' => 'lookup', 'label' => 'Ich bin', 'lookup' => 'ich_bin'],
            ],
            'Bankdaten' => [
                'iban' => ['type' => 'text', 'label' => 'IBAN'],
                'bic' => ['type' => 'text', 'label' => 'BIC'],
                'bank_institute' => ['type' => 'text', 'label' => 'Geldinstitut'],
            ],
            'Steuer & Versicherung' => [
                'steuer_id' => ['type' => 'text', 'label' => 'Steuer-ID'],
                'sozialversicherungsnummer' => ['type' => 'text', 'label' => 'Sozialversicherungsnummer'],
                'health_insurance' => ['type' => 'lookup', 'label' => 'Krankenkasse', 'lookup' => 'krankenkasse'],
                'health_insurance_card_file_id' => ['type' => 'file', 'label' => 'Foto Versichertenkarte'],
            ],
            'Legal-Status (EU/Non-EU)' => [
                'is_eu_citizen' => ['type' => 'bool', 'label' => 'EU-Buerger'],
                'nationalpass_file_id' => ['type' => 'file', 'label' => 'Nationalpass'],
                'aufenthaltstitel_front_file_id' => ['type' => 'file', 'label' => 'Aufenthaltstitel Vorderseite'],
                'aufenthaltstitel_back_file_id' => ['type' => 'file', 'label' => 'Aufenthaltstitel Rueckseite'],
                'visumsblatt_file_id' => ['type' => 'file', 'label' => 'Visum'],
                'zusatzblatt_file_id' => ['type' => 'file', 'label' => 'Zusatzblatt'],
                'immatrikulation_file_id' => ['type' => 'file', 'label' => 'Immatrikulationsbescheinigung'],
            ],
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
        ];
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
            if (($meta['type'] ?? '') === 'file') {
                continue;
            }
            $raw = $employee->getAttribute($field);
            if ($raw instanceof \DateTimeInterface) {
                $raw = $raw->format(($meta['type'] === 'datetime') ? 'Y-m-d\TH:i' : 'Y-m-d');
            } elseif (is_bool($raw)) {
                $raw = $raw ? '1' : '0';
            }
            $values[$field] = $raw === null ? '' : (string) $raw;
        }
        $this->fieldValues = $values;
    }

    public function saveAll(): void
    {
        $employee = $this->employee();
        if (!$employee) {
            return;
        }

        $allowed = $this->fieldsFlat();
        $updates = [];
        foreach ($this->fieldValues as $field => $value) {
            if (!array_key_exists($field, $allowed)) {
                continue;
            }
            $meta = $allowed[$field];
            $type = $meta['type'];
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

        if (empty($updates)) {
            $this->flash = 'Keine Aenderungen.';
            return;
        }

        $employee->update($updates);
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
    public function updatedUploadImmatrikulation(): void { $this->handleFileUpload('immatrikulation_file_id', 'uploadImmatrikulation'); }

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
