<?php

namespace Platform\Recruiting\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Platform\Recruiting\Models\RecApplicantSettings;
use Platform\Recruiting\Models\RecEmployee;
use Symfony\Component\HttpFoundation\Response;

/**
 * CSV-Export lohnrelevanter Aenderungen. Liefert alle offenen Payroll-
 * Changes als CSV und quittiert die ausgelieferten Eintraege.
 *
 * Race-sicher: Eintraege die NACH dem Snapshot reinkommen, ueberleben
 * den Reset. Wir merken uns die genauen Array-Indizes bzw. Hashes der
 * ausgelieferten Eintraege und entfernen nur die — neue Eintraege
 * bleiben fuer den naechsten Export erhalten.
 *
 * ?dry_run=true → CSV liefert ohne Quittierung (Test-Modus)
 */
class PayrollChangesExportController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $isDryRun = $request->boolean('dry_run');
        $teamId = (int) auth()->user()->currentTeam->id;
        $fieldLabels = RecApplicantSettings::payrollFieldLabels();

        $rows = [];
        $exportedCount = 0;

        // Transaktion + Lock: zwischen Lesen und Update darf kein
        // weiterer Observer-Update reinlaufen.
        DB::transaction(function () use ($teamId, $isDryRun, &$rows, &$exportedCount, $fieldLabels): void {
            $employees = RecEmployee::query()
                ->where('team_id', $teamId)
                ->whereNotNull('payroll_data_changed_at')
                ->where('is_active', true)
                ->orderBy('payroll_data_changed_at', 'asc')
                ->lockForUpdate()
                ->get();

            foreach ($employees as $employee) {
                $name = trim(($employee->first_name ?? '') . ' ' . ($employee->last_name ?? ''));
                $entries = $employee->payroll_data_changed_fields ?? [];
                if (!is_array($entries) || $entries === []) {
                    continue;
                }

                $exportedHashes = [];
                foreach ($entries as $entry) {
                    $rows[] = [
                        'name'             => $name,
                        'personnel_number' => $employee->personnel_number ?? '',
                        'field'            => $fieldLabels[$entry['field'] ?? ''] ?? ($entry['field'] ?? ''),
                        'old_value'        => $this->castValue($entry['old'] ?? ''),
                        'new_value'        => $this->castValue($entry['new'] ?? ''),
                        'changed_at'       => $entry['at'] ?? '',
                    ];
                    $exportedHashes[] = $this->entryHash($entry);
                    $exportedCount++;
                }

                if ($isDryRun) {
                    continue;
                }

                // RACE-SAFE RESET: zwischen Lesen oben und Update unten
                // koennen NEUE Eintraege im JSON gelandet sein (Observer
                // schreibt direkt via DB::table). Wir re-lesen den aktuellen
                // Stand und behalten alles, was NICHT in unseren
                // exportierten Hashes ist.
                $currentRaw = DB::table('rec_employees')
                    ->where('id', $employee->id)
                    ->value('payroll_data_changed_fields');
                $current = $currentRaw ? json_decode($currentRaw, true) : [];
                if (!is_array($current)) {
                    $current = [];
                }

                $remaining = array_values(array_filter(
                    $current,
                    fn (array $e) => !in_array($this->entryHash($e), $exportedHashes, true)
                ));

                DB::table('rec_employees')
                    ->where('id', $employee->id)
                    ->update([
                        'payroll_data_changed_at'     => $remaining === [] ? null : now(),
                        'payroll_data_changed_fields' => $remaining === [] ? null : json_encode($remaining),
                    ]);
            }
        });

        $csv = $this->buildCsv($rows);
        $filename = 'payroll-changes-' . now()->format('Y-m-d_His') . '.csv';

        return response($csv, 200)
            ->header('Content-Type', 'text/csv; charset=utf-8')
            ->header('Content-Disposition', 'attachment; filename="' . $filename . '"')
            ->header('Cache-Control', 'no-store')
            ->header('X-Records-Count', (string) $exportedCount)
            ->header('X-Dry-Run', $isDryRun ? '1' : '0');
    }

    /**
     * Stabiler Hash eines Eintrags. field + at reichen, weil
     * Observer pro Field je now() einen Eintrag schreibt (Mikrosekunde-
     * Praezision). Sollten zwei Eintraege denselben Hash haben, ist
     * der Reset deterministisch (beide werden entfernt) — keine
     * Datenkorruption.
     */
    protected function entryHash(array $entry): string
    {
        return md5(($entry['field'] ?? '') . '|' . ($entry['at'] ?? ''));
    }

    protected function buildCsv(array $rows): string
    {
        $handle = fopen('php://temp', 'r+');

        // BOM fuer Excel-UTF8-Erkennung
        fwrite($handle, "\xEF\xBB\xBF");

        fputcsv($handle, [
            'Mitarbeiter',
            'Personalnummer',
            'Feld',
            'Alter Wert',
            'Neuer Wert',
            'Geaendert am',
        ], ';');

        foreach ($rows as $row) {
            fputcsv($handle, [
                $row['name'],
                $row['personnel_number'],
                $row['field'],
                $row['old_value'],
                $row['new_value'],
                $row['changed_at'],
            ], ';');
        }

        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }

    protected function castValue(mixed $value): string
    {
        if ($value === null) {
            return '';
        }
        if (is_bool($value)) {
            return $value ? 'Ja' : 'Nein';
        }
        if (is_array($value)) {
            return implode(', ', $value);
        }
        return (string) $value;
    }
}
