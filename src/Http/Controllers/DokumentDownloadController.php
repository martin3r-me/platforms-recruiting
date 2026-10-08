<?php

namespace Platform\Recruiting\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Storage;
use Platform\Recruiting\Models\RecDocumentRecipient;
use Platform\Recruiting\Models\RecEmployee;
use Platform\Recruiting\Services\PersonScopeResolver;
use Platform\Recruiting\Services\PortalAuth;
use Platform\Recruiting\Support\DokumentZugriff;

/**
 * PDF-Auslieferung an den Mitarbeiter (Spec §5.3). Keine Token-URL: die
 * verifizierte Portal-Sitzung muss fuer die Anstellung der Zustellung oder
 * eine Schwester-Anstellung derselben Person stehen (PersonScopeResolver).
 * Entscheidung in DokumentZugriff (rein, getestet), Reihenfolge wie
 * DispoAttachmentController: nie ein Existenz-Orakel.
 *
 * "Gesehen am" setzt NICHT dieser Controller, sondern PortalShell::oeffneDokument()
 * — der Download ist das Ergebnis des Oeffnens, nicht das Oeffnen selbst.
 */
class DokumentDownloadController extends Controller
{
    public function __invoke(Request $request, string $uuid)
    {
        $zustellung = RecDocumentRecipient::query()->where('uuid', $uuid)->with('document')->first();
        $dokument = $zustellung?->document;                    // null bei SoftDelete
        $employee = $zustellung ? RecEmployee::find($zustellung->rec_employee_id) : null;

        $scopeIds = $employee ? app(PersonScopeResolver::class)->forEmployee($employee)['ids'] : [];
        $sitzungGueltig = self::sitzungDeckt($scopeIds, fn (string $key) => $request->session()->has($key));
        $gesperrt = $scopeIds !== []
            && RecEmployee::query()->whereIn('id', $scopeIds)->whereNotNull('portal_locked_at')->exists();

        $code = DokumentZugriff::entscheide(
            $zustellung !== null && $dokument !== null && $employee !== null,
            $sitzungGueltig,
            $gesperrt,
            $zustellung?->withdrawn_at !== null,
        );
        abort_if($code !== 200, $code);

        return Storage::disk($dokument->disk)->response(
            $dokument->stored_path,
            $dokument->original_filename,
            ['Cache-Control' => 'private, no-store']
        );
    }

    /**
     * @param  list<int> $scopeIds
     * @param  callable(string):bool $hatSitzung
     */
    public static function sitzungDeckt(array $scopeIds, callable $hatSitzung): bool
    {
        foreach ($scopeIds as $id) {
            if ($hatSitzung(PortalAuth::sessionKey((int) $id))) {
                return true;
            }
        }

        return false;
    }
}
