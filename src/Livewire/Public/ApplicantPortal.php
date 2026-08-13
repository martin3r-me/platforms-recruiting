<?php

namespace Platform\Recruiting\Livewire\Public;

use Livewire\Component;
use Platform\Core\Models\CorePublicFormLink;
use Platform\Recruiting\Models\RecApplicant;
use Platform\Recruiting\Models\RecTrainingCertificate;
use Platform\Recruiting\Support\TrainingCertificatePortalRows;
use Platform\Recruiting\Support\TrainingCertificateWaTemplate;

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

        $contractRows = $applicant->contracts
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

        // Zeilenliste UND Zustand aus EINEM Aufruf: der Zustand zaehlte vorher
        // nur die Vertragszeilen. Ein abgelehnter Nicht-EU-Bewerber hat
        // typischerweise keine Vertraege — wer die Zertifikate erst nach der
        // Zustandszeile anhaengt, laesst das Portal sich fuer leer erklaeren,
        // waehrend das Zertifikat darin liegt. Die Reihenfolge ist deshalb nicht
        // kommentiert, sondern weggenommen (siehe appendWithState()).
        [$this->contracts, $this->state] = TrainingCertificatePortalRows::appendWithState(
            $contractRows,
            $this->certificateRows((int) $applicant->id)
        );
    }

    /**
     * Die Zertifikat-Zeilen eines Bewerbers, in der Form der Vertragszeilen.
     *
     * Identisch zu EmployeePortal::certificateRows() — bewusst zweimal und
     * nicht als geteilter Trait: die beiden Portale sind ansonsten voneinander
     * unabhaengig, und die gemeinsame Zeilenform liegt schon an einer Stelle
     * (TrainingCertificatePortalRows), also dort, wo ein Auseinanderlaufen
     * wehtaete.
     *
     * KEIN Filter auf `kind`: ein Bewerber darf Zertifikate mehrerer
     * Schulungsarten haben, im Portal sollen alle liegen. Der kind-Filter
     * gehoert an die Ausstellung, nicht an die Anzeige.
     *
     * @return list<array<string,mixed>>
     */
    private function certificateRows(int $applicantId): array
    {
        return RecTrainingCertificate::query()
            ->where('rec_applicant_id', $applicantId)
            ->orderBy('issued_at')
            ->get()
            ->map(fn (RecTrainingCertificate $cert) => TrainingCertificatePortalRows::row(
                $cert->id,
                $cert->issued_at,
                route(TrainingCertificateWaTemplate::ROUTE_NAME, ['uuid' => $cert->uuid]),
            ))
            ->all();
    }

    public function render()
    {
        return view('recruiting::livewire.public.applicant-portal')
            ->layout('platform::layouts.guest');
    }
}
