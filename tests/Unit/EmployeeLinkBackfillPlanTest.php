<?php

namespace Platform\Recruiting\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Support\EmployeeLinkBackfillPlan;

/**
 * Die Schreib-Regel des Link-Backfills: was nachgetragen wird und was
 * Handarbeit bleibt.
 *
 * Der teure Fehler waere hier NICHT der fehlende Link, sondern der falsche:
 * ZasEmployeeFileController loest die Personalakte eines Mitarbeiters ueber
 * rec_applicant_id auf, ein Fehlgriff haengt also die Vertraege eines fremden
 * Menschen in seine Akte.
 */
class EmployeeLinkBackfillPlanTest extends TestCase
{
    public function test_single_claim_is_written(): void
    {
        $plan = EmployeeLinkBackfillPlan::build([81 => [501]]);

        $this->assertSame([81 => 501], $plan['link']);
        $this->assertSame([], $plan['ambiguous']);
    }

    /** Zwei Bewerber auf denselben MA: Namensvettern mit gleichem Geburtsdatum. */
    public function test_contested_employee_is_never_written(): void
    {
        $plan = EmployeeLinkBackfillPlan::build([81 => [501, 777]]);

        $this->assertSame([], $plan['link']);
        $this->assertSame([81 => [501, 777]], $plan['ambiguous']);
    }

    /** Derselbe Bewerber mehrfach gemeldet ist ein Anspruch, kein Konflikt. */
    public function test_duplicate_claim_of_the_same_applicant_collapses(): void
    {
        $plan = EmployeeLinkBackfillPlan::build([81 => [501, 501]]);

        $this->assertSame([81 => 501], $plan['link']);
        $this->assertSame([], $plan['ambiguous']);
    }

    /**
     * Ein Bewerber mit zwei Mitarbeiter-Zeilen ist erlaubt: ZAS bedient zwei
     * Firmen (RG-/MA-Nummer), eine Person kann bei beiden angestellt sein.
     */
    public function test_one_applicant_may_claim_two_employees(): void
    {
        $plan = EmployeeLinkBackfillPlan::build([21 => [1090], 22 => [1090]]);

        $this->assertSame([21 => 1090, 22 => 1090], $plan['link']);
        $this->assertSame([], $plan['ambiguous']);
    }

    public function test_mixed_input_splits_cleanly(): void
    {
        $plan = EmployeeLinkBackfillPlan::build([
            99 => [3],
            81 => [501, 777],
            12 => [42],
        ]);

        $this->assertSame([12 => 42, 99 => 3], $plan['link']);
        $this->assertSame([81 => [501, 777]], $plan['ambiguous']);
    }

    public function test_empty_input_yields_empty_plan(): void
    {
        $this->assertSame(['link' => [], 'ambiguous' => []], EmployeeLinkBackfillPlan::build([]));
    }
}
