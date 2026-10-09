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

        return $this->pdfAntwort($vertrag);
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
     * 404: kein Vertrag, nicht unterschrieben, keine Anstellung (kein
     * Existenz-Orakel). 403: keine Sitzung der Person oder Sperre. Sonst 200.
     */
    public static function zugriff(?RecContract $vertrag, callable $hatSitzung): int
    {
        $anstellung = $vertrag?->anstellung();
        $scopeIds = $anstellung ? app(PersonScopeResolver::class)->forEmployee($anstellung)['ids'] : [];
        $gesperrt = $scopeIds !== []
            && RecEmployee::query()->whereIn('id', $scopeIds)->whereNotNull('portal_locked_at')->exists();

        return DokumentZugriff::entscheide(
            $vertrag !== null && $anstellung !== null && $vertrag->status === 'completed' && $vertrag->signed_at !== null,
            DokumentDownloadController::sitzungDeckt($scopeIds, $hatSitzung),
            DokumentDownloadController::gesperrt($gesperrt, $anstellung),
            false,
        );
    }

    private function pdfAntwort(RecContract $vertrag)
    {
        $vertrag->loadMissing('contractTemplate');
        $anstellung = $vertrag->anstellung();
        $name = trim(($anstellung?->first_name ?? '') . ' ' . ($anstellung?->last_name ?? ''));

        // Persoenliches Dokument: nicht im Browser-/Proxy-Cache liegen lassen
        // (wie der Dokument-Download im selben Portal).
        return $this->contractPdfDownload($vertrag, $name !== '' ? $name : null)
            ->header('Cache-Control', 'private, no-store');
    }
}
