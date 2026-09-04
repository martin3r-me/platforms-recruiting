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
            'declined_at' => null,
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
    public function test_declined_exits_like_confirmed(): void {
        // Absage (Kunde 04.09.): keine Stufe mehr, auch keine Rausnahme.
        $this->assertNull($this->p->dueStage($this->state(['declined_at' => $this->at('14:30')]), $this->at('16:01'), $this->times));
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
    public function test_due_at_uses_absolute_datetimes_across_days(): void {
        // Eskalation pro Sendung: Plan liegt am Folgetag — am Sende-Tag passiert
        // nichts, am Plan-Tag laeuft der volle Zyklus.
        $due = [1 => new \DateTimeImmutable('2026-09-02 07:00'), 2 => new \DateTimeImmutable('2026-09-02 11:00'), 3 => new \DateTimeImmutable('2026-09-02 15:00')];
        $state = $this->state(['reminder_sent_at' => new \DateTimeImmutable('2026-09-01 18:00')]);
        $this->assertNull($this->p->dueStageAt($state, new \DateTimeImmutable('2026-09-01 19:00'), $due, 6), 'Vor dem Plan-Tag nichts.');
        $this->assertSame(1, $this->p->dueStageAt($state, new \DateTimeImmutable('2026-09-02 07:01'), $due, 6));
        $this->assertSame(3, $this->p->dueStageAt($state, new \DateTimeImmutable('2026-09-02 15:01'), $due, 6), 'Schonfrist (18:00+6h) ist am Plan-Tag laengst um.');
    }
    public function test_due_at_respects_grace_and_fairness_like_the_day_variant(): void {
        $due = [1 => new \DateTimeImmutable('2026-09-02 07:00'), 2 => new \DateTimeImmutable('2026-09-02 11:00'), 3 => new \DateTimeImmutable('2026-09-02 15:00')];
        // Ansprache erst NACH Stufe-2-Zeitpunkt -> Stufe 3 blockiert (Fairness).
        $late = $this->state(['reminder_sent_at' => new \DateTimeImmutable('2026-09-02 12:00')]);
        $this->assertNull($this->p->dueStageAt($late, new \DateTimeImmutable('2026-09-02 15:01'), $due, 0));
        // Ansprache 10:00, Schonfrist 6h -> Rausnahme erst ab 16:00.
        $mid = $this->state(['reminder_sent_at' => new \DateTimeImmutable('2026-09-02 10:00'),
            'escalation_1_at' => new \DateTimeImmutable('2026-09-02 07:00'), 'escalation_2_at' => new \DateTimeImmutable('2026-09-02 11:00')]);
        $this->assertNull($this->p->dueStageAt($mid, new \DateTimeImmutable('2026-09-02 15:01'), $due, 6));
        $this->assertSame(3, $this->p->dueStageAt($mid, new \DateTimeImmutable('2026-09-02 16:01'), $due, 6));
    }
    public function test_grace_defers_stage3_until_the_person_had_time_to_react(): void {
        // Neuversand 12:00 (Nummern-Nachzug), Schonfrist 6h: um 16:01 noch keine
        // Rausnahme - erst ab 18:00 (12:00 + 6h) feuert Stufe 3.
        $late = $this->state(['reminder_sent_at' => $this->at('12:00'),
            'escalation_1_at' => $this->at('14:00'), 'escalation_2_at' => $this->at('15:00')]);
        $this->assertNull($this->p->dueStage($late, $this->at('16:01'), $this->times, 6));
        $this->assertSame(3, $this->p->dueStage($late, $this->at('18:01'), $this->times, 6));
    }
    public function test_grace_changes_nothing_for_sends_days_before(): void {
        $this->assertSame(3, $this->p->dueStage(
            $this->state(['reminder_sent_at' => new \DateTimeImmutable('2026-08-30 10:00')]),
            $this->at('16:01'), $this->times, 6
        ));
    }
    public function test_grace_zero_keeps_old_behaviour(): void {
        $late = $this->state(['reminder_sent_at' => $this->at('12:00')]);
        $this->assertSame(3, $this->p->dueStage($late, $this->at('16:01'), $this->times, 0));
    }
    public function test_grace_does_not_touch_stage1_and_2(): void {
        // Erinnerungen sollen Nachzuegler gerade ERREICHEN - nur die Rausnahme wartet.
        $late = $this->state(['reminder_sent_at' => $this->at('12:00')]);
        $this->assertSame(1, $this->p->dueStage($late, $this->at('14:01'), $this->times, 6));
    }
    public function test_stage1_does_not_fire_after_stage2_already_stamped(): void {
        // Scheduler-Ausfall 14–15h: Stufe 2 lief um 15:01, danach darf Stufe 1 nicht nachrutschen
        $this->assertNull($this->p->dueStage(
            $this->state(['escalation_2_at' => $this->at('15:01')]),
            $this->at('15:06'), $this->times
        ));
    }
}
