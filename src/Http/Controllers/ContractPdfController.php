<?php

namespace Platform\Recruiting\Http\Controllers;

use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;
use Platform\Core\Models\CorePublicFormLink;
use Platform\Recruiting\Models\RecApplicant;
use Platform\Recruiting\Models\RecContract;

class ContractPdfController extends Controller
{
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

        $html = view('recruiting::pdf.contract', [
            'contract' => $contract,
            'candidateName' => $applicant->crmContactLinks?->first()?->contact?->full_name,
        ])->render();

        $filename = Str::slug($contract->contractTemplate?->name ?? 'Vertrag') . '.pdf';

        return Pdf::loadHTML($html)
            ->setOption('defaultFont', 'DejaVu Sans')
            ->setOption('isHtml5ParserEnabled', true)
            ->setPaper('a4')
            ->download($filename);
    }
}
