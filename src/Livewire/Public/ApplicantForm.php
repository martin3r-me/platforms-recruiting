<?php

namespace Platform\Recruiting\Livewire\Public;

use Carbon\Carbon;
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
    public string $birthDateInput = '';
    public string $birthDateError = '';

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

        if (!$contact || !$contact->birth_date) {
            $this->state = 'noBirthDate';
            return;
        }

        $this->applicantName = $contact->full_name ?? 'Bewerber';
        $this->applicantId = $applicant->id;
        $this->state = 'verify';
    }

    public function verifyBirthDate(): void
    {
        $attempts = session('applicant_verify_attempts_' . $this->publicToken, 0);

        if ($attempts >= 3) {
            $this->birthDateError = 'Zu viele Fehlversuche. Bitte kontaktieren Sie die Personalabteilung.';
            return;
        }

        if (empty($this->birthDateInput)) {
            $this->birthDateError = 'Bitte geben Sie Ihr Geburtsdatum ein.';
            return;
        }

        try {
            $inputDate = Carbon::parse($this->birthDateInput)->startOfDay();
        } catch (\Exception $e) {
            $this->birthDateError = 'Ungültiges Datumsformat.';
            return;
        }

        $applicant = $this->getApplicant();
        if (!$applicant) {
            $this->state = 'notFound';
            return;
        }

        $contact = $applicant->getContact();
        $contactBirthDate = Carbon::parse($contact->birth_date)->startOfDay();

        if (!$inputDate->equalTo($contactBirthDate)) {
            $attempts++;
            session(['applicant_verify_attempts_' . $this->publicToken => $attempts]);
            $remaining = 3 - $attempts;
            if ($remaining > 0) {
                $this->birthDateError = 'Das Geburtsdatum stimmt nicht überein. Noch ' . $remaining . ' Versuch(e).';
            } else {
                $this->birthDateError = 'Zu viele Fehlversuche. Bitte kontaktieren Sie die Personalabteilung.';
            }
            return;
        }

        $this->birthDateError = '';
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
