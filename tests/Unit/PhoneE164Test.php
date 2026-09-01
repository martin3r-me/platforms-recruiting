<?php

namespace Platform\Recruiting\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Support\PhoneE164;

/**
 * Die fuenf Format-Klassen aus dem Prod-Befund vom 01.09. (Query ueber
 * rec_employees: 207x national, 48x "sonstig" mit Unicode-Resten) muessen
 * alle sauber nach E.164 kommen; Unheilbares liefert null.
 */
class PhoneE164Test extends TestCase
{
    public function test_national_with_leading_zero(): void
    {
        $this->assertSame('+4917624533557', PhoneE164::normalize('017624533557'));
    }

    public function test_bare_number_without_zero_and_plus(): void
    {
        $this->assertSame('+4917661258620', PhoneE164::normalize('17661258620'));
        $this->assertSame('+491715303721', PhoneE164::normalize('1715303721'));
    }

    public function test_spaces_and_odd_grouping(): void
    {
        $this->assertSame('+4917681350994', PhoneE164::normalize('0 176 81350994'));
        $this->assertSame('+4915733957222', PhoneE164::normalize('01573 3957222'));
    }

    public function test_invisible_unicode_marks_are_stripped(): void
    {
        $this->assertSame('+491605069269', PhoneE164::normalize("\u{202A}+49 160 5069269\u{202C}"));
        $this->assertSame('+4915228451328', PhoneE164::normalize("\u{202A}+49 15228451328\u{202C}"));
    }

    public function test_already_e164_stays_and_foreign_prefix_is_respected(): void
    {
        $this->assertSame('+4915738762915', PhoneE164::normalize('+49 15738762915'));
        $this->assertSame('+436601234567', PhoneE164::normalize('0043 660 1234567'), '00-Praefix = international');
    }

    public function test_garbage_and_empty_yield_null(): void
    {
        $this->assertNull(PhoneE164::normalize('keine Nummer'));
        $this->assertNull(PhoneE164::normalize('123'));
        $this->assertNull(PhoneE164::normalize(''));
        $this->assertNull(PhoneE164::normalize(null));
    }

    public function test_missing_or_doubled_49_prefix_is_repaired_to_the_valid_mobile(): void
    {
        // Prod-Befund 01.09.: "49..." ohne '+' liest sich sonst als Festnetz 0491/Leer.
        $this->assertSame('+491783756394', PhoneE164::normalize('491783756394'));
        $this->assertSame('+491637983400', PhoneE164::normalize('491637983400'));
        $this->assertSame('+491788457275', PhoneE164::normalize('+49491788457275'), 'doppeltes 49 nach dem +');
    }

    public function test_two_numbers_in_one_field_take_the_first_valid(): void
    {
        $this->assertSame('+4917673678214', PhoneE164::normalize('+49 176 73678214+4915735597660'));
    }

    public function test_real_landline_with_leading_zero_is_not_reinterpreted(): void
    {
        $this->assertSame('+492161823900', PhoneE164::normalize('02161823900'));
    }

    public function test_fixed_line_is_detected_for_the_customer_rest_list(): void
    {
        $e164 = PhoneE164::normalize('02161823900');
        $this->assertSame('+492161823900', $e164, 'Festnetz ist formal gueltig ...');
        $this->assertTrue(PhoneE164::isFixedLine($e164), '... wird aber als Festnetz gelistet');
        $this->assertFalse(PhoneE164::isFixedLine('+4917624533557'));
    }
}
