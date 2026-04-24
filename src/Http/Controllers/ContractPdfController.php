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

        $contentForPdf = $this->prepareContentForPdf($contract);

        $html = view('recruiting::pdf.contract', [
            'contract' => $contract,
            'candidateName' => $applicant->crmContactLinks?->first()?->contact?->full_name,
            'contentForPdf' => $contentForPdf,
        ])->render();

        $filename = Str::slug($contract->contractTemplate?->name ?? 'Vertrag') . '.pdf';

        return Pdf::loadHTML($html)
            ->setOption('defaultFont', 'DejaVu Sans')
            ->setOption('isHtml5ParserEnabled', true)
            ->setPaper('a4')
            ->download($filename);
    }

    /**
     * Normalises the contract content for PDF output:
     *   - collapses 3+ consecutive newlines to 2 so the vertical gap between
     *     sections does not grow out of hand under white-space: pre-line
     *   - for Arbeitsvertrag variants (code AV-*), injects the RheinGedeck
     *     company stamp image in front of the last "RheinGedeck GmbH" text
     *     (which lives in the employer cell of the signature table)
     */
    private function prepareContentForPdf(RecContract $contract): string
    {
        $content = $contract->personalized_content ?? '';
        if ($content === '') {
            return '';
        }

        $content = preg_replace('/\n{3,}/', "\n\n", $content);

        $code = $contract->contractTemplate?->code;
        if ($code && str_starts_with($code, 'AV-')) {
            $stampDataUrl = $this->loadCompanyStampDataUrl();
            if ($stampDataUrl) {
                $needle = 'RheinGedeck GmbH';
                $pos = strrpos($content, $needle);
                if ($pos !== false) {
                    $stampHtml = '<img src="' . $stampDataUrl . '" alt="RheinGedeck GmbH" style="max-width:180px;max-height:120px;display:block;margin-bottom:4px;">';
                    $content = substr($content, 0, $pos)
                        . $stampHtml
                        . $needle
                        . substr($content, $pos + strlen($needle));
                }
            }
        }

        return $content;
    }

    private function loadCompanyStampDataUrl(): ?string
    {
        $path = __DIR__ . '/../../../resources/images/company-stamp.png';
        if (!is_file($path)) {
            return null;
        }
        $binary = @file_get_contents($path);
        if ($binary === false) {
            return null;
        }
        return 'data:image/png;base64,' . base64_encode($binary);
    }
}
