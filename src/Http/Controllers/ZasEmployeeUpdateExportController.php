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
 * Liefert das ZAS-CSV mit MA, an denen sich seit dem letzten Update-
 * Pull was geaendert hat (Delta-Sync via zas_changed_at).
 *
 * Filter:
 *   - rec_employees.zas_initial_exported_at IS NOT NULL  (= bereits initial geliefert)
 *   - rec_employees.zas_changed_at IS NOT NULL           (= Aenderung liegt vor)
 *   - rec_employees.is_active = true
 *
 * Reihenfolge: aelteste Aenderung zuerst.
 *
 * Response-Header:
 *   - X-Records-Count: Anzahl gelieferter MA
 *   - X-Last-Change:   ISO-Timestamp der juengsten Aenderung im Batch
 *
 * ?dry_run=true → CSV liefert ohne Marker-Reset
 * ?limit=N      → max N Records
 */
class ZasEmployeeUpdateExportController extends Controller
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
                    "ZAS-EMPLOYEE-UPDATE-EXPORT-FEHLER (dry_run debug)\n\n%s: %s\nin %s:%d\n\nStack:\n%s",
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
        $employees = $this->fetchChangedEmployees($limit);

        $rows = [];
        $latestChange = null;
        $deliveredIds = [];

        foreach ($employees as $employee) {
            $rows[] = $this->fieldResolver->resolve($employee);
            $deliveredIds[] = $employee->id;
            if ($latestChange === null || $employee->zas_changed_at?->gt($latestChange)) {
                $latestChange = $employee->zas_changed_at;
            }
        }

        $csv = $this->csvBuilder->build($rows, ZasEmployeeFieldResolver::COLUMNS);

        if (!$isDryRun && $deliveredIds !== []) {
            $this->markAsUpdateExported($deliveredIds);
        }

        return response($csv, 200)
            ->header('Content-Type', 'text/csv; charset=utf-8')
            ->header('Content-Disposition', 'attachment; filename="rheingedeck-employees-updates.csv"')
            ->header('Cache-Control', 'no-store')
            ->header('X-Records-Count', (string) count($rows))
            ->header('X-Last-Change', $latestChange instanceof Carbon ? $latestChange->toIso8601String() : '');
    }

    protected function fetchChangedEmployees(?int $limit): \Illuminate\Support\Collection
    {
        $query = RecEmployee::query()
            ->whereNotNull('zas_initial_exported_at')
            ->whereNotNull('zas_changed_at')
            ->where('is_active', true)
            ->orderBy('zas_changed_at', 'asc');

        if ($limit !== null && $limit > 0) {
            $query->limit($limit);
        }

        return $query->get();
    }

    protected function markAsUpdateExported(array $employeeIds): void
    {
        // Direkter DB-Update — Observer wird sonst beim setzen von
        // zas_changed_at=NULL nicht getriggert sondern die Aenderung
        // wuerde ein neues now() schreiben (Endlos-Schleife).
        DB::table('rec_employees')
            ->whereIn('id', $employeeIds)
            ->update(['zas_changed_at' => null]);
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
