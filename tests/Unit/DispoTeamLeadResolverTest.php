<?php

namespace Platform\Recruiting\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Services\Zas\Dispo\DispoTeamLeadResolver;

class DispoTeamLeadResolverTest extends TestCase
{
    private function contacts(): array
    {
        return [
            7 => ['name' => 'Sheran Kumar', 'first_name' => 'Sheran', 'phone' => '+49 170 1234567', 'portal_token' => 't7'],
            8 => ['name' => 'Kim Ohne', 'first_name' => 'Kim', 'phone' => null, 'portal_token' => 't8'],
            9 => ['name' => 'Ali Zwei', 'first_name' => 'Ali', 'phone' => '+49 160 1', 'portal_token' => 't9'],
        ];
    }

    public function test_exact_match_case_insensitive_and_trimmed(): void
    {
        $leads = (new DispoTeamLeadResolver())->resolve([
            ['employee_id' => 7, 'taetigkeit' => ' teamleitung ', 'datum' => '2026-08-28'],
            ['employee_id' => 9, 'taetigkeit' => 'Servicekräfte', 'datum' => '2026-08-28'],
        ], $this->contacts(), ['Teamleitung']);

        $this->assertCount(1, $leads);
        $this->assertSame(7, $leads[0]['employee_id']);
        $this->assertSame('Sheran Kumar (+49 170 1234567)', $leads[0]['label']);
    }

    public function test_variants_are_not_matched_unless_configured(): void
    {
        $rows = [['employee_id' => 7, 'taetigkeit' => 'Teamleitung Logisitk', 'datum' => '2026-08-28']];
        $this->assertSame([], (new DispoTeamLeadResolver())->resolve($rows, $this->contacts(), ['Teamleitung']));
        $this->assertCount(1, (new DispoTeamLeadResolver())->resolve($rows, $this->contacts(), ['Teamleitung', 'Teamleitung Logisitk']));
    }

    public function test_dedup_per_employee_over_multiple_days_and_order_is_first_occurrence(): void
    {
        $leads = (new DispoTeamLeadResolver())->resolve([
            ['employee_id' => 9, 'taetigkeit' => 'Teamleitung', 'datum' => '2026-08-28'],
            ['employee_id' => 7, 'taetigkeit' => 'Teamleitung', 'datum' => '2026-08-28'],
            ['employee_id' => 9, 'taetigkeit' => 'Teamleitung', 'datum' => '2026-08-29'],
        ], $this->contacts(), ['Teamleitung']);

        $this->assertSame([9, 7], array_column($leads, 'employee_id'));
    }

    public function test_lead_without_phone_has_name_only_label_and_null_phone(): void
    {
        $leads = (new DispoTeamLeadResolver())->resolve(
            [['employee_id' => 8, 'taetigkeit' => 'Teamleitung', 'datum' => '2026-08-28']],
            $this->contacts(), ['Teamleitung']
        );
        $this->assertSame('Kim Ohne', $leads[0]['label']);
        $this->assertNull($leads[0]['phone']);
    }

    public function test_unmatched_or_unknown_contact_is_skipped(): void
    {
        $leads = (new DispoTeamLeadResolver())->resolve([
            ['employee_id' => null, 'taetigkeit' => 'Teamleitung', 'datum' => '2026-08-28'],
            ['employee_id' => 99, 'taetigkeit' => 'Teamleitung', 'datum' => '2026-08-28'],
        ], $this->contacts(), ['Teamleitung']);
        $this->assertSame([], $leads);
    }

    public function test_only_day_filter(): void
    {
        $rows = [
            ['employee_id' => 7, 'taetigkeit' => 'Teamleitung', 'datum' => '2026-08-28'],
            ['employee_id' => 9, 'taetigkeit' => 'Teamleitung', 'datum' => '2026-08-29'],
        ];
        $leads = (new DispoTeamLeadResolver())->resolve($rows, $this->contacts(), ['Teamleitung'], '2026-08-29');
        $this->assertSame([9], array_column($leads, 'employee_id'));
    }

    public function test_empty_config_yields_nothing(): void
    {
        $rows = [['employee_id' => 7, 'taetigkeit' => 'Teamleitung', 'datum' => '2026-08-28']];
        $this->assertSame([], (new DispoTeamLeadResolver())->resolve($rows, $this->contacts(), []));
        $this->assertSame([], (new DispoTeamLeadResolver())->resolve($rows, $this->contacts(), ['', '  ']));
    }
}
