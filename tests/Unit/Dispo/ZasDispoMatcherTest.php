<?php

namespace Platform\Recruiting\Tests\Unit\Dispo;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Services\Zas\Dispo\ZasDispoMatcher;

class ZasDispoMatcherTest extends TestCase
{
    public function test_exact_match_wins(): void
    {
        $matcher = new ZasDispoMatcher(['RG14' => 7, '14' => 9]);
        $this->assertSame(['employee_id' => 7, 'reason' => 'exact'], $matcher->match('RG14'));
    }

    public function test_digits_match_when_no_exact(): void
    {
        $matcher = new ZasDispoMatcher(['14' => 9]);
        $this->assertSame(['employee_id' => 9, 'reason' => 'digits'], $matcher->match('RG14'));
    }

    public function test_digits_match_reverse_direction(): void
    {
        // Bei uns steht 'RG14', ZAS liefert kuenftig vielleicht '14'
        $matcher = new ZasDispoMatcher(['RG14' => 7]);
        $this->assertSame(['employee_id' => 7, 'reason' => 'digits'], $matcher->match('14'));
    }

    public function test_ambiguous_digits_do_not_match(): void
    {
        // 'RG14' und 'MA14' haben beide Ziffern '14' -> mehrdeutig
        $matcher = new ZasDispoMatcher(['RG14' => 7, 'MA14' => 8]);
        $this->assertSame(['employee_id' => null, 'reason' => 'ambiguous'], $matcher->match('14'));
    }

    public function test_unknown_pnr(): void
    {
        $matcher = new ZasDispoMatcher(['RG14' => 7]);
        $this->assertSame(['employee_id' => null, 'reason' => 'none'], $matcher->match('RG999'));
    }

    public function test_empty_pnr(): void
    {
        $matcher = new ZasDispoMatcher(['RG14' => 7]);
        $this->assertSame(['employee_id' => null, 'reason' => 'empty'], $matcher->match(''));
        $this->assertSame(['employee_id' => null, 'reason' => 'empty'], $matcher->match(null));
    }

    public function test_pnr_without_digits_cannot_digits_match(): void
    {
        $matcher = new ZasDispoMatcher(['RG14' => 7]);
        $this->assertSame(['employee_id' => null, 'reason' => 'none'], $matcher->match('RG'));
    }
}
