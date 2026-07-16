<?php

namespace Platform\Recruiting\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Support\PublicAddressStyle;

class PublicAddressStyleTest extends TestCase
{
    public function test_true_wird_als_duzen_erkannt(): void
    {
        $this->assertTrue(PublicAddressStyle::informal(true));
        $this->assertTrue(PublicAddressStyle::informal(1));
        $this->assertTrue(PublicAddressStyle::informal('1'));
        $this->assertTrue(PublicAddressStyle::informal('true'));
        $this->assertTrue(PublicAddressStyle::informal(' TRUE '));
    }

    public function test_false_und_fehlende_werte_bleiben_sie(): void
    {
        $this->assertFalse(PublicAddressStyle::informal(false));
        $this->assertFalse(PublicAddressStyle::informal(null));
        $this->assertFalse(PublicAddressStyle::informal(0));
        $this->assertFalse(PublicAddressStyle::informal('0'));
        $this->assertFalse(PublicAddressStyle::informal(''));
        $this->assertFalse(PublicAddressStyle::informal('false'));
    }

    public function test_unbekannte_werte_fallen_auf_sie_zurueck(): void
    {
        // Default muss IMMER Sie sein — nur explizites Aktivieren duzt.
        $this->assertFalse(PublicAddressStyle::informal('du'));
        $this->assertFalse(PublicAddressStyle::informal('ja'));
        $this->assertFalse(PublicAddressStyle::informal(2));
        $this->assertFalse(PublicAddressStyle::informal([]));
        $this->assertFalse(PublicAddressStyle::informal(['use_informal_address' => true]));
        $this->assertFalse(PublicAddressStyle::informal(1.0));
    }
}
