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
        $sitzungGueltig = self::sitzungDeckt($scopeIds, fn (int $id) => PortalAuth::sitzungGueltig($id, $request->session()));
        $gesperrt = $scopeIds !== []
            && RecEmployee::query()->whereIn('id', $scopeIds)->whereNotNull('portal_locked_at')->exists();
        $gesperrt = self::gesperrt($gesperrt, $employee);

        $code = DokumentZugriff::entscheide(
            $zustellung !== null && $dokument !== null && $employee !== null,
            $sitzungGueltig,
            $gesperrt,
            $zustellung?->withdrawn_at !== null,
        );
        abort_if($code !== 200, $code);
        // Datei auf dem Speicher weg: 404 statt 500 (Spec §10).
        abort_unless(Storage::disk($dokument->disk)->exists($dokument->stored_path), 404);

        return Storage::disk($dokument->disk)->response(
            $dokument->stored_path,
            $dokument->original_filename,
            ['Cache-Control' => 'private, no-store']
        );
    }

    /**
     * Gesperrt ist, wessen Portal gesperrt ist ODER wessen Anstellung (die der
     * Zustellung) deaktiviert ist (Spec §10, Final-Review Fund 9).
     */
    public static function gesperrt(bool $portalGesperrt, ?RecEmployee $employee): bool
    {
        return $portalGesperrt || ($employee !== null && !$employee->is_active);
    }

    /**
     * Deckt eine gueltige Portal-Sitzung eine der Anstellungen? Die Antwort
     * je Anstellung kommt aus PortalAuth::sitzungGueltig() (Spec HR-Bereich
     * §9.6) — hier wird nur ueber den Umfang iteriert.
     *
     * @param  list<int> $scopeIds
     * @param  callable(int):bool $gilt
     */
    public static function sitzungDeckt(array $scopeIds, callable $gilt): bool
    {
        foreach ($scopeIds as $id) {
            if ($gilt((int) $id)) {
                return true;
            }
        }

        return false;
    }
}
