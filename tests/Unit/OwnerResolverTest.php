<?php

namespace Platform\Recruiting\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Services\OwnerResolver;

class OwnerResolverTest extends TestCase
{
    public function test_existing_applicant_owner_wins(): void
    {
        // Manuell gesetzter Owner darf nie überschrieben werden.
        $this->assertSame(5, OwnerResolver::resolve(5, 9, 3, 1));
    }

    public function test_falls_back_to_position_owner(): void
    {
        $this->assertSame(9, OwnerResolver::resolve(null, 9, 3, 1));
    }

    public function test_falls_back_to_default_contact(): void
    {
        $this->assertSame(3, OwnerResolver::resolve(null, null, 3, 1));
    }

    public function test_falls_back_to_team_owner(): void
    {
        $this->assertSame(1, OwnerResolver::resolve(null, null, null, 1));
    }

    public function test_returns_null_when_nothing_set(): void
    {
        $this->assertNull(OwnerResolver::resolve(null, null, null, null));
    }

    public function test_treats_zero_as_unset(): void
    {
        // 0 (häufiger "kein Wert"-Marker) wird wie null behandelt.
        $this->assertSame(9, OwnerResolver::resolve(0, 9, 0, 0));
    }
}
