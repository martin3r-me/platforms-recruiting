<?php

namespace Platform\Recruiting\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Platform\Recruiting\Services\Zas\Dispo\ZasDispoBookingExport;
use Platform\Recruiting\Services\Zas\Dispo\ZasZeitenExport;
use Platform\Recruiting\Services\Zas\ZasCsvBuilder;
use Symfony\Component\HttpFoundation\Response;

/**
 * ZAS-Abruf: erledigte Zeiten im Format des bisherigen Webportals (Olaf 19./20.11.2026).
 * Jeder Zeiteintrag genau einmal — der Abruf beansprucht die Zeilen VOR dem Ausliefern.
 *
 * ?einsatz=  optional auf einen Einsatz (VID, z. B. RG20523) begrenzen — fuer das Testprojekt
 * ?dry_run   Datei liefern, nichts markieren (auch ohne Wert; nur 0/false/no/off/nein heisst scharf)
 */
class ZasZeitenExportController extends Controller
{
    public function __construct(
        protected ZasZeitenExport $export,
        protected ZasCsvBuilder $csvBuilder,
    ) {}

    public function __invoke(Request $request): Response
    {
        $einsatz = trim((string) $request->query('einsatz', ''));
        $dryRun = ZasDispoBookingExport::dryRun($request->query());

        $zeilen = $this->export->ermitteln($einsatz !== '' ? $einsatz : null);
        if (!$dryRun) {
            $zeilen = $this->export->beanspruchen($zeilen);
        }

        return response($this->export->csv($zeilen, $this->csvBuilder), 200)
            ->header('Content-Type', 'text/csv; charset=utf-8')
            ->header('Content-Disposition', 'attachment; filename="rheingedeck-zeiterfassung.csv"')
            ->header('Cache-Control', 'no-store')
            ->header('X-Records-Count', (string) count($zeilen))
            ->header('X-Dry-Run', $dryRun ? '1' : '0');
    }
}
