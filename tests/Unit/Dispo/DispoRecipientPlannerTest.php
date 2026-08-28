<?php

namespace Platform\Recruiting\Tests\Unit\Dispo;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Services\Zas\Dispo\DispoRecipientPlanner;

class DispoRecipientPlannerTest extends TestCase
{
    private DispoRecipientPlanner $planner;

    protected function setUp(): void
    {
        $this->planner = new DispoRecipientPlanner();
    }

    /** @return array<string, mixed> */
    private function row(array $overrides = []): array
    {
        return $overrides + [
            'id' => 1, 'employee_id' => 7, 'status_id' => 1,
            'confirmed_at' => null, 'reminder_sent_at' => null,
            'missing_since' => null, 'deletion_marked_at' => null,
            'datum' => '2026-08-20',
        ];
    }

    public function test_groups_per_employee_with_first_date(): void
    {
        $result = $this->planner->plan([
            $this->row(['id' => 1, 'datum' => '2026-08-21']),
            $this->row(['id' => 2, 'datum' => '2026-08-20']),
            $this->row(['id' => 3, 'employee_id' => 8]),
        ], [7 => '+49111', 8 => '+49222'], false);

        $this->assertCount(2, $result['recipients']);
        $first = $result['recipients'][0];
        $this->assertSame(7, $first['employee_id']);
        $this->assertSame([1, 2], $first['assignment_ids']);
        $this->assertSame('2026-08-20', $first['first_datum']);
        $this->assertFalse($first['is_reminder']);
    }

    public function test_skips_wrong_status_missing_marked(): void
    {
        $result = $this->planner->plan([
            $this->row(['id' => 1, 'status_id' => 0]),
            $this->row(['id' => 2, 'status_id' => 3]),
            $this->row(['id' => 3, 'missing_since' => '2026-08-14 10:00:00']),
            $this->row(['id' => 4, 'deletion_marked_at' => '2026-08-14 10:00:00']),
        ], [7 => '+49111'], false);

        $this->assertSame([], $result['recipients']);
        $this->assertSame(2, $result['skipped']['wrong_status']);
        $this->assertSame(1, $result['skipped']['missing']);
        $this->assertSame(1, $result['skipped']['deletion_marked']);
    }

    public function test_skips_unmatched_and_without_phone(): void
    {
        $result = $this->planner->plan([
            $this->row(['id' => 1, 'employee_id' => null]),
            $this->row(['id' => 2, 'employee_id' => 9]),   // keine Nummer in $phones
            $this->row(['id' => 3, 'employee_id' => 10]),  // Nummer null
        ], [10 => null], false);

        $this->assertSame([], $result['recipients']);
        $this->assertSame(1, $result['skipped']['not_matched']);
        $this->assertSame(2, $result['skipped']['no_phone']);
    }

    public function test_skips_confirmed_and_already_sent_without_reminder_flag(): void
    {
        $result = $this->planner->plan([
            $this->row(['id' => 1, 'confirmed_at' => '2026-08-14 09:00:00']),
            $this->row(['id' => 2, 'reminder_sent_at' => '2026-08-13 09:00:00']),
        ], [7 => '+49111'], false);

        $this->assertSame([], $result['recipients']);
        $this->assertSame(1, $result['skipped']['confirmed']);
        $this->assertSame(1, $result['skipped']['already_sent']);
    }

    public function test_reminder_flag_includes_already_sent_and_marks_is_reminder(): void
    {
        $result = $this->planner->plan([
            $this->row(['id' => 1, 'reminder_sent_at' => '2026-08-13 09:00:00']),
            $this->row(['id' => 2, 'confirmed_at' => '2026-08-14 09:00:00']),
        ], [7 => '+49111'], true);

        $this->assertCount(1, $result['recipients']);
        $this->assertSame([1], $result['recipients'][0]['assignment_ids']);
        $this->assertTrue($result['recipients'][0]['is_reminder']);
        $this->assertSame(1, $result['skipped']['confirmed']); // bestaetigt bleibt draussen
    }

    public function test_mixed_employee_fresh_and_sent(): void
    {
        // Ein MA: ein frischer + ein bereits angeschriebener Einsatz, ohne Reminder-Flag:
        // nur der frische geht raus, is_reminder=false.
        $result = $this->planner->plan([
            $this->row(['id' => 1]),
            $this->row(['id' => 2, 'reminder_sent_at' => '2026-08-13 09:00:00']),
        ], [7 => '+49111'], false);

        $this->assertSame([1], $result['recipients'][0]['assignment_ids']);
        $this->assertFalse($result['recipients'][0]['is_reminder']);
        $this->assertSame(1, $result['skipped']['already_sent']);
    }

    public function test_canonicalized_rows_of_one_person_become_one_recipient(): void
    {
        $rows = [
            ['id' => 1, 'employee_id' => 10, 'status_id' => 1, 'confirmed_at' => null, 'reminder_sent_at' => null, 'missing_since' => null, 'deletion_marked_at' => null, 'datum' => '2026-09-01'],
            ['id' => 2, 'employee_id' => 11, 'status_id' => 1, 'confirmed_at' => null, 'reminder_sent_at' => null, 'missing_since' => null, 'deletion_marked_at' => null, 'datum' => '2026-09-02'],
        ];
        $rows = \Platform\Recruiting\Services\Zas\Dispo\DispoIdentityGroups::canonicalize($rows, [10 => 10, 11 => 10]);
        $result = (new DispoRecipientPlanner())->plan($rows, [10 => '+49 172 1'], false);
        $this->assertCount(1, $result['recipients']);
        $this->assertSame([1, 2], $result['recipients'][0]['assignment_ids']);
        $this->assertSame('2026-09-01', $result['recipients'][0]['first_datum']);
    }
}
