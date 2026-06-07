<?php

namespace Platform\Recruiting\Livewire\Employees;

use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Platform\Recruiting\Models\RecApplicantSettings;
use Platform\Recruiting\Models\RecEmployee;

/**
 * Zeigt alle Mitarbeiter mit offenen lohnrelevanten Aenderungen.
 * Export-Button fuehrt zum CSV-Endpoint (setzt Flags zurueck).
 */
class PayrollChanges extends Component
{
    /**
     * Flattened rows: jeder payroll_data_changed_fields-Eintrag wird
     * zu einer eigenen Zeile mit MA-Kontext.
     */
    #[Computed]
    public function rows(): array
    {
        $teamId = auth()->user()->currentTeam->id;

        $employees = RecEmployee::query()
            ->where('team_id', $teamId)
            ->whereNotNull('payroll_data_changed_at')
            ->where('is_active', true)
            ->orderBy('payroll_data_changed_at', 'desc')
            ->get();

        $fieldLabels = $this->fieldLabels();
        $lookupMaps = [];
        $rows = [];

        foreach ($employees as $employee) {
            $name = trim(($employee->first_name ?? '') . ' ' . ($employee->last_name ?? ''));
            $entries = $employee->payroll_data_changed_fields ?? [];

            foreach ($entries as $entry) {
                $field = $entry['field'] ?? '';
                $label = $fieldLabels[$field] ?? $field;

                // Lookup-Felder als Label aufloesen
                $lookupName = $this->lookupNameFor($field);
                if ($lookupName !== null) {
                    if (!isset($lookupMaps[$lookupName])) {
                        $lookupMaps[$lookupName] = $this->loadLookupMap($lookupName);
                    }
                    $map = $lookupMaps[$lookupName];
                    $oldDisplay = $map[$entry['old'] ?? ''] ?? ($entry['old'] ?? '');
                    $newDisplay = $map[$entry['new'] ?? ''] ?? ($entry['new'] ?? '');
                } else {
                    $oldDisplay = $this->formatValue($entry['old'] ?? null);
                    $newDisplay = $this->formatValue($entry['new'] ?? null);
                }

                $rows[] = [
                    'employee_id'      => $employee->id,
                    'name'             => $name,
                    'personnel_number' => $employee->personnel_number ?? '',
                    'field'            => $field,
                    'label'            => $label,
                    'old'              => $oldDisplay,
                    'new'              => $newDisplay,
                    'at'               => $entry['at'] ?? '',
                ];
            }
        }

        return $rows;
    }

    #[Computed]
    public function hasChanges(): bool
    {
        return count($this->rows) > 0;
    }

    protected function fieldLabels(): array
    {
        return RecApplicantSettings::payrollFieldLabels();
    }

    protected function lookupNameFor(string $field): ?string
    {
        return match ($field) {
            'health_insurance' => 'krankenkasse',
            default            => null,
        };
    }

    protected function loadLookupMap(string $lookupName): array
    {
        $lookupId = DB::table('core_lookups')
            ->where('name', $lookupName)
            ->value('id');

        if (!$lookupId) {
            return [];
        }

        return DB::table('core_lookup_values')
            ->where('lookup_id', $lookupId)
            ->pluck('label', 'value')
            ->all();
    }

    protected function formatValue(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '—';
        }
        if (is_bool($value)) {
            return $value ? 'Ja' : 'Nein';
        }
        if (is_array($value)) {
            return implode(', ', $value);
        }
        return (string) $value;
    }

    public function render()
    {
        return view('recruiting::livewire.employees.payroll-changes')
            ->layout('platform::layouts.app');
    }
}
