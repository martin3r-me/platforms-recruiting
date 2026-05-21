<?php

namespace Platform\Recruiting\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Platform\Core\Models\ContextFile;
use Platform\Recruiting\Http\Controllers\Concerns\RendersContractPdf;
use Platform\Recruiting\Http\Controllers\Concerns\StreamsZasContextFile;
use Platform\Recruiting\Models\RecEmployee;
use Platform\Recruiting\Services\Zas\ZasEmployeeFieldResolver;
use Platform\Recruiting\Services\Zas\ZasSignedUrlGenerator;
use Symfony\Component\HttpFoundation\Response;

/**
 * Streamt eine vom ZAS-MA-Export referenzierte Mitarbeiter-Datei.
 *
 * URL-Form:
 *   GET /recruiting/zas/employee-files/{employee_uuid}/{slot}
 *       ?expires={ts}&sig={hmac}
 *
 * Slots beginnen mit `emp-*` damit keine Kollision mit Bewerber-Slots.
 * File-IDs werden direkt aus rec_employees-Spalten gelesen (via
 * ZasEmployeeFieldResolver::FILE_SLOT_FIELD_MAP).
 *
 * Vertraege (emp-arbvertrag, emp-ifsg) werden via Bewerber-Verknuepfung
 * (rec_employees.rec_applicant_id → rec_contracts) on-the-fly als PDF
 * gerendert — analog ZasFileController.
 *
 * Auth: Signed-URL allein (kein Bearer). Caching: no-store.
 */
class ZasEmployeeFileController extends Controller
{
    use StreamsZasContextFile;
    use RendersContractPdf;

    public function __construct(
        protected ZasSignedUrlGenerator $signedUrlGenerator,
    ) {}

    public function __invoke(Request $request, string $employeeUuid, string $slot): Response
    {
        // 1. Signatur pruefen
        $expires = (int) $request->query('expires', 0);
        $sig = (string) $request->query('sig', '');

        if (!$this->signedUrlGenerator->isValid($employeeUuid, $slot, $expires, $sig)) {
            return response('Invalid or expired signature', 403)
                ->header('Cache-Control', 'no-store');
        }

        // 2. Mitarbeiter holen
        $employee = RecEmployee::where('uuid', $employeeUuid)->first();
        if (!$employee) {
            return response('Not found', 404)->header('Cache-Control', 'no-store');
        }

        // 3. Slot aufloesen
        if ($slot === 'emp-vertrag') {
            return $this->streamLatestSignedContract($employee, 'arbeitsvertrag');
        }
        if ($slot === 'emp-ifsg') {
            return $this->streamLatestSignedContract($employee, 'ifsg');
        }

        return $this->streamEmployeeFileSlot($employee, $slot);
    }

    /**
     * Loest einen Slot via FILE_SLOT_FIELD_MAP zur Employee-Spalte auf,
     * holt die file_id, laedt die ContextFile und streamt sie.
     */
    protected function streamEmployeeFileSlot(RecEmployee $employee, string $slot): Response
    {
        $field = ZasEmployeeFieldResolver::FILE_SLOT_FIELD_MAP[$slot] ?? null;
        if (!$field) {
            return response('Unknown slot', 404)->header('Cache-Control', 'no-store');
        }

        $fileId = $employee->getAttribute($field);
        if (!$fileId) {
            return response('No file in slot', 404)->header('Cache-Control', 'no-store');
        }

        $file = ContextFile::find((int) $fileId);
        if (!$file) {
            return response('File not found', 404)->header('Cache-Control', 'no-store');
        }

        return $this->streamContextFile($file);
    }

    /**
     * Streamt das juengste unterschriebene Vertrags-PDF des MA.
     * Vertraege haengen weiterhin am rec_applicant (nicht am Employee
     * direkt) — wir gehen ueber employee.rec_applicant_id.
     */
    protected function streamLatestSignedContract(RecEmployee $employee, string $type): Response
    {
        if (!$employee->rec_applicant_id) {
            return response('Employee has no linked applicant', 404)
                ->header('Cache-Control', 'no-store');
        }

        $query = DB::table('rec_contracts')
            ->join('rec_contract_templates', 'rec_contracts.rec_contract_template_id', '=', 'rec_contract_templates.id')
            ->where('rec_contracts.rec_applicant_id', $employee->rec_applicant_id)
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

        return $this->renderContractPdfDirect((int) $contract->id);
    }

    /**
     * Rendert das Vertrags-PDF — Logik identisch zu ZasFileController.
     * Nutzt RendersContractPdf-Trait fuer Stempel-Konsistenz.
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
}
