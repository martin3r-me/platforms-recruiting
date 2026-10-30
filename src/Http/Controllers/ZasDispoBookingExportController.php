<?php

namespace Platform\Recruiting\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Platform\Recruiting\Services\Zas\Dispo\ZasDispoBookingExport;
use Platform\Recruiting\Services\Zas\ZasCsvBuilder;
use Symfony\Component\HttpFoundation\Response;

/**
 * ZAS-Abruf: zugesagte Job-Schichten als Buchungen (Spec 2026-10-30).
 * Jede Zeile genau einmal — der Abruf setzt den Marker.
 *
 * ?einsatz=  optional auf einen Einsatz (EinsatzRef) begrenzen — fuer Tests
 * ?dry_run=1 Datei liefern, nichts markieren
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
        $dryRun = filter_var($request->query('dry_run', false), FILTER_VALIDATE_BOOLEAN);

        $rows = $this->export->rows($einsatz !== '' ? $einsatz : null);
        $csv = $this->export->csv($rows, $this->csvBuilder);
        $markiert = $dryRun ? 0 : $this->export->markieren(array_column($rows, 'id'));

        return response($csv, 200)
            ->header('Content-Type', 'text/csv; charset=utf-8')
            ->header('Content-Disposition', 'attachment; filename="rheingedeck-dispo-bookings.csv"')
            ->header('Cache-Control', 'no-store')
            ->header('X-Records-Count', (string) count($rows))
            ->header('X-Records-Marked', (string) $markiert)
            ->header('X-Dry-Run', $dryRun ? '1' : '0');
    }
}
