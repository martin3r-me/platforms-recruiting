<?php

namespace Platform\Recruiting\Livewire\Public;

use Livewire\Component;
use Platform\Core\Models\CorePublicFormLink;
use Platform\Recruiting\Models\RecContract;

class ContractSigning extends Component
{
    public int $step = 1;

    public string $state = 'loading';

    public ?int $contractId = null;
    public string $contractContent = '';
    public string $contractTemplateName = '';

    public bool $par15HasPrevious = false;
    public array $par15Entries = [];

    public bool $par16WasJobseeking = false;
    public array $par16Entries = [];

    public ?string $signatureData = null;

    public function mount(string $token): void
    {
        $link = CorePublicFormLink::where('token', $token)->first();

        if (! $link) {
            $this->state = 'invalid';
            return;
        }

        if (! $link->isValid()) {
            $this->state = 'expired';
            return;
        }

        $contract = $link->linkable;

        if (! $contract instanceof RecContract) {
            $this->state = 'invalid';
            return;
        }

        if ($contract->status === 'completed' || $contract->signed_at) {
            $this->state = 'already_signed';
            return;
        }

        if ($contract->status !== 'sent') {
            $this->state = 'invalid';
            return;
        }

        $this->contractId = $contract->id;
        $this->contractContent = $contract->personalized_content ?? '';
        $this->contractTemplateName = $contract->contractTemplate?->name ?? 'Vertrag';
        $this->state = 'form';
    }

    public function addPar15Entry(): void
    {
        $this->par15Entries[] = ['beginn' => '', 'ende' => '', 'arbeitgeber' => '', 'tage' => ''];
    }

    public function removePar15Entry(int $index): void
    {
        unset($this->par15Entries[$index]);
        $this->par15Entries = array_values($this->par15Entries);
    }

    public function addPar16Entry(): void
    {
        $this->par16Entries[] = ['beginn' => '', 'ende' => '', 'arbeitsagentur' => ''];
    }

    public function removePar16Entry(int $index): void
    {
        unset($this->par16Entries[$index]);
        $this->par16Entries = array_values($this->par16Entries);
    }

    public function nextStep(): void
    {
        if ($this->step === 1) {
            $this->validatePreSigningData();
        }

        $this->step++;
    }

    public function previousStep(): void
    {
        if ($this->step > 1) {
            $this->step--;
        }
    }

    public function sign(): void
    {
        $this->validate([
            'signatureData' => 'required',
        ], [
            'signatureData.required' => 'Bitte unterschreiben Sie den Vertrag.',
        ]);

        $contract = RecContract::find($this->contractId);

        if (! $contract || $contract->status !== 'sent') {
            $this->state = 'invalid';
            return;
        }

        $preSigningData = [
            'par15_has_previous' => $this->par15HasPrevious,
            'par15_entries' => $this->par15HasPrevious ? $this->par15Entries : [],
            'par16_was_jobseeking' => $this->par16WasJobseeking,
            'par16_entries' => $this->par16WasJobseeking ? $this->par16Entries : [],
        ];

        $personalizedContent = RecContract::embedPreSigningData(
            $contract->personalized_content ?? '',
            $preSigningData
        );

        $contract->update([
            'pre_signing_data' => $preSigningData,
            'personalized_content' => $personalizedContent,
            'signature_data' => $this->signatureData,
            'signed_at' => now(),
            'completed_at' => now(),
            'status' => 'completed',
        ]);

        $this->state = 'already_signed';
    }

    private function validatePreSigningData(): void
    {
        $rules = [];
        $messages = [];

        if ($this->par15HasPrevious) {
            $rules = array_merge($rules, [
                'par15Entries' => 'required|array|min:1',
                'par15Entries.*.beginn' => 'required|string',
                'par15Entries.*.ende' => 'required|string',
                'par15Entries.*.arbeitgeber' => 'required|string',
                'par15Entries.*.tage' => 'required|integer|min:1',
            ]);
            $messages = array_merge($messages, [
                'par15Entries.required' => 'Bitte mindestens einen Eintrag hinzufuegen.',
                'par15Entries.min' => 'Bitte mindestens einen Eintrag hinzufuegen.',
                'par15Entries.*.beginn.required' => 'Beginn ist erforderlich.',
                'par15Entries.*.ende.required' => 'Ende ist erforderlich.',
                'par15Entries.*.arbeitgeber.required' => 'Arbeitgeber ist erforderlich.',
                'par15Entries.*.tage.required' => 'Anzahl Tage ist erforderlich.',
            ]);
        }

        if ($this->par16WasJobseeking) {
            $rules = array_merge($rules, [
                'par16Entries' => 'required|array|min:1',
                'par16Entries.*.beginn' => 'required|string',
                'par16Entries.*.ende' => 'required|string',
                'par16Entries.*.arbeitsagentur' => 'required|string',
            ]);
            $messages = array_merge($messages, [
                'par16Entries.required' => 'Bitte mindestens einen Eintrag hinzufuegen.',
                'par16Entries.min' => 'Bitte mindestens einen Eintrag hinzufuegen.',
                'par16Entries.*.beginn.required' => 'Beginn ist erforderlich.',
                'par16Entries.*.ende.required' => 'Ende ist erforderlich.',
                'par16Entries.*.arbeitsagentur.required' => 'Arbeitsagentur ist erforderlich.',
            ]);
        }

        if (! empty($rules)) {
            $this->validate($rules, $messages);
        }
    }

    public function render()
    {
        return view('recruiting::livewire.public.contract-signing')
            ->layout('platform::layouts.guest');
    }
}
