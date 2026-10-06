<?php

namespace Platform\Recruiting\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Models\RecApplicant;

/** Text → Datumsfeld beim Umsetzen in eine neue Stelle (Befund 06.10.2026). */
final class AlsIsoDatumTest extends TestCase
{
    public function test_deutsches_format_wird_umgewandelt(): void
    {
        $this->assertSame('2008-05-01', RecApplicant::alsIsoDatum('01.05.2008'));
        $this->assertSame('2008-05-01', RecApplicant::alsIsoDatum(' 1.5.2008 '));
    }

    public function test_iso_bleibt(): void
    {
        $this->assertSame('2003-02-17', RecApplicant::alsIsoDatum('2003-02-17'));
    }

    public function test_unlesbares_oder_unmoegliches_wird_null(): void
    {
        $this->assertNull(RecApplicant::alsIsoDatum('31.02.2008'));
        $this->assertNull(RecApplicant::alsIsoDatum('Mai 2008'));
        $this->assertNull(RecApplicant::alsIsoDatum(''));
        $this->assertNull(RecApplicant::alsIsoDatum(null));
        $this->assertNull(RecApplicant::alsIsoDatum('05/01/2008'));
    }
}
