<?php

namespace Platform\Recruiting\Livewire\Public;

use Livewire\Component;
use Platform\Core\Livewire\Concerns\WithExtraFields;
use Platform\Core\Models\CoreExtraFieldValue;
use Platform\Recruiting\Models\RecApplicant;

class ApplicantForm extends Component
{
    use WithExtraFields;

    public string $publicToken = '';
    public string $state = 'loading';
    public string $applicantName = '';

    public ?int $applicantId = null;

    public int $totalFields = 0;
    public int $filledFields = 0;

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

        // Filter: only show unfilled fields, skip file type fields
        $filtered = [];
        $this->totalFields = 0;
        $this->filledFields = 0;

        foreach ($this->extraFieldDefinitions as $field) {
            if ($field['type'] === 'file') {
                continue;
            }

            $this->totalFields++;
            $value = $this->extraFieldValues[$field['id']] ?? null;
            $isFilled = $value !== null && $value !== '' && $value !== [];

            if ($isFilled) {
                $this->filledFields++;
            } else {
                $filtered[] = $field;
            }
        }

        // Overwrite definitions with only unfilled, non-file fields
        $this->extraFieldDefinitions = $filtered;

        // Reset values to only contain filtered field IDs
        $filteredValues = [];
        foreach ($filtered as $field) {
            $filteredValues[$field['id']] = $this->extraFieldValues[$field['id']] ?? null;
        }
        $this->extraFieldValues = $filteredValues;
        $this->originalExtraFieldValues = $filteredValues;

        if (empty($filtered)) {
            $this->state = 'completed';
        } else {
            $this->state = 'form';
        }
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
            if ($field['type'] === 'file') {
                continue;
            }
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
