<?php

namespace Platform\Recruiting\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Support\ProofReminderPlanner;

final class ProofReminderPlannerTest extends TestCase
{
    private function nachweis(array $a = []): array
    {
        return array_merge([
            'id' => 1, 'rec_employee_id' => 10, 'proof_type_code' => 'ausweis',
            'valid_until' => '2026-10-10', 'reminded_at' => null, 'superseded_at' => null,
        ], $a);
    }

    public function test_erinnert_innerhalb_der_vorlaufzeit(): void
    {
        // ausweis: 30 Tage Vorlauf
        $plan = ProofReminderPlanner::plan([$this->nachweis(['valid_until' => '2026-10-10'])], '2026-09-24', null);
        $this->assertCount(1, $plan);
    }

    public function test_erinnert_nicht_zu_frueh(): void
    {
        $plan = ProofReminderPlanner::plan([$this->nachweis(['valid_until' => '2027-05-01'])], '2026-09-24', null);
        $this->assertSame([], $plan);
    }

    public function test_erinnert_nur_einmal(): void
    {
        $plan = ProofReminderPlanner::plan([$this->nachweis(['reminded_at' => '2026-09-20 08:00:00'])], '2026-09-24', null);
        $this->assertSame([], $plan);
    }

    public function test_abgeloeste_fassung_zaehlt_nicht(): void
    {
        $plan = ProofReminderPlanner::plan([$this->nachweis(['superseded_at' => '2026-09-01 10:00:00'])], '2026-09-24', null);
        $this->assertSame([], $plan);
    }

    public function test_stichtag_haelt_den_altbestand_zurueck(): void
    {
        // Das ist die Bremse gegen die 500-WhatsApp-Welle.
        $plan = ProofReminderPlanner::plan([
            $this->nachweis(['id' => 1, 'valid_until' => '2026-08-01']),   // vor dem Stichtag
            $this->nachweis(['id' => 2, 'valid_until' => '2026-10-10']),   // danach
        ], '2026-09-24', '2026-10-01');

        $this->assertSame([2], array_column($plan, 'proof_id'));
    }

    public function test_ohne_stichtag_kommt_auch_der_altbestand(): void
    {
        $plan = ProofReminderPlanner::plan([$this->nachweis(['valid_until' => '2026-08-01'])], '2026-09-24', null);
        $this->assertCount(1, $plan);
    }

    public function test_arten_ohne_ablauf_kommen_nie(): void
    {
        $plan = ProofReminderPlanner::plan([$this->nachweis(['proof_type_code' => 'selfie', 'valid_until' => null])], '2026-09-24', null);
        $this->assertSame([], $plan);
    }
}
