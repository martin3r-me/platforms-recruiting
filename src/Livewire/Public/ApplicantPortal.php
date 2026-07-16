<?php

namespace Platform\Recruiting\Livewire\Public;

use Livewire\Component;
use Platform\Core\Models\CorePublicFormLink;
use Platform\Recruiting\Models\RecApplicant;

class ApplicantPortal extends Component
{
    public string $state = 'loading';

    public ?int $applicantId = null;
    public ?string $applicantToken = null;
    public string $applicantName = '';
    public array $contracts = [];
    public bool $duzen = false;

    public function mount(string $token): void
    {
        $this->applicantToken = $token;

        $link = CorePublicFormLink::where('token', $token)->first();

        if (!$link) {
            $this->state = 'invalid';
            return;
        }

        if (!$link->isValid()) {
            $this->state = 'expired';
            return;
        }

        $applicant = $link->linkable;

        if (!$applicant instanceof RecApplicant) {
            $this->state = 'invalid';
            return;
        }

        $applicant->load([
            'crmContactLinks.contact',
            'contracts.contractTemplate',
        ]);

        $this->applicantId = $applicant->id;
        $this->duzen = $applicant->usesInformalAddress();
        $contact = $applicant->crmContactLinks->first()?->contact;
        $this->applicantName = trim(($contact?->first_name ?? '') . ' ' . ($contact?->last_name ?? '')) ?: 'Bewerber';

        $contracts = $applicant->contracts
            ->filter(fn ($c) => $c->status !== 'cancelled')
            ->map(function ($c) {
                $contractLink = $c->getOrCreatePublicFormLink();
                $code = $c->contractTemplate?->code;
                $displayName = match (true) {
                    $code !== null && str_starts_with($code, 'AV-') => 'Arbeitsvertrag',
                    $code === 'IFSG' => 'Infektionsschutzgesetz',
                    default => $c->contractTemplate?->name ?? 'Vertrag',
                };
                return [
                    'id' => $c->id,
                    'display_name' => $displayName,
                    'status' => $c->status,
                    'signed_at' => $c->signed_at,
                    'completed_at' => $c->completed_at,
                    'sign_url' => route('recruiting.public.contract-signing', ['token' => $contractLink->token]),
                    'pdf_url' => $c->status === 'completed'
                        ? route('recruiting.public.contract-pdf', ['token' => $this->applicantToken, 'contractId' => $c->id])
                        : null,
                ];
            })
            ->values()
            ->toArray();

        $this->contracts = $contracts;
        $this->state = count($this->contracts) === 0 ? 'empty' : 'ready';
    }

    public function render()
    {
        return view('recruiting::livewire.public.applicant-portal')
            ->layout('platform::layouts.guest');
    }
}
