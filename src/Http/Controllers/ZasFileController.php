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
        // Bevorzuge medium-Variante: ~30-160 KB statt 1-3 MB beim Original.
        // Wenn keine medium-Variante existiert (z. B. Legacy-Uploads, oder
        // Originale die zu klein zum Skalieren waren), Fallback aufs Original.
        $variant = $file->variants()
            ->where('variant_type', 'like', 'medium_%')
            ->first();

        if ($variant) {
            $disk = Storage::disk($variant->disk ?? 'local');
            $path = $variant->path;
            $sourceMime = 'image/webp';                  // Variants sind immer webp
            $servedAs = $variant->variant_type;
        } else {
            $disk = Storage::disk($file->disk ?? 'local');
            $path = $file->path;
            $sourceMime = $file->mime_type ?: 'application/octet-stream';
            $servedAs = 'original';
        }

        if (!$disk->exists($path)) {
            return response('File missing on disk', 404)->header('Cache-Control', 'no-store');
        }

        // WebP wird von MS Access (Picture-Control) nicht angezeigt. ZAS
        // braucht JPG. Wir konvertieren on-the-fly: Variant-WebP einlesen,
        // mit Intervention Image zu JPG, als image/jpeg streamen. Originale
        // die schon ein gaengiges Format haben (jpg/png) gehen unveraendert
        // raus.
        //
        // Pro Request +100-300ms. Bei ~640 Image-Requests im Erst-Pull also
        // ~2-3 Min zusaetzliche Server-Zeit — vertretbar fuer die Volumen.
        $needsJpegConversion = str_starts_with($sourceMime, 'image/')
            && !in_array($sourceMime, ['image/jpeg', 'image/jpg'], true);

        if ($needsJpegConversion) {
            return $this->streamAsJpeg($disk, $path, $file, $servedAs, $sourceMime);
        }

        // Original ist schon JPG/PNG → direkt durchstreamen
        $filename = $file->original_name ?: $file->file_name;
        $size = $variant?->file_size ?: ($file->file_size ?: '');

        return new StreamedResponse(
            function () use ($disk, $path) {
                $stream = $disk->readStream($path);
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
                'Content-Type'        => $sourceMime,
                'Content-Length'      => (string) $size,
                'Content-Disposition' => 'inline; filename="' . addslashes($filename) . '"',
                'Cache-Control'       => 'no-store',
                'X-Variant-Served'    => $servedAs,
            ]
        );
    }

    /**
     * Liest die Quell-Datei (WebP-Variante oder anderes Bildformat) aus
     * dem Storage, konvertiert mit Intervention Image zu JPG (Quality 90),
     * gibt das Resultat als image/jpeg-Response zurueck.
     *
     * Speicher: laedt das komplette Bild in Memory. Bei medium-Variants
     * (~30-160 KB) unkritisch. Bei Originalen (1-5 MB) ist's noch ok,
     * passiert aber nur wenn keine Variant existiert.
     */
    protected function streamAsJpeg(
        \Illuminate\Contracts\Filesystem\Filesystem $disk,
        string $path,
        ContextFile $file,
        string $servedAs,
        string $sourceMime,
    ): Response {
        $sourceBytes = $disk->get($path);
        if ($sourceBytes === null) {
            return response('File missing on disk', 404)->header('Cache-Control', 'no-store');
        }

        try {
            $manager = new \Intervention\Image\ImageManager(
                new \Intervention\Image\Drivers\Gd\Driver()
            );
            $image = $manager->read($sourceBytes);
            $jpegBytes = (string) $image->toJpeg(90);
        } catch (\Throwable $e) {
            // Konvertierung fehlgeschlagen → Fallback: Original-Bytes
            // unkonvertiert ausgeben. Besser kaputt-anzeigen als 500er.
            \Log::warning('[ZAS-FileController] WebP-JPG-Konvertierung fehlgeschlagen', [
                'context_file_id' => $file->id,
                'error' => $e->getMessage(),
            ]);
            $jpegBytes = $sourceBytes;
        }

        $filename = $this->variantFilename($file, 'medium');

        return response($jpegBytes, 200, [
            'Content-Type'        => 'image/jpeg',
            'Content-Length'      => (string) strlen($jpegBytes),
            'Content-Disposition' => 'inline; filename="' . addslashes($filename) . '"',
            'Cache-Control'       => 'no-store',
            'X-Variant-Served'    => $servedAs,
            'X-Format-Converted'  => $sourceMime . '->image/jpeg',
        ]);
    }

    /**
     * Erzeugt einen sprechenden Filename fuer die Variante. Wir liefern
     * an ZAS immer JPG aus (MS Access kann WebP nicht anzeigen), daher
     * immer .jpg-Endung.
     */
    protected function variantFilename(ContextFile $file, string $sizeName): string
    {
        $base = $file->original_name ?: $file->file_name;
        if ($base === '') {
            return $sizeName . '.jpg';
        }
        $dot = strrpos($base, '.');
        if ($dot === false) {
            return $base . '-' . $sizeName . '.jpg';
        }
        $name = substr($base, 0, $dot);
        return $name . '-' . $sizeName . '.jpg';
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
