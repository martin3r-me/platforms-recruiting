<?php

namespace Platform\Recruiting\Tests\Unit\Dispo;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Services\Zas\Dispo\DispoIdentityGroups as G;

class DispoIdentityGroupsTest extends TestCase
{
    public function test_two_employees_on_same_contact_form_one_group(): void
    {
        $groups = G::build([10 => [500], 11 => [500]], [10, 11, 12]);
        $this->assertSame([10, 11], $groups[10]);
        $this->assertSame([10, 11], $groups[11]);
        $this->assertSame([12], $groups[12]);
    }

    public function test_no_link_is_singleton(): void
    {
        $this->assertSame([7 => [7]], G::build([], [7]));
    }

    public function test_inactive_member_is_excluded(): void
    {
        $groups = G::build([10 => [500], 11 => [500]], [10]); // 11 nicht aktiv
        $this->assertSame([10], $groups[10]);
        $this->assertArrayNotHasKey(11, $groups);
    }

    public function test_three_records_via_two_contacts_merge_transitively(): void
    {
        // 10+11 teilen Kontakt 500, 11+12 teilen Kontakt 501 -> alle drei eine Person
        $groups = G::build([10 => [500], 11 => [500, 501], 12 => [501]], [10, 11, 12]);
        $this->assertSame([10, 11, 12], $groups[10]);
        $this->assertSame([10, 11, 12], $groups[12]);
    }

    public function test_canonical_is_min_and_map(): void
    {
        $this->assertSame(10, G::canonical([11, 10, 12]));
        $this->assertSame([10 => 10, 11 => 10, 12 => 12], G::canonicalMap([10 => [10, 11], 11 => [10, 11], 12 => [12]]));
    }

    public function test_canonicalize_rewrites_key_and_keeps_null(): void
    {
        $rows = [['id' => 1, 'employee_id' => 11], ['id' => 2, 'employee_id' => 12], ['id' => 3, 'employee_id' => null]];
        $out = G::canonicalize($rows, [11 => 10, 12 => 12]);
        $this->assertSame([10, 12, null], array_column($out, 'employee_id'));
        $this->assertSame([1, 2, 3], array_column($out, 'id'));
    }
}
