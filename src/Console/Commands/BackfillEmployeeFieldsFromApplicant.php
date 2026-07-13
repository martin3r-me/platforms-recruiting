<?php

namespace Platform\Recruiting\Console\Commands;

use Illuminate\Console\Command;
use Platform\Recruiting\Models\RecApplicant;
use Platform\Recruiting\Models\RecEmployee;
use Platform\Recruiting\Services\CreateEmployeeFromApplicantService;
use Platform\Recruiting\Support\ApplicantEmployeeFieldMapping;
use Platform\Recruiting\Support\EmployeeBackfillPlanner;

/**
 * Zieht leere RecEmployee-Spalten aus den Bewerber-Extra-Fields nach.
 *
 * Hintergrund: Bis 01.07.2026 las CreateEmployeeFromApplicantService die
 * Non-EU-Dokumente (Aufenthaltstitel V/R, Visum, Zusatzblatt) aus den nie
 * automatisch befuellten rec_applicant_legal_statuses-Spalten — alle davor
 * konvertierten MAs haben diese file_ids nicht, obwohl sie am Bewerber
 * als Extra-Fields vorliegen. Der Service ist idempotent (kein Re-Mapping
 * bei existierendem MA), daher dieses explizite Nachziehen.
 *
 * Verhalten:
 *  - Fuellt NUR aktuell leere Spalten (null/''/[]) — manuelle Nachpflege
 *    aus MA-Portal/HR wird nie ueberschrieben (EmployeeBackfillPlanner).
 *  - Mapping identisch zum Create-Flow (ApplicantEmployeeFieldMapping,
 *    unit-getestet) + nationalpass/immatrikulation aus dem legalStatus.
 *  - Update via Eloquent → RecEmployeeExportObserver setzt zas_changed_at,
 *    ZAS bekommt die nachgetragenen Felder beim naechsten Update-Pull.
 *
 * Aufruf:
 *   php artisan recruiting:backfill-employee-fields --dry-run
 *   php artisan recruiting:backfill-employee-fields
 *   php artisan recruiting:backfill-employee-fields --employee=6
 */
class BackfillEmployeeFieldsFromApplicant extends Command
{
    protected $signature = 'recruiting:backfill-employee-fields
        {--dry-run : Nur anzeigen, welche Spalten gefuellt wuerden}
        {--employee= : Nur diesen RecEmployee (ID) verarbeiten}
        {--team-id= : Auf ein Team beschraenken}';

    protected $description = 'Fuellt leere RecEmployee-Spalten aus den Bewerber-Extra-Fields nach (nie ueberschreibend)';

    public function handle(CreateEmployeeFromApplicantService $service): int
    {
        $dryRun = (bool) $this->option('dry-run');

        if ($dryRun) {
            $this->warn('[DRY-RUN] Keine Änderungen werden vorgenommen.');
        }

        $query = RecEmployee::query()
            ->whereNotNull('rec_applicant_id')
            ->orderBy('id');

        if ($this->option('employee') !== null) {
            $query->where('id', (int) $this->option('employee'));
        }
        if ($this->option('team-id') !== null) {
            $query->where('team_id', (int) $this->option('team-id'));
        }

        $employees = $query->get();
        $this->components->info("{$employees->count()} Mitarbeiter mit Bewerber-Verknüpfung gefunden.");

        $filled = 0;
        $untouched = 0;
        $skipped = 0;
        $columnCounts = [];

        foreach ($employees as $employee) {
            $label = trim("#{$employee->id} {$employee->first_name} {$employee->last_name}");

            $applicant = RecApplicant::find($employee->rec_applicant_id);
            if (!$applicant) {
                $this->warn("⚠ {$label} — Bewerber #{$employee->rec_applicant_id} nicht gefunden, übersprungen.");
                $skipped++;
                continue;
            }

            $extraValues = $service->collectExtraFieldValuesByName($applicant);
            $candidates = ApplicantEmployeeFieldMapping::resolve($extraValues);

            // legalStatus-Quellen ergaenzen (einzige Spalten, die der
            // Create-Flow nicht aus Extra-Fields zieht).
            $applicant->loadMissing('legalStatus');
            if ($legalStatus = $applicant->legalStatus) {
                foreach (['nationalpass_file_id', 'immatrikulation_file_id'] as $column) {
                    if ($legalStatus->$column !== null) {
                        $candidates[$column] = $legalStatus->$column;
                    }
                }
                if ($legalStatus->is_eu_citizen !== null) {
                    $candidates['is_eu_citizen'] = $legalStatus->is_eu_citizen;
                }
            }

            $current = [];
            foreach (array_keys($candidates) as $column) {
                $current[$column] = $employee->getAttribute($column);
            }

            $plan = EmployeeBackfillPlanner::plan($candidates, $current);

            if (empty($plan)) {
                $untouched++;
                continue;
            }

            $this->line("✏ {$label}:");
            foreach ($plan as $column => $value) {
                $display = is_array($value) ? json_encode($value, JSON_UNESCAPED_UNICODE) : var_export($value, true);
                $this->line("    {$column} = {$display}");
                $columnCounts[$column] = ($columnCounts[$column] ?? 0) + 1;
            }

            if (!$dryRun) {
                // Eloquent-Save, damit RecEmployeeExportObserver zas_changed_at
                // setzt und ZAS die Änderung beim nächsten Pull erhält.
                $employee->fill($plan);
                $employee->save();
            }
            $filled++;
        }

        $this->newLine();
        $this->components->bulletList([
            ($dryRun ? 'Würden gefüllt: ' : 'Gefüllt: ') . $filled,
            "Ohne Lücken: {$untouched}",
            "Übersprungen: {$skipped}",
        ]);

        if (!empty($columnCounts)) {
            arsort($columnCounts);
            $this->components->info('Betroffene Spalten:');
            $this->table(
                ['Spalte', 'Anzahl MAs'],
                collect($columnCounts)->map(fn ($count, $column) => [$column, $count])->values()->all(),
            );
        }

        return self::SUCCESS;
    }
}
