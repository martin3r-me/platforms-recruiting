<?php

namespace Platform\Recruiting\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Platform\Core\Models\CorePublicFormLink;
use Platform\Recruiting\Http\Controllers\Concerns\RendersContractPdf;
use Platform\Recruiting\Models\RecContract;
use Platform\Recruiting\Models\RecEmployee;
use Platform\Recruiting\Services\PersonScopeResolver;
use Platform\Recruiting\Support\DokumentZugriff;

/**
 * Vertrags-PDF aus dem neuen Portal (Spec Vertrag aus der Akte §2.5) — ueber
 * den VERTRAGS-Link-Token, aber nur mit verifizierter Portal-Sitzung einer
 * Anstellung derselben Person (Muster DokumentDownloadController). Der Token
 * allein oeffnet nichts; die alte Route ueber den Bewerber-Token bleibt.
 */
class VertragPdfController extends Controller
{
    use RendersContractPdf;

    public function __invoke(Request $request, string $token)
    {
        $vertrag = self::vertragZumToken($token);
        $code = self::zugriff($vertrag, fn (string $key) => $request->session()->has($key));
        abort_if($code !== 200, $code);

        // Persoenliches Dokument: nicht im Browser-/Proxy-Cache liegen lassen
        // (wie der Dokument-Download im selben Portal).
        return $this->pdfAntwort($vertrag)->header('Cache-Control', 'private, no-store');
    }

    /** HR-Download aus der Akte (Spec §2.7) — im Modul-Guard, Mandant = aktives Team, sonst 404. */
    public function hr(int $contractId)
    {
        $vertrag = self::hrVertrag((int) auth()->user()->currentTeam->id, $contractId);
        abort_if($vertrag === null, 404);

        return $this->pdfAntwort($vertrag)->header('Cache-Control', 'private, no-store');
    }

    public static function hrVertrag(int $teamId, int $contractId): ?RecContract
    {
        return RecContract::query()
            ->where('team_id', $teamId)
            ->where('status', 'completed')
            ->with('contractTemplate')
            ->find($contractId);
    }

    public static function vertragZumToken(string $token): ?RecContract
    {
        $link = CorePublicFormLink::query()->where('token', $token)->first();
        if (!$link || !$link->isValid()) {
            return null;
        }
        $vertrag = $link->linkable;

        return $vertrag instanceof RecContract ? $vertrag : null;
    }

    /**
     * 404: kein Vertrag, nicht unterschrieben, keine Anstellung. 403: keine
     * Sitzung einer AKTIVEN Anstellung der Person, oder Portalsperre im
     * Umfang. Sonst 200.
     *
     * Aktivitaet (Ruling Task 6, Fix-Runde 1): gefragt ist nicht die
     * Vertrags-Anstellung, sondern die der Sitzung — die Sitzung muss zu
     * einer aktiven Anstellung im Personen-Umfang gehoeren. Nach einem
     * Wechsel RG -> MA mit deaktivierter RG-Anstellung bleibt der
     * RG-Vertrag fuer dieselbe Person abrufbar (gleiche Person, kein Leck);
     * die Liste zeigt ihn ja auch. Eine Sitzung, die nur an inaktiven
     * Anstellungen haengt, oeffnet nichts — das Portal selbst laesst sie
     * auch nicht mehr hinein (PortalShell::berechtigterMitarbeiter()).
     */
    public static function zugriff(?RecContract $vertrag, callable $hatSitzung): int
    {
        $anstellung = $vertrag?->anstellung();
        $scopeIds = $anstellung ? app(PersonScopeResolver::class)->forEmployee($anstellung)['ids'] : [];
        $aktiveIds = $scopeIds === []
            ? []
            : RecEmployee::query()->whereIn('id', $scopeIds)->where('is_active', true)->pluck('id')->all();
        $gesperrt = $scopeIds !== []
            && RecEmployee::query()->whereIn('id', $scopeIds)->whereNotNull('portal_locked_at')->exists();

        return DokumentZugriff::entscheide(
            $vertrag !== null && $anstellung !== null && $vertrag->status === 'completed' && $vertrag->signed_at !== null,
            DokumentDownloadController::sitzungDeckt($aktiveIds, $hatSitzung),
            $gesperrt,
            false,
        );
    }

    /** Ueberschreibbar fuer den Durchstich-Test (__invoke ohne DomPDF). */
    protected function pdfAntwort(RecContract $vertrag)
    {
        $vertrag->loadMissing('contractTemplate');
        $anstellung = $vertrag->anstellung();
        $name = trim(($anstellung?->first_name ?? '') . ' ' . ($anstellung?->last_name ?? ''));

        return $this->contractPdfDownload($vertrag, $name !== '' ? $name : null);
    }
}
