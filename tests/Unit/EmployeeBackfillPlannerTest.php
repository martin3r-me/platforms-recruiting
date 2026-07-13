<?php

namespace Platform\Recruiting\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Support\EmployeeBackfillPlanner;

class EmployeeBackfillPlannerTest extends TestCase
{
    public function test_fills_columns_that_are_currently_empty(): void
    {
        $plan = EmployeeBackfillPlanner::plan(
            candidates: ['visumsblatt_file_id' => 1798, 'city' => 'Köln'],
            current:    ['visumsblatt_file_id' => null, 'city' => ''],
        );

        $this->assertSame(['visumsblatt_file_id' => 1798, 'city' => 'Köln'], $plan);
    }

    public function test_never_overwrites_existing_values(): void
    {
        // Manuelle Nachpflege (MA-Portal/HR) hat Vorrang vor dem Backfill.
        $plan = EmployeeBackfillPlanner::plan(
            candidates: [
                'visumsblatt_file_id' => 1798,
                'city'                => 'Köln',
                'has_car'             => true,
                'beschaftigungsort'   => ['koeln'],
            ],
            current: [
                'visumsblatt_file_id' => 2001,
                'city'                => 'Bonn',
                'has_car'             => false,   // false ist ein echter Wert, kein "leer"
                'beschaftigungsort'   => ['duesseldorf'],
            ],
        );

        $this->assertSame([], $plan);
    }

    public function test_empty_array_counts_as_empty(): void
    {
        $plan = EmployeeBackfillPlanner::plan(
            candidates: ['beschaftigungsort' => ['koeln']],
            current:    ['beschaftigungsort' => []],
        );

        $this->assertSame(['beschaftigungsort' => ['koeln']], $plan);
    }

    public function test_candidate_columns_missing_from_current_are_filled(): void
    {
        // Spalte nicht im current-Snapshot (z.B. frisch hinzugefuegte Spalte)
        // → behandelt wie leer.
        $plan = EmployeeBackfillPlanner::plan(
            candidates: ['zusatzblatt_back_file_id' => 55],
            current:    [],
        );

        $this->assertSame(['zusatzblatt_back_file_id' => 55], $plan);
    }

    public function test_null_candidates_are_ignored(): void
    {
        // Defensiv: resolve() liefert eigentlich keine nulls, aber der
        // Planner darf eine leere Quelle nie als "Update auf null" planen.
        $plan = EmployeeBackfillPlanner::plan(
            candidates: ['city' => null],
            current:    ['city' => null],
        );

        $this->assertSame([], $plan);
    }
}
