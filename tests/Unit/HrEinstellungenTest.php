<?php

namespace Platform\Recruiting\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Models\RecApplicantSettings;
use Platform\Recruiting\Support\HrEinstellungen;

final class HrEinstellungenTest extends TestCase
{
    public function test_begrenze_jede_verzweigung(): void
    {
        $this->assertSame(5, HrEinstellungen::begrenze(null, 1, 60, 5));
        $this->assertSame(5, HrEinstellungen::begrenze('', 1, 60, 5), 'leeres Feld: Vorgabe, nicht 1');
        $this->assertSame(5, HrEinstellungen::begrenze('abc', 1, 60, 5));
        $this->assertSame(1, HrEinstellungen::begrenze('0', 1, 60, 5));
        $this->assertSame(60, HrEinstellungen::begrenze(61, 1, 60, 5));
        $this->assertSame(7, HrEinstellungen::begrenze('7', 1, 60, 5));
    }

    public function test_vorgaben_stehen_in_default_settings(): void
    {
        $this->assertFalse(RecApplicantSettings::DEFAULT_SETTINGS[HrEinstellungen::NOTFALL_WEG]);
        $this->assertSame(5, RecApplicantSettings::DEFAULT_SETTINGS[HrEinstellungen::ERINNERT_TAGE]);
        $this->assertSame(3, RecApplicantSettings::DEFAULT_SETTINGS[HrEinstellungen::RUHE_TAGE]);
    }
}
