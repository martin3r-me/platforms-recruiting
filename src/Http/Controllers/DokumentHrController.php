<?php

namespace Platform\Recruiting\Http\Controllers;

use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Storage;
use Platform\Recruiting\Models\RecDocument;

/**
 * HR-Seite der Dokumente (Spec §3.2, §5.4): Datei und Nachweisblatt. Laeuft
 * im Modul-Guard (ModuleRouter::group), Mandant = aktives Team; fremde
 * Mandanten sehen 404, nie 403 (kein Existenz-Orakel).
 */
class DokumentHrController extends Controller
{
    public function datei(string $uuid)
    {
        $dokument = RecDocument::withTrashed()
            ->where('uuid', $uuid)
            ->where('team_id', auth()->user()->currentTeam->id)
            ->firstOrFail();

        return Storage::disk($dokument->disk)->response(
            $dokument->stored_path,
            $dokument->original_filename,
            ['Cache-Control' => 'private, no-store']
        );
    }
}
