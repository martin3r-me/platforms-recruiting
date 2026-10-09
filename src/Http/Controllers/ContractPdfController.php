<?php

namespace Platform\Recruiting\Http\Controllers;

use Illuminate\Routing\Controller;
use Platform\Core\Models\CorePublicFormLink;
use Platform\Recruiting\Http\Controllers\Concerns\RendersContractPdf;
use Platform\Recruiting\Models\RecApplicant;
use Platform\Recruiting\Models\RecContract;

class ContractPdfController extends Controller
{
    use RendersContractPdf;
    public function __invoke(string $token, int $contractId)
    {
        $link = CorePublicFormLink::where('token', $token)->first();
        abort_unless($link && $link->isValid(), 403);

        $applicant = $link->linkable;
        abort_unless($applicant instanceof RecApplicant, 404);

        $contract = RecContract::where('id', $contractId)
            ->where('rec_applicant_id', $applicant->id)
            ->where('status', 'completed')
            ->with('contractTemplate')
            ->firstOrFail();

        return $this->contractPdfDownload(
            $contract,
            $applicant->crmContactLinks?->first()?->contact?->full_name,
        );
    }

    // PDF-Render-Logik (contractPdfDownload, prepareContractContentForPdf,
    // loadCompanyStampDataUrl) ist im RendersContractPdf-Trait — gemeinsam
    // genutzt mit VertragPdfController und den ZAS-Controllern.
}
