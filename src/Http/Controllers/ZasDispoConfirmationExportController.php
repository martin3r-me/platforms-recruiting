<?php

namespace Platform\Recruiting\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Platform\Recruiting\Services\Zas\Dispo\ZasDispoConfirmationExport;
use Platform\Recruiting\Services\Zas\ZasCsvBuilder;
use Symfony\Component\HttpFoundation\Response;

/**
 * ZAS-Abruf: NUR die bestaetigten Dispo-Einbuchungen (Kunde 08.09.) —
 * Snapshot, kein Marker, beliebig oft abrufbar. ZAS setzt beim Import den
 * Haken "bestaetigt" ueber die DS-ID.
 *
 * ?days=N    Rueckblick in Tagen (Default 7, max 60)
 * ?einsatz=  optional auf einen Einsatz (EinsatzRef) begrenzen — fuer Tests
 */
class ZasDispoConfirmationExportController extends Controller
{
    public function __construct(
        protected ZasDispoConfirmationExport $export,
        protected ZasCsvBuilder $csvBuilder,
    ) {}

    public function __invoke(Request $request): Response
    {
        $days = min(60, max(0, (int) $request->query('days', 7)));
        $einsatz = trim((string) $request->query('einsatz', ''));

        $rows = $this->export->rows($days, $einsatz !== '' ? $einsatz : null);
        $csv = $this->csvBuilder->build($rows, ZasDispoConfirmationExport::COLUMNS);

        return response($csv, 200)
            ->header('Content-Type', 'text/csv; charset=utf-8')
            ->header('Content-Disposition', 'attachment; filename="rheingedeck-dispo-confirmations.csv"')
            ->header('Cache-Control', 'no-store')
            ->header('X-Records-Count', (string) count($rows));
    }
}
