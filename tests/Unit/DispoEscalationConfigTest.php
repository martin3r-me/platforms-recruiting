<?php

namespace Platform\Recruiting\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Services\Zas\Dispo\DispoEscalationConfig as C;

class DispoEscalationConfigTest extends TestCase
{
    private array $defaults = [1 => '14:00', 2 => '15:00', 3 => '16:00'];

    public function test_null_everything_is_default_vortag(): void
    {
        $cfg = C::effective(null, null, null, null, $this->defaults);
        $this->assertSame(['day' => 'vortag', 'times' => $this->defaults, 'overridden' => false], $cfg);
    }

    public function test_full_time_override_wins(): void
    {
        $cfg = C::effective(null, '10:00', '11:00', '12:00', $this->defaults);
        $this->assertSame([1 => '10:00', 2 => '11:00', 3 => '12:00'], $cfg['times']);
        $this->assertTrue($cfg['overridden']);
    }

    public function test_partial_or_invalid_times_fall_back_to_defaults(): void
    {
        $this->assertSame($this->defaults, C::effective(null, '10:00', null, '12:00', $this->defaults)['times']);
        $this->assertSame($this->defaults, C::effective(null, '10:00', '25:00', '12:00', $this->defaults)['times']);
    }

    public function test_einsatztag_mode_is_overridden_even_with_default_times(): void
    {
        $cfg = C::effective('einsatztag', null, null, null, $this->defaults);
        $this->assertSame('einsatztag', $cfg['day']);
        $this->assertTrue($cfg['overridden']);
        $this->assertSame('vortag', C::effective('irgendwas', null, null, null, $this->defaults)['day']);
    }

    public function test_applies_on(): void
    {
        $this->assertTrue(C::appliesOn('vortag', '2026-08-28', '2026-08-27'));
        $this->assertFalse(C::appliesOn('vortag', '2026-08-27', '2026-08-27'));
        $this->assertTrue(C::appliesOn('einsatztag', '2026-08-27', '2026-08-27'));
        $this->assertFalse(C::appliesOn('einsatztag', '2026-08-28', '2026-08-27'));
    }

    public function test_validate_ok_cases(): void
    {
        $this->assertSame([], C::validate('vortag', '', '', '', '10:00', $this->defaults));
        $this->assertSame([], C::validate('vortag', '12:00', '13:00', '14:00', null, $this->defaults));
        $this->assertSame([], C::validate('einsatztag', '07:00', '08:00', '09:00', '10:00', $this->defaults));
    }

    public function test_validate_partial_times(): void
    {
        $errors = C::validate('vortag', '12:00', '', '', null, $this->defaults);
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('alle drei', $errors[0]);
    }

    public function test_validate_order(): void
    {
        $errors = C::validate('vortag', '15:00', '14:00', '16:00', null, $this->defaults);
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('aufsteigend', $errors[0]);
    }

    public function test_validate_einsatztag_requires_stage3_before_shift_start_also_with_defaults(): void
    {
        $errors = C::validate('einsatztag', '', '', '', '10:00', $this->defaults);
        $this->assertNotEmpty($errors, 'Defaults 14/15/16 liegen nach Schichtbeginn 10:00.');
        $this->assertStringContainsString('10:00', $errors[0]);

        $this->assertSame([], C::validate('einsatztag', '', '', '', null, $this->defaults), 'Ohne bekannte Schichtzeit keine Schichtpruefung.');
    }

    public function test_validate_invalid_format(): void
    {
        $this->assertNotEmpty(C::validate('vortag', '9:00', '10:00', '11:00', null, $this->defaults));
        $this->assertNotEmpty(C::validate('sonstwas', '', '', '', null, $this->defaults));
    }
}
