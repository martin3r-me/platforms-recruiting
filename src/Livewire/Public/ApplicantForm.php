<?php

namespace Platform\Recruiting\Livewire\Public;

use Livewire\Attributes\Computed;
use Livewire\Component;
use Livewire\WithFileUploads;
use Platform\Core\Livewire\Concerns\WithExtraFields;
use Platform\Core\Models\ContextFile;
use Platform\Core\Models\CoreExtraFieldValue;
use Platform\Core\Services\ContextFileService;
use Platform\Recruiting\Models\RecApplicant;
use Platform\Recruiting\Models\RecApplicantLegalStatus;
use Platform\Recruiting\Services\SyncApplicantExtraFieldsToCrm;

class ApplicantForm extends Component
{
    use WithExtraFields;
    use WithFileUploads;

    public string $publicToken = '';
    public string $state = 'loading';
    public string $applicantName = '';

    public ?int $applicantId = null;

    public int $totalFields = 0;
    public int $filledFields = 0;

    public array $pendingFileUploads = [];
    public array $uploadedFileData = [];

    /** All field values (including already-filled) for condition evaluation */
    public array $allFieldValues = [];

    /** All field definitions (including already-filled) for condition evaluation */
    public array $allFieldDefinitions = [];

    /** Legal status fields */
    public ?bool $isEuCitizen = null;
    public bool $showLegalStatus = false;
    public bool $legalStatusAlreadyFilled = false;
    public array $legalDocumentUploads = [];
    public array $legalDocumentFileData = [];

    private function getApplicant(): ?RecApplicant
    {
        if (!$this->applicantId) {
            return null;
        }
        return RecApplicant::find($this->applicantId);
    }

    public function mount(string $publicToken): void
    {
        $this->publicToken = $publicToken;

        $applicant = RecApplicant::where('public_token', $publicToken)->first();

        if (!$applicant) {
            $this->state = 'notFound';
            return;
        }

        if (!$applicant->is_active) {
            $this->state = 'notActive';
            return;
        }

        $contact = $applicant->getContact();
        $this->applicantName = $contact->full_name ?? 'Bewerber';
        $this->applicantId = $applicant->id;
        $this->loadFormFields($applicant);
    }

    private function loadFormFields(RecApplicant $applicant): void
    {
        $this->loadExtraFieldValues($applicant);

        // Store ALL definitions and values for condition evaluation (before filtering)
        $this->allFieldDefinitions = $this->extraFieldDefinitions;
        $this->allFieldValues = $this->extraFieldValues;

        // Filter: only show unfilled fields
        $filtered = [];
        $this->totalFields = 0;
        $this->filledFields = 0;

        foreach ($this->extraFieldDefinitions as $field) {
            $this->totalFields++;
            $value = $this->extraFieldValues[$field['id']] ?? null;
            $isFilled = $value !== null && $value !== '' && $value !== [] && $value !== '[]';

            if ($isFilled) {
                $this->filledFields++;
            } else {
                $filtered[] = $field;
            }
        }

        // Overwrite definitions with only unfilled fields
        $this->extraFieldDefinitions = $filtered;

        // Reset values to only contain filtered field IDs
        $filteredValues = [];
        foreach ($filtered as $field) {
            $filteredValues[$field['id']] = $this->extraFieldValues[$field['id']] ?? null;
        }
        $this->extraFieldValues = $filteredValues;
        $this->originalExtraFieldValues = $filteredValues;

        $this->loadUploadedFileData();

        // Legal status
        $this->loadLegalStatusFields($applicant);

        if (empty($filtered) && (!$this->showLegalStatus || $this->legalStatusAlreadyFilled)) {
            $this->state = 'completed';
        } else {
            $this->state = 'form';
        }
    }

    private function loadLegalStatusFields(RecApplicant $applicant): void
    {
        $phases = $applicant->extraFieldParents();
        $this->showLegalStatus = collect($phases)->contains(
            fn($p) => $p->getAutoPilotSetting('collect_legal_status', false)
        );

        if (!$this->showLegalStatus) {
            return;
        }

        $legalStatus = $applicant->legalStatus;

        if ($legalStatus && $legalStatus->is_eu_citizen !== null) {
            $this->isEuCitizen = $legalStatus->is_eu_citizen;
            $this->legalStatusAlreadyFilled = true;
            $this->totalFields++;
            $this->filledFields++;
        } else {
            $this->totalFields++;
            $this->isEuCitizen = null;
        }

        // Load existing document file data
        if ($legalStatus) {
            $this->loadLegalDocumentFileData($legalStatus);
        }
    }

    private function loadLegalDocumentFileData(RecApplicantLegalStatus $legalStatus): void
    {
        $fileIds = [];
        foreach (RecApplicantLegalStatus::DOCUMENT_FIELDS as $field => $label) {
            $fileId = $legalStatus->$field;
            if ($fileId) {
                $fileIds[$field] = $fileId;
            }
        }

        if (empty($fileIds)) {
            $this->legalDocumentFileData = [];
            return;
        }

        $files = ContextFile::whereIn('id', array_values($fileIds))->with('variants')->get()->keyBy('id');
        $this->legalDocumentFileData = [];

        foreach ($fileIds as $field => $fileId) {
            $file = $files->get($fileId);
            if ($file) {
                $this->legalDocumentFileData[$field] = [
                    'id' => $file->id,
                    'original_name' => $file->original_name,
                    'file_size' => $file->file_size,
                    'mime_type' => $file->mime_type,
                    'is_image' => $file->isImage(),
                    'url' => $file->url,
                    'thumbnail_url' => $file->thumbnail?->url,
                ];
            }
        }
    }

    private function loadUploadedFileData(): void
    {
        $fileIds = [];
        foreach ($this->extraFieldDefinitions as $field) {
            if ($field['type'] !== 'file') continue;
            $val = $this->extraFieldValues[$field['id']] ?? null;
            if (is_array($val)) {
                $fileIds = array_merge($fileIds, $val);
            } elseif ($val) {
                $fileIds[] = $val;
            }
        }
        if (empty($fileIds)) {
            $this->uploadedFileData = [];
            return;
        }
        $files = ContextFile::whereIn('id', $fileIds)->with('variants')->get()->keyBy('id');
        $this->uploadedFileData = [];
        foreach ($files as $file) {
            $this->uploadedFileData[$file->id] = [
                'id' => $file->id,
                'original_name' => $file->original_name,
                'file_size' => $file->file_size,
                'mime_type' => $file->mime_type,
                'is_image' => $file->isImage(),
                'url' => $file->url,
                'thumbnail_url' => $file->thumbnail?->url,
            ];
        }
    }

    public function updatedPendingFileUploads(): void
    {
        $applicant = $this->getApplicant();
        if (!$applicant) return;

        $service = app(ContextFileService::class);

        foreach ($this->pendingFileUploads as $fieldId => $file) {
            if (!$file) continue;
            $field = collect($this->extraFieldDefinitions)->firstWhere('id', $fieldId);
            if (!$field || $field['type'] !== 'file') continue;

            $isMultiple = $field['options']['multiple'] ?? false;
            $files = is_array($file) ? $file : [$file];

            foreach ($files as $uploadedFile) {
                $result = $service->uploadForContext(
                    $uploadedFile,
                    get_class($applicant),
                    $applicant->id,
                    ['team_id' => $applicant->team_id, 'user_id' => null]
                );
                if ($isMultiple) {
                    $current = $this->extraFieldValues[$fieldId] ?? [];
                    $current = is_array($current) ? $current : [];
                    $current[] = $result['id'];
                    $this->extraFieldValues[$fieldId] = $current;
                } else {
                    $this->extraFieldValues[$fieldId] = $result['id'];
                }
            }
        }

        $this->pendingFileUploads = [];
        $this->loadUploadedFileData();
    }

    public function updatedLegalDocumentUploads(): void
    {
        $applicant = $this->getApplicant();
        if (!$applicant) return;

        $legalStatus = $applicant->legalStatus()->firstOrCreate([
            'team_id' => $applicant->team_id,
        ]);

        $service = app(ContextFileService::class);

        foreach ($this->legalDocumentUploads as $field => $file) {
            if (!$file) continue;
            if (!array_key_exists($field, RecApplicantLegalStatus::DOCUMENT_FIELDS)) continue;

            $result = $service->uploadForContext(
                $file,
                RecApplicantLegalStatus::class,
                $legalStatus->id,
                ['team_id' => $applicant->team_id, 'user_id' => null]
            );

            $legalStatus->$field = $result['id'];
            $legalStatus->save();
        }

        $this->legalDocumentUploads = [];
        $this->loadLegalDocumentFileData($legalStatus);
    }

    public function removeLegalDocument(string $field): void
    {
        if (!array_key_exists($field, RecApplicantLegalStatus::DOCUMENT_FIELDS)) return;

        $applicant = $this->getApplicant();
        if (!$applicant) return;

        $legalStatus = $applicant->legalStatus;
        if (!$legalStatus) return;

        $legalStatus->$field = null;
        $legalStatus->save();

        unset($this->legalDocumentFileData[$field]);
    }

    public function removeFile(int $fieldId, int $fileId): void
    {
        $current = $this->extraFieldValues[$fieldId] ?? null;
        if (is_array($current)) {
            $this->extraFieldValues[$fieldId] = array_values(array_filter($current, fn($id) => $id != $fileId));
        } else {
            $this->extraFieldValues[$fieldId] = null;
        }
        unset($this->uploadedFileData[$fileId]);
    }

    public function formatFileSize(int $bytes): string
    {
        if ($bytes >= 1048576) return round($bytes / 1048576, 1) . ' MB';
        if ($bytes >= 1024) return round($bytes / 1024, 0) . ' KB';
        return $bytes . ' B';
    }

    public function save(): void
    {
        $rules = $this->getExtraFieldValidationRules();
        $messages = $this->getExtraFieldValidationMessages();

        // Phase-Override: Felder die in der aktuellen Phase via
        // options.required_in_phase_ids als required gelten, kriegen die
        // 'required'-Validierung — auch wenn is_required=false ist.
        $applicant = $this->getApplicant();
        if ($applicant) {
            $currentPhaseId = $applicant->rec_phase_id;
            foreach ($this->extraFieldDefinitions as $def) {
                if (!is_array($def) || !empty($def['is_required'])) {
                    continue; // schon required, nichts zu tun
                }
                if (!$applicant->isFieldRequiredInCurrentPhase($def, $currentPhaseId)) {
                    continue;
                }
                $key = 'extraFieldValues.' . $def['id'];
                $existing = $rules[$key] ?? '';
                if (!str_contains($existing, 'required')) {
                    $rules[$key] = $existing === ''
                        ? 'required'
                        : 'required|' . $existing;
                }
                $messages[$key . '.required'] = 'Bitte ' . ($def['label'] ?? 'dieses Feld') . ' ausfüllen.';
            }
        }

        // Add legal status validation
        if ($this->showLegalStatus && !$this->legalStatusAlreadyFilled) {
            $rules['isEuCitizen'] = 'required|boolean';
            $messages['isEuCitizen.required'] = 'Bitte geben Sie an, ob Sie EU-Bürger sind.';
        }

        $this->validate($rules, $messages);

        $applicant = $this->getApplicant();
        if (!$applicant) {
            $this->state = 'notFound';
            return;
        }

        $this->saveExtraFieldValues($applicant);

        // Propagate applicant-form inputs (address, birth date) into canonical
        // CRM storage so contract templates and downstream consumers can read
        // them via contact.* mappings instead of the extra-field bucket.
        app(SyncApplicantExtraFieldsToCrm::class)->sync($applicant->fresh(['crmContactLinks.contact']));

        // Save legal status
        if ($this->showLegalStatus && !$this->legalStatusAlreadyFilled) {
            $legalStatus = $applicant->legalStatus()->firstOrCreate([
                'team_id' => $applicant->team_id,
            ]);
            $legalStatus->setEuCitizen($this->isEuCitizen);
        }

        // Sync form values into allFieldValues for condition evaluation
        foreach ($this->extraFieldValues as $fieldId => $value) {
            $this->allFieldValues[$fieldId] = $value;
        }

        $applicant->progress = $applicant->calculateProgress();
        $applicant->save();

        // Recount filled fields
        $this->filledFields = 0;
        $allDefinitions = $applicant->getExtraFieldsWithLabels();
        $this->totalFields = 0;
        $remainingUnfilled = 0;

        foreach ($allDefinitions as $field) {
            $this->totalFields++;
            $isFilled = $field['value'] !== null && $field['value'] !== '' && $field['value'] !== [];
            if ($isFilled) {
                $this->filledFields++;
            } else {
                $remainingUnfilled++;
            }
        }

        // Count legal status field
        if ($this->showLegalStatus) {
            $this->totalFields++;
            $applicant->refresh();
            $lsNow = $applicant->legalStatus;
            if ($lsNow && $lsNow->is_eu_citizen !== null) {
                $this->filledFields++;
                $this->legalStatusAlreadyFilled = true;
            } else {
                $remainingUnfilled++;
            }
        }

        // Zentrale HR-Schreibtisch-Regeln evaluieren (eu_burger,
        // grundlegende_deutschkenntnisse, etc.). Muss VOR dem Phase-Advance
        // laufen, damit ein routed Bewerber (auto_pilot=false) nicht doch
        // noch in die nächste Phase wandert.
        app(\Platform\Recruiting\Services\HrDeskRoutingService::class)
            ->evaluateAndRoute($applicant->fresh(['legalStatus']));

        if ($remainingUnfilled === 0) {
            $this->state = 'completed';
            $applicant->refresh();
            $applicant->checkAutoPilotCompletion();
        } else {
            $this->state = 'saved';
        }
    }

    public function continueEditing(): void
    {
        $applicant = $this->getApplicant();
        if (!$applicant) {
            $this->state = 'notFound';
            return;
        }
        $this->loadFormFields($applicant);
    }

    #[Computed]
    public function confirmedBooking(): ?array
    {
        $applicant = $this->getApplicant();
        $booking = $applicant?->confirmedBooking();
        if (!$booking) {
            return null;
        }
        $interview = $booking->interview;
        return [
            'starts_at' => $interview?->starts_at,
            'location' => $interview?->location,
        ];
    }

    #[Computed]
    public function schulungUrl(): string
    {
        return $this->getApplicant()?->getSchulungUrl() ?? 'https://rheingedeck.de/schulung';
    }

    public function render()
    {
        return view('recruiting::livewire.public.applicant-form')
            ->layout('platform::layouts.guest');
    }
}
