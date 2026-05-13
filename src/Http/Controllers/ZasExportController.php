<?php

namespace Platform\Recruiting\Http\Controllers;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Platform\Recruiting\Models\RecApplicant;
use Platform\Recruiting\Services\Zas\ZasCsvBuilder;
use Platform\Recruiting\Services\Zas\ZasFieldResolver;
use Symfony\Component\HttpFoundation\Response;

/**
 * Liefert das ZAS-CSV mit Bewerbern aus, deren Daten sich seit dem
 * letzten Pull veraendert haben (Delta-Sync via export_changed_at).
 *
 * Filter:
 *   - rec_applicants.export_changed_at IS NOT NULL  (Delta-Marker)
 *   - rec_contracts.sent_at IS NOT NULL fuer den Bewerber  (Phase-Gate)
 *
 * Reihenfolge: aelteste Aenderung zuerst — bei einem grossen Delta
 * bekommt ZAS so chronologisch konsistente Updates.
 *
 * Response-Header:
 *   - X-Records-Count: Anzahl gelieferter Bewerber (0 = nichts neu)
 *   - X-Last-Change:   ISO-Timestamp der juengsten Aenderung im Batch
 *
 * Query-Param ?dry_run=true:
 *   - liefert dieselbe CSV
 *   - setzt export_changed_at NICHT auf NULL
 *   - fuer Test-CSV-Generierung und Debugging
 *
 * Siehe docs/meingedeck/zas-applicant-export.md
 */
class ZasExportController extends Controller
{
    public function __construct(
        protected ZasFieldResolver $fieldResolver,
        protected ZasCsvBuilder $csvBuilder,
    ) {}

    public function __invoke(Request $request): Response
    {
        $isDryRun = $request->boolean('dry_run');

        // Optional: gestaffelter Rollout fuer ZAS-Anbieter.
        // Bei ?limit=N wird die Auslieferung auf N Datensaetze begrenzt
        // (aelteste export_changed_at zuerst). Im Live-Modus werden auch
        // nur diese N Marker konsumiert — die restlichen bleiben markiert
        // fuer einen spaeteren Pull. Hauptanwendung: Erstpull = 1
        // Datensatz testweise, Kunde validiert im UI, dann restliche.
        $limit = null;
        if ($request->has('limit')) {
            $rawLimit = $request->query('limit');
            if (is_numeric($rawLimit) && (int) $rawLimit > 0) {
                $limit = (int) $rawLimit;
            }
        }

        try {
            return $this->buildResponse($isDryRun, $limit);
        } catch (\Throwable $e) {
            // Im Dry-Run-Modus: Klartext-Fehler im Body, damit Debugging
            // ohne APP_DEBUG-Toggle moeglich ist. Im echten Pull bleibt
            // das Standard-500-Verhalten von Laravel (= Hr. Michel sieht
            // keine Stack-Traces).
            if ($isDryRun) {
                $payload = sprintf(
                    "ZAS-EXPORT-FEHLER (dry_run debug)\n\n%s: %s\nin %s:%d\n\nStack:\n%s",
                    get_class($e),
                    $e->getMessage(),
                    $e->getFile(),
                    $e->getLine(),
                    $e->getTraceAsString()
                );
                return response($payload, 500)
                    ->header('Content-Type', 'text/plain; charset=utf-8')
                    ->header('Cache-Control', 'no-store');
            }
            throw $e;
        }
    }

    protected function buildResponse(bool $isDryRun, ?int $limit = null): Response
    {
        $minPhaseOrder = config('recruiting.zas.export_min_phase_order');

        $applicants = $this->fetchChangedApplicants(
            minPhaseOrder: $minPhaseOrder !== null ? (int) $minPhaseOrder : null,
            limit: $limit,
        );

        $rows = [];
        $latestChange = null;
        $deliveredIds = [];

        foreach ($applicants as $applicant) {
            $rows[] = $this->fieldResolver->resolve($applicant);
            $deliveredIds[] = $applicant->id;
            if ($latestChange === null || $applicant->export_changed_at?->gt($latestChange)) {
                $latestChange = $applicant->export_changed_at;
            }
        }

        $csv = $this->csvBuilder->build($rows);

        if (!$isDryRun && $deliveredIds !== []) {
            $this->markAsExported($deliveredIds);
        }

        return response($csv, 200)
            ->header('Content-Type', 'text/csv; charset=utf-8')
            ->header('Content-Disposition', 'attachment; filename="rheingedeck-applicants.csv"')
            ->header('Cache-Control', 'no-store')
            ->header('X-Records-Count', (string) count($rows))
            ->header('X-Last-Change', $latestChange instanceof Carbon ? $latestChange->toIso8601String() : '');
    }

    /**
     * Zieht alle Bewerber, die ausgeliefert werden sollen.
     */
    protected function fetchChangedApplicants(?int $minPhaseOrder, ?int $limit = null): \Illuminate\Support\Collection
    {
        $query = RecApplicant::query()
            ->whereNotNull('export_changed_at')
            ->where('is_test', false)
            ->whereExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('rec_contracts')
                    ->whereColumn('rec_contracts.rec_applicant_id', 'rec_applicants.id')
                    ->whereNotNull('rec_contracts.sent_at');
            })
            ->orderBy('export_changed_at', 'asc');

        // Optionales zusaetzliches Phase-Gate (per Config).
        if ($minPhaseOrder !== null) {
            $query->whereExists(function ($q) use ($minPhaseOrder) {
                $q->select(DB::raw(1))
                    ->from('rec_phases')
                    ->whereColumn('rec_phases.id', 'rec_applicants.rec_phase_id')
                    ->where('rec_phases.order', '>=', $minPhaseOrder);
            });
        }

        // Gestaffelter Rollout: nur N Datensaetze ausliefern (siehe
        // __invoke-Kommentar). Sortierung bleibt ASC nach
        // export_changed_at — also kommen die aeltesten Aenderungen zuerst,
        // konsistent zwischen Pulls.
        if ($limit !== null && $limit > 0) {
            $query->limit($limit);
        }

        return $query->get();
    }

    /**
     * Setzt export_changed_at auf NULL. Direkter DB-Update um den
     * Observer NICHT zu triggern (sonst Endlos-Schleife: Reset →
     * saved → markChanged → Reset → ...).
     */
    protected function markAsExported(array $applicantIds): void
    {
        DB::table('rec_applicants')
            ->whereIn('id', $applicantIds)
            ->update(['export_changed_at' => null]);
    }
}
