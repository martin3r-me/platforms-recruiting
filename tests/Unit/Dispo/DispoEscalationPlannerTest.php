<?php
namespace Platform\Recruiting\Tests\Unit\Dispo;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Services\Zas\Dispo\DispoEscalationPlanner;

class DispoEscalationPlannerTest extends TestCase
{
    private DispoEscalationPlanner $p;
    private array $times = ['1' => '14:00', '2' => '15:00', '3' => '16:00'];
    protected function setUp(): void { $this->p = new DispoEscalationPlanner(); }

    private function at(string $t): \DateTimeImmutable { return new \DateTimeImmutable("2026-09-01 {$t}"); }
    private function state(array $o = []): array {
        return array_merge([
            'reminder_sent_at' => $this->at('08:00'), 'confirmed_at' => null,
            'escalation_1_at' => null, 'escalation_2_at' => null, 'deletion_marked_at' => null,
        ], $o);
    }

    public function test_stage1_due_after_time1(): void {
        $this->assertSame(1, $this->p->dueStage($this->state(), $this->at('14:01'), $this->times));
    }
    public function test_stage1_not_before_time1(): void {
        $this->assertNull($this->p->dueStage($this->state(), $this->at('13:59'), $this->times));
    }
    public function test_stage2_due_after_time2(): void {
        $this->assertSame(2, $this->p->dueStage($this->state(['escalation_1_at' => $this->at('14:00')]), $this->at('15:01'), $this->times));
    }
    public function test_stage3_removal_after_time3(): void {
        $this->assertSame(3, $this->p->dueStage($this->state(['escalation_1_at' => $this->at('14:00'), 'escalation_2_at' => $this->at('15:00')]), $this->at('16:01'), $this->times));
    }
    public function test_confirmed_exits(): void {
        $this->assertNull($this->p->dueStage($this->state(['confirmed_at' => $this->at('14:30')]), $this->at('16:01'), $this->times));
    }
    public function test_already_removed_exits(): void {
        $this->assertNull($this->p->dueStage($this->state(['deletion_marked_at' => $this->at('16:00')]), $this->at('16:05'), $this->times));
    }
    public function test_already_stamped_stage1_not_refired(): void {
        // now zwischen t1 und t2, Stufe 1 schon gestempelt -> nichts faellig
        $this->assertNull($this->p->dueStage($this->state(['escalation_1_at' => $this->at('14:00')]), $this->at('14:30'), $this->times));
    }
    public function test_fairness_guard_late_send_skips_stage(): void {
        // Erstversand erst 15:30 -> um 16:01 weder Stufe1/2 (reminder_sent_at > deren Zeit) noch Stufe3 (reminder_sent_at > 16:00)
        $this->assertNull($this->p->dueStage($this->state(['reminder_sent_at' => $this->at('15:30')]), $this->at('16:01'), $this->times));
    }
    public function test_not_sent_yields_null(): void {
        $this->assertNull($this->p->dueStage($this->state(['reminder_sent_at' => null]), $this->at('16:01'), $this->times));
    }
    public function test_stage3_wins_even_when_prior_stages_unstamped(): void {
        // Verpasstes Fenster: um 16:01 unbestaetigt, keine Vorstempel -> sofort Stufe 3
        $this->assertSame(3, $this->p->dueStage($this->state(), $this->at('16:01'), $this->times));
    }
    public function test_stage1_does_not_fire_after_stage2_already_stamped(): void {
        // Scheduler-Ausfall 14–15h: Stufe 2 lief um 15:01, danach darf Stufe 1 nicht nachrutschen
        $this->assertNull($this->p->dueStage(
            $this->state(['escalation_2_at' => $this->at('15:01')]),
            $this->at('15:06'), $this->times
        ));
    }
}
