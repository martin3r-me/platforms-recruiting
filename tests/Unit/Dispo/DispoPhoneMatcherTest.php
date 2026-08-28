<?php

namespace Platform\Recruiting\Tests\Unit\Dispo;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Services\Zas\Dispo\DispoPhoneMatcher;

class DispoPhoneMatcherTest extends TestCase
{
    public function test_normalize_variants(): void
    {
        $this->assertSame('4917612345678', DispoPhoneMatcher::normalize('+49 176 12345678'));
        $this->assertSame('4917612345678', DispoPhoneMatcher::normalize('0049 176 12345678'));
        $this->assertSame('4917612345678', DispoPhoneMatcher::normalize('0176/123-456 78'));
        $this->assertSame('4917612345678', DispoPhoneMatcher::normalize('49 176 12345678'));
        $this->assertNull(DispoPhoneMatcher::normalize(''));
        $this->assertNull(DispoPhoneMatcher::normalize(null));
        $this->assertNull(DispoPhoneMatcher::normalize('keine'));
    }

    public function test_matches_normalized_equal_numbers(): void
    {
        $matcher = new DispoPhoneMatcher([7 => '+49 176 12345678', 8 => '0221 999888']);
        $this->assertSame(7, $matcher->match('017612345678'));
        $this->assertSame(8, $matcher->match('+49221999888'));
        $this->assertNull($matcher->match('+49 999 000000'));
        $this->assertNull($matcher->match(null));
    }

    public function test_ambiguous_numbers_never_match(): void
    {
        $matcher = new DispoPhoneMatcher([7 => '0176 12345678', 8 => '+4917612345678']);
        $this->assertNull($matcher->match('017612345678'));
    }

    public function test_null_and_empty_directory_entries_are_ignored(): void
    {
        $matcher = new DispoPhoneMatcher([7 => null, 8 => '', 9 => '0176 12345678']);
        $this->assertSame(9, $matcher->match('+49 176 12345678'));
    }

    public function test_multiple_phones_per_id_and_same_id_twice_is_not_ambiguous(): void
    {
        $m = new DispoPhoneMatcher([10 => ['+49 172 1', '0172 1'], 12 => '+49 160 9']);
        $this->assertSame(10, $m->match('+491721'));
        $this->assertSame(10, $m->match('01721'));
        $this->assertSame(12, $m->match('+49 160 9'));
    }

    public function test_two_different_ids_same_phone_remain_ambiguous(): void
    {
        $m = new DispoPhoneMatcher([10 => '+49 172 1', 11 => '+49 172 1']);
        $this->assertNull($m->match('+49 172 1'));
    }
}
