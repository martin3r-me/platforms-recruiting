<?php

namespace Platform\Recruiting\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Platform\Core\Models\ContextFile;
use Platform\Recruiting\Http\Controllers\Concerns\RendersContractPdf;
use Platform\Recruiting\Models\RecApplicant;
use Platform\Recruiting\Services\Zas\ZasFieldResolver;
use Platform\Recruiting\Services\Zas\ZasSignedUrlGenerator;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Streamt eine vom ZAS-Export referenzierte Bewerber-Datei.
 *
 * URL-Form (siehe ZasSignedUrlGenerator):
 *   GET /recruiting/zas/files/{applicant_uuid}/{slot}?expires={ts}&sig={hmac}
 *
 * Der Slot wird per FILE_FIELD_FALLBACKS auf die alt+neu Field-Keys
 * gemappt; der erste vorhandene Wert gewinnt. Bei `upl-vertrag` wird
 * das jueueste signierte Vertrags-PDF rausgegeben.
 *
 * Sicherheit: HMAC-Signatur + Expiry. Keine weitere Auth-Schicht —
 * die signierte URL IST die Auth. Endpoint ohne Bearer (sonst koennte
 * ZAS die URL nicht im Browser/Importer ohne Header oeffnen).
 *
 * Caching: explizit `no-store`, weil sich der Datei-Inhalt theoretisch
 * unter derselben URL aendern kann (auch wenn ZAS in der Praxis nicht
 * ueberschreibt — siehe Doku, Edge-Case Bild-Update).
 */
class ZasFileController extends Controller
{
    use RendersContractPdf;

    public function __construct(
        protected ZasSignedUrlGenerator $signedUrlGenerator,
    ) {}

    public function __invoke(Request $request, string $applicantUuid, string $slot): Response
    {
        // 1. Signatur pruefen
        $expires = (int) $request->query('expires', 0);
        $sig = (string) $request->query('sig', '');

        if (!$this->signedUrlGenerator->isValid($applicantUuid, $slot, $expires, $sig)) {
            return response('Invalid or expired signature', 403)
                ->header('Cache-Control', 'no-store');
        }

        // 2. Bewerber holen
        $applicant = RecApplicant::where('uuid', $applicantUuid)->first();
        if (!$applicant) {
            return response('Not found', 404)->header('Cache-Control', 'no-store');
        }

        // 3. Slot aufloesen
        if ($slot === 'upl-vertrag') {
            return $this->streamLatestSignedContract($applicant, 'arbeitsvertrag');
        }
        if ($slot === 'upl-ifsg') {
            return $this->streamLatestSignedContract($applicant, 'ifsg');
        }

        return $this->streamExtraFieldFile($applicant, $slot);
    }

    /**
     * Loest einen Datei-Slot via FILE_FIELD_FALLBACKS auf die zugehoerige
     * ContextFile auf und streamt sie.
     */
    protected function streamExtraFieldFile(RecApplicant $applicant, string $slot): Response
    {
        $candidateFields = $this->getFallbackFieldsForSlot($slot);
        if ($candidateFields === []) {
            return response('Unknown slot', 404)->header('Cache-Control', 'no-store');
        }

        $fileId = $this->resolveFileId($applicant, $candidateFields);
        if (!$fileId) {
            return response('No file in slot', 404)->header('Cache-Control', 'no-store');
        }

        $file = ContextFile::find($fileId);
        if (!$file) {
            return response('File not found', 404)->header('Cache-Control', 'no-store');
        }

        return $this->streamContextFile($file);
    }

    /**
     * Streamt das juengste unterschriebene Vertrags-PDF des Bewerbers
     * fuer den angegebenen Typ:
     *   - 'arbeitsvertrag' → templates mit code LIKE 'AV%'
     *   - 'ifsg'           → templates mit code = 'IFSG'
     *
     * Pro Bewerber gibt es typischerweise einen AV (irgendeine Zuschlag-
     * Variante) und einen IFSG. Wenn ein Bewerber irrtuemlich mehrere AV
     * unterschrieben hat, gewinnt der juengste signed_at.
     */
    protected function streamLatestSignedContract(RecApplicant $applicant, string $type): Response
    {
        $query = DB::table('rec_contracts')
            ->join('rec_contract_templates', 'rec_contracts.rec_contract_template_id', '=', 'rec_contract_templates.id')
            ->where('rec_contracts.rec_applicant_id', $applicant->id)
            ->whereNotNull('rec_contracts.signed_at')
            ->select('rec_contracts.id', 'rec_contracts.signed_at');

        $query = match ($type) {
            'arbeitsvertrag' => $query->where('rec_contract_templates.code', 'like', 'AV%'),
            'ifsg'           => $query->where('rec_contract_templates.code', '=', 'IFSG'),
            default          => $query,
        };

        $contract = $query->orderByDesc('rec_contracts.signed_at')->first();

        if (!$contract) {
            return response('No signed contract', 404)->header('Cache-Control', 'no-store');
        }

        // ContractPdfController arbeitet mit (token, contractId) und
        // braucht einen public_form_link-Token-Kontext, den wir hier
        // nicht haben. Stattdessen rendern wir das PDF direkt aus dem
        // Vertrag — die Signed-URL bleibt damit die einzige Auth-Schicht.
        return $this->renderContractPdfDirect((int) $contract->id);
    }

    /**
     * Rendert das Vertrags-PDF ohne Public-Token-Pfad. Logik analog zu
     * ContractPdfController, aber direkt aus dem Vertrag heraus.
     */
    protected function renderContractPdfDirect(int $contractId): Response
    {
        $contract = \Platform\Recruiting\Models\RecContract::with('contractTemplate')
            ->where('id', $contractId)
            ->first();

        if (!$contract) {
            return response('Contract gone', 404)->header('Cache-Control', 'no-store');
        }

        $applicant = \Platform\Recruiting\Models\RecApplicant::find($contract->rec_applicant_id);
        $candidateName = $applicant?->crmContactLinks?->first()?->contact?->full_name;

        // Wichtig: dieselbe prepareContractContentForPdf wie der
        // ContractPdfController nutzen (Trait), damit der Stempel bei
        // AV-* Vertraegen identisch injiziert wird. Sonst sieht der
        // Bewerber einen Vertrag MIT Stempel und ZAS einen OHNE.
        $contentForPdf = $this->prepareContractContentForPdf($contract);

        $html = view('recruiting::pdf.contract', [
            'contract'       => $contract,
            'candidateName'  => $candidateName,
            'contentForPdf'  => $contentForPdf,
        ])->render();

        $filename = \Illuminate\Support\Str::slug(
            $contract->contractTemplate?->name ?? 'Vertrag'
        ) . '.pdf';

        return \Barryvdh\DomPDF\Facade\Pdf::loadHTML($html)
            ->setOption('defaultFont', 'DejaVu Sans')
            ->setOption('isHtml5ParserEnabled', true)
            ->setPaper('a4')
            ->download($filename)
            ->header('Cache-Control', 'no-store');
    }

    /**
     * Streamt eine ContextFile von ihrem Storage-Disk.
     */
    protected function streamContextFile(ContextFile $file): Response
    {
        $disk = Storage::disk($file->disk ?? 'local');
        if (!$disk->exists($file->path)) {
            return response('File missing on disk', 404)->header('Cache-Control', 'no-store');
        }

        return new StreamedResponse(
            function () use ($disk, $file) {
                $stream = $disk->readStream($file->path);
                if ($stream === null) {
                    return;
                }
                fpassthru($stream);
                if (is_resource($stream)) {
                    fclose($stream);
                }
            },
            200,
            [
                'Content-Type'        => $file->mime_type ?: 'application/octet-stream',
                'Content-Length'      => $file->file_size ?: '',
                'Content-Disposition' => 'inline; filename="' . addslashes($file->original_name ?: $file->file_name) . '"',
                'Cache-Control'       => 'no-store',
            ]
        );
    }

    /**
     * Holt die file_id (oder erste id aus einer JSON-Liste) aus dem
     * ersten nicht-leeren Field-Key des Slot-Fallbacks.
     */
    protected function resolveFileId(RecApplicant $applicant, array $fieldNames): ?int
    {
        foreach ($fieldNames as $name) {
            $definition = $applicant->getExtraFieldDefinitions()->firstWhere('name', $name);
            if (!$definition) {
                continue;
            }

            $rawValue = DB::table('core_extra_field_values')
                ->where('fieldable_type', 'rec_applicant')
                ->where('fieldable_id', $applicant->id)
                ->where('definition_id', $definition->id)
                ->value('value');

            if ($rawValue === null || $rawValue === '') {
                continue;
            }

            // Multi-File: JSON-Array. Wir nehmen das erste Element.
            if (is_string($rawValue) && str_starts_with($rawValue, '[') && str_ends_with($rawValue, ']')) {
                $decoded = json_decode($rawValue, true);
                if (is_array($decoded) && isset($decoded[0])) {
                    return (int) $decoded[0];
                }
                continue;
            }

            $intValue = (int) $rawValue;
            if ($intValue > 0) {
                return $intValue;
            }
        }

        return null;
    }

    /**
     * Holt das Slot→Field-Mapping aus ZasFieldResolver, damit die Liste
     * nur an einer Stelle gepflegt wird.
     */
    protected function getFallbackFieldsForSlot(string $slot): array
    {
        return ZasFieldResolver::FILE_FIELD_FALLBACKS[$slot] ?? [];
    }
}
