<?php

namespace Platform\Recruiting\Http\Controllers;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Platform\Recruiting\Models\RecEmployee;
use Platform\Recruiting\Services\Zas\ZasCsvBuilder;
use Platform\Recruiting\Services\Zas\ZasEmployeeFieldResolver;
use Symfony\Component\HttpFoundation\Response;

/**
 * Liefert das ZAS-CSV mit MA die NEU angelegt wurden (Phase-4-Hook
 * via CreateEmployeeFromApplicantService) und noch nicht zu ZAS
 * gegangen sind.
 *
 * Filter:
 *   - rec_employees.zas_initial_exported_at IS NULL  (noch nicht geliefert)
 *   - rec_employees.is_active = true                 (analog is_test im Bewerber-Export)
 *
 * Reihenfolge: ASC nach created_at — frueheste MA-Anlage zuerst.
 *
 * Response-Header:
 *   - X-Records-Count: Anzahl gelieferter MA
 *   - X-Last-Created:  ISO-Timestamp des juengsten created_at im Batch
 *
 * ?dry_run=true → CSV liefert ohne Marker-Reset
 * ?limit=N      → max N Records (gestaffelter Rollout)
 */
class ZasEmployeeInitialExportController extends Controller
{
    public function __construct(
        protected ZasEmployeeFieldResolver $fieldResolver,
        protected ZasCsvBuilder $csvBuilder,
    ) {}

    public function __invoke(Request $request): Response
    {
        $isDryRun = $request->boolean('dry_run');
        $limit = $this->extractLimit($request);

        try {
            return $this->buildResponse($isDryRun, $limit);
        } catch (\Throwable $e) {
            if ($isDryRun) {
                $payload = sprintf(
                    "ZAS-EMPLOYEE-INITIAL-EXPORT-FEHLER (dry_run debug)\n\n%s: %s\nin %s:%d\n\nStack:\n%s",
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

    protected function buildResponse(bool $isDryRun, ?int $limit): Response
    {
        $employees = $this->fetchInitialEmployees($limit);

        $rows = [];
        $latestCreated = null;
        $deliveredIds = [];

        foreach ($employees as $employee) {
            $rows[] = $this->fieldResolver->resolve($employee);
            $deliveredIds[] = $employee->id;
            if ($latestCreated === null || $employee->created_at?->gt($latestCreated)) {
                $latestCreated = $employee->created_at;
            }
        }

        $csv = $this->csvBuilder->build($rows, ZasEmployeeFieldResolver::COLUMNS);

        if (!$isDryRun && $deliveredIds !== []) {
            $this->markAsInitialExported($deliveredIds);
        }

        return response($csv, 200)
            ->header('Content-Type', 'text/csv; charset=utf-8')
            ->header('Content-Disposition', 'attachment; filename="rheingedeck-employees-initial.csv"')
            ->header('Cache-Control', 'no-store')
            ->header('X-Records-Count', (string) count($rows))
            ->header('X-Last-Created', $latestCreated instanceof Carbon ? $latestCreated->toIso8601String() : '');
    }

    protected function fetchInitialEmployees(?int $limit): \Illuminate\Support\Collection
    {
        $query = RecEmployee::query()
            ->whereNull('zas_initial_exported_at')
            ->where('is_active', true)
            ->orderBy('created_at', 'asc');

        if ($limit !== null && $limit > 0) {
            $query->limit($limit);
        }

        return $query->get();
    }

    protected function markAsInitialExported(array $employeeIds): void
    {
        // Direkter DB-Update um den RecEmployeeExportObserver NICHT
        // zu triggern (sonst wuerde zas_changed_at gleich mit gesetzt
        // und der gerade ausgelieferte MA waere sofort im Update-Endpoint
        // sichtbar — was nicht gewollt ist).
        DB::table('rec_employees')
            ->whereIn('id', $employeeIds)
            ->update(['zas_initial_exported_at' => now()]);
    }

    protected function extractLimit(Request $request): ?int
    {
        if (!$request->has('limit')) {
            return null;
        }
        $raw = $request->query('limit');
        if (is_numeric($raw) && (int) $raw > 0) {
            return (int) $raw;
        }
        return null;
    }
}
