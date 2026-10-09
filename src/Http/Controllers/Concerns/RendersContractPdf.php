<?php

namespace Platform\Recruiting\Http\Controllers\Concerns;

use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Str;
use Platform\Recruiting\Models\RecContract;

/**
 * Gemeinsame Vertrags-PDF-Render-Logik.
 *
 * Wird genutzt von:
 *   - ContractPdfController     (HR-UI / Public-Token-Pfad)
 *   - VertragPdfController      (neues Portal, Vertrags-Token + Sitzung)
 *   - ZasFileController         (ZAS-Export Signed-URL-Pfad)
 *   - ZasEmployeeFileController (ZAS-Export ueber die Anstellung)
 *
 * Beide muessen exakt dasselbe PDF erzeugen — sonst sieht der Bewerber
 * einen Vertrag und ZAS / IBEI einen anderen. Insbesondere muss der
 * Firmenstempel bei Arbeitsvertraegen (`AV-*`) konsistent injiziert
 * werden.
 */
trait RendersContractPdf
{
    /**
     * Bereitet `personalized_content` fuer's PDF-Rendering auf:
     *   - kollabiert 3+ Newlines auf 2 (sonst werden die Abschnitts-
     *     Abstaende unter `white-space: pre-line` zu gross)
     *   - bei Arbeitsvertraegen (Code `AV-*`): injiziert den
     *     RheinGedeck-Stempel vor dem letzten "RheinGedeck GmbH"-Vorkommen
     *     (das ist die Arbeitgeber-Zelle in der Unterschriftstabelle).
     *
     * Hinweis: Der bare `AV`-Code (legacy, inactive) bekommt KEINEN
     * Stempel — bewusst, weil das alte Template sich anders zusammensetzt
     * und der Stempel-Anker `RheinGedeck GmbH` evtl. an anderer Stelle
     * steht. Aktuelle aktive Templates sind alle `AV-XXX` (mit Suffix).
     */
    protected function prepareContractContentForPdf(RecContract $contract): string
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

    /**
     * Laedt das Firmenstempel-Bild als data:image/png-URL fuer DomPDF.
     * Liegt unter resources/images/company-stamp.png im Recruiting-Modul.
     * Returnt null wenn die Datei fehlt oder unlesbar ist — dann wird
     * der Vertrag eben ohne Stempel gerendert.
     */
    protected function loadCompanyStampDataUrl(): ?string
    {
        $path = __DIR__ . '/../../../../resources/images/company-stamp.png';
        if (!is_file($path)) {
            return null;
        }
        $binary = @file_get_contents($path);
        if ($binary === false) {
            return null;
        }
        return 'data:image/png;base64,' . base64_encode($binary);
    }

    /**
     * Das fertige Vertrags-PDF als Download — EINE Stelle fuer View, DomPDF-
     * Optionen und Dateinamen (Spec Vertrag aus der Akte §2.5: der neue
     * Portal-Controller kopiert den Rumpf nicht, er ruft ihn).
     */
    protected function contractPdfDownload(RecContract $contract, ?string $candidateName)
    {
        $html = view('recruiting::pdf.contract', [
            'contract'      => $contract,
            'candidateName' => $candidateName,
            'contentForPdf' => $this->prepareContractContentForPdf($contract),
        ])->render();

        $filename = Str::slug($contract->contractTemplate?->name ?? 'Vertrag') . '.pdf';

        return Pdf::loadHTML($html)
            ->setOption('defaultFont', 'DejaVu Sans')
            ->setOption('isHtml5ParserEnabled', true)
            ->setPaper('a4')
            ->download($filename);
    }
}
