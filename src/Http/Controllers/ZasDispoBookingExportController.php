<?php

namespace Platform\Recruiting\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Platform\Recruiting\Services\Zas\Dispo\ZasDispoBookingExport;
use Platform\Recruiting\Services\Zas\ZasCsvBuilder;
use Symfony\Component\HttpFoundation\Response;

/**
 * ZAS-Abruf: zugesagte Job-Schichten als Buchungen (Spec 2026-10-30).
 * Jede Buchung genau einmal — der Abruf beansprucht die Zeilen VOR dem
 * Ausliefern (bedingter UPDATE je Zeile), parallele Abrufe liefern nie doppelt.
 *
 * ?einsatz=  optional auf einen Einsatz (EinsatzRef) begrenzen — fuer Tests
 * ?dry_run   Datei liefern, nichts markieren (auch ohne Wert; nur 0/false/no/off/nein heisst scharf)
 */
class ZasDispoBookingExportController extends Controller
{
    public function __construct(
        protected ZasDispoBookingExport $export,
        protected ZasCsvBuilder $csvBuilder,
    ) {}

    public function __invoke(Request $request): Response
    {
        $einsatz = trim((string) $request->query('einsatz', ''));
        $dryRun = ZasDispoBookingExport::dryRun($request->query());

        $ermittelt = $this->export->ermitteln($einsatz !== '' ? $einsatz : null);
        $rows = $dryRun ? $ermittelt['rows'] : $this->export->beanspruchen($ermittelt);
        $csv = $this->export->csv($rows, $this->csvBuilder);

        return response($csv, 200)
            ->header('Content-Type', 'text/csv; charset=utf-8')
            ->header('Content-Disposition', 'attachment; filename="rheingedeck-dispo-bookings.csv"')
            ->header('Cache-Control', 'no-store')
            ->header('X-Records-Count', (string) count($rows))
            ->header('X-Dry-Run', $dryRun ? '1' : '0');
    }
}
