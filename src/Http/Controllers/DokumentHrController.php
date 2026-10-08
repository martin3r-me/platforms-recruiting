<?php

namespace Platform\Recruiting\Http\Controllers;

use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;
use Platform\Recruiting\Http\Controllers\Concerns\RendersContractPdf;
use Platform\Recruiting\Models\RecDocumentRecipient;
use Platform\Recruiting\Models\RecEmployee;
use Platform\Recruiting\Support\DokumentNachweisDaten;
use Illuminate\Support\Facades\Storage;
use Platform\Recruiting\Models\RecDocument;

/**
 * HR-Seite der Dokumente (Spec §3.2, §5.4): Datei und Nachweisblatt. Laeuft
 * im Modul-Guard (ModuleRouter::group), Mandant = aktives Team; fremde
 * Mandanten sehen 404, nie 403 (kein Existenz-Orakel).
 */
class DokumentHrController extends Controller
{
    use RendersContractPdf;

    public function datei(string $uuid)
    {
        $dokument = RecDocument::withTrashed()
            ->where('uuid', $uuid)
            ->where('team_id', auth()->user()->currentTeam->id)
            ->firstOrFail();
        // Datei auf dem Speicher weg: 404 statt 500 (Spec §10).
        abort_unless(Storage::disk($dokument->disk)->exists($dokument->stored_path), 404);

        return Storage::disk($dokument->disk)->response(
            $dokument->stored_path,
            $dokument->original_filename,
            ['Cache-Control' => 'private, no-store']
        );
    }

    public function nachweis(string $uuid)
    {
        $teamId = auth()->user()->currentTeam->id;
        $z = RecDocumentRecipient::query()
            ->where('uuid', $uuid)
            ->where('team_id', $teamId)
            ->whereNotNull('signed_at')
            ->with(['document' => fn ($q) => $q->withTrashed()])
            ->firstOrFail();
        $d = $z->document;
        abort_if($d === null || (int) $d->team_id !== (int) $teamId, 404);
        $ma = RecEmployee::find($z->rec_employee_id);
        $labels = (array) config('recruiting.zas.company_labels', []);
        $firma = $ma ? ($labels[(string) $ma->company] ?? (string) $ma->company) : null;

        $daten = DokumentNachweisDaten::fuer(
            ['title' => $d->title, 'original_filename' => $d->original_filename, 'file_sha256' => $d->file_sha256, 'created_at' => $d->created_at?->format('Y-m-d H:i:s')],
            [
                'created_at'      => $z->created_at?->format('Y-m-d H:i:s'),
                'first_viewed_at' => $z->first_viewed_at?->format('Y-m-d H:i:s'),
                'acknowledged_at' => $z->acknowledged_at?->format('Y-m-d H:i:s'),
                'signed_at'       => $z->signed_at?->format('Y-m-d H:i:s'),
                'signature_data'  => $z->signature_data,
            ],
            ['first_name' => $ma?->first_name, 'last_name' => $ma?->last_name, 'personnel_number' => $ma?->personnel_number, 'company' => $firma],
        );

        $html = view('recruiting::pdf.dokument-nachweis', [
            'daten'   => $daten,
            'erstellt' => now()->format('d.m.Y H:i'),
            'stempel' => $this->loadCompanyStampDataUrl(),
        ])->render();

        return Pdf::loadHTML($html)
            ->setOption('defaultFont', 'DejaVu Sans')
            ->setOption('isHtml5ParserEnabled', true)
            ->setPaper('a4')
            ->stream(Str::slug('Nachweis ' . $d->title) . '.pdf');
    }
}
