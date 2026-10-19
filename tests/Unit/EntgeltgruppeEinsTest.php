<?php

namespace Platform\Recruiting\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Support\EntgeltgruppeEins;

final class EntgeltgruppeEinsTest extends TestCase
{
    public function test_drei_stufen_aus_dem_tarif(): void
    {
        $this->assertSame(14.96, EntgeltgruppeEins::satz('2026-01-01'));
        $this->assertSame(14.96, EntgeltgruppeEins::satz('2026-08-31'));
        $this->assertSame(15.33, EntgeltgruppeEins::satz('2026-09-01'));
        $this->assertSame(15.33, EntgeltgruppeEins::satz('2027-03-31'));
        $this->assertSame(15.87, EntgeltgruppeEins::satz('2027-04-01'));
        $this->assertSame(15.87, EntgeltgruppeEins::satz('2030-12-31'));
    }

    public function test_vor_dem_tarif_gilt_die_erste_stufe(): void
    {
        $this->assertSame(14.96, EntgeltgruppeEins::satz('2025-12-31'));
        $this->assertSame('2026-01-01', EntgeltgruppeEins::gueltigAb('2025-12-31'));
    }

    public function test_datum_mit_uhrzeit_wird_auf_den_tag_gekuerzt(): void
    {
        $this->assertSame(15.33, EntgeltgruppeEins::satz('2026-09-01 00:00:00'));
        $this->assertSame('2026-09-01', EntgeltgruppeEins::gueltigAb('2026-10-19 12:00:00'));
    }
}
