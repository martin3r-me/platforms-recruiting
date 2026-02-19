<?php

namespace Platform\Recruiting\Livewire\Public;

use Livewire\Component;
use Livewire\WithFileUploads;
use Platform\Core\Livewire\Concerns\WithExtraFields;
use Platform\Core\Models\ContextFile;
use Platform\Core\Models\CoreExtraFieldValue;
use Platform\Core\Services\ContextFileService;
use Platform\Recruiting\Models\RecApplicant;

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

        // Filter: only show unfilled fields
        $filtered = [];
        $this->totalFields = 0;
        $this->filledFields = 0;

        foreach ($this->extraFieldDefinitions as $field) {
            $this->totalFields++;
            $value = $this->extraFieldValues[$field['id']] ?? null;
            $isFilled = $value !== null && $value !== '' && $value !== [];

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

        if (empty($filtered)) {
            $this->state = 'completed';
        } else {
            $this->state = 'form';
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
        $this->validate($this->getExtraFieldValidationRules(), $this->getExtraFieldValidationMessages());

        $applicant = $this->getApplicant();
        if (!$applicant) {
            $this->state = 'notFound';
            return;
        }

        $this->saveExtraFieldValues($applicant);

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

        $this->state = $remainingUnfilled === 0 ? 'completed' : 'saved';
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

    public function render()
    {
        return view('recruiting::livewire.public.applicant-form')
            ->layout('platform::layouts.guest');
    }
}
