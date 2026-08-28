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
        $this->assertSame(['day' => 'vortag', 'times' => $this->defaults, 'date' => null, 'overridden' => false], $cfg);
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

    public function test_datum_mode_carries_date_and_counts_as_override(): void
    {
        $cfg = C::effective('datum', null, null, null, $this->defaults, '2026-08-29');
        $this->assertSame('datum', $cfg['day']);
        $this->assertSame('2026-08-29', $cfg['date']);
        $this->assertTrue($cfg['overridden']);
    }

    public function test_datum_mode_without_valid_date_falls_back_to_vortag(): void
    {
        $this->assertSame('vortag', C::effective('datum', null, null, null, $this->defaults, null)['day']);
        $this->assertSame('vortag', C::effective('datum', null, null, null, $this->defaults, '29.08.2026')['day']);
        $this->assertNull(C::effective('einsatztag', null, null, null, $this->defaults, '2026-08-29')['date'], 'date nur im datum-Modus');
    }

    public function test_applies_on_datum_mode_for_all_upcoming_days_of_the_event(): void
    {
        $this->assertTrue(C::appliesOn('datum', '2026-09-02', '2026-08-29', '2026-08-29'), 'Einsatz in 4 Tagen, heute = Eskalationsdatum');
        $this->assertTrue(C::appliesOn('datum', '2026-08-29', '2026-08-29', '2026-08-29'), 'Einsatz am Eskalationsdatum selbst');
        $this->assertFalse(C::appliesOn('datum', '2026-09-02', '2026-08-30', '2026-08-29'), 'anderer Lauftag');
        $this->assertFalse(C::appliesOn('datum', '2026-08-28', '2026-08-29', '2026-08-29'), 'vergangener Einsatztag');
        $this->assertFalse(C::appliesOn('datum', '2026-09-02', '2026-08-29', null), 'ohne Datum nie');
    }

    public function test_validate_datum_mode(): void
    {
        $d = $this->defaults;
        $this->assertSame([], C::validate('datum', '', '', '', '10:00', $d, '2026-08-29', '2026-08-28', '2026-09-02'));
        $this->assertNotEmpty(C::validate('datum', '', '', '', '10:00', $d, '', '2026-08-28', '2026-09-02'), 'Datum fehlt');
        $this->assertNotEmpty(C::validate('datum', '', '', '', '10:00', $d, '2026-08-27', '2026-08-28', '2026-09-02'), 'Vergangenheit');
        $this->assertNotEmpty(C::validate('datum', '', '', '', '10:00', $d, '2026-09-03', '2026-08-28', '2026-09-02'), 'nach erstem Einsatztag');
        // Datum = erster Einsatztag -> Einsatztag-Regel (Stufe 3 vor fruehestem von)
        $this->assertNotEmpty(C::validate('datum', '', '', '', '10:00', $d, '2026-09-02', '2026-08-28', '2026-09-02'), '16:00 ist nicht vor 10:00');
        $this->assertSame([], C::validate('datum', '07:00', '08:00', '09:00', '10:00', $d, '2026-09-02', '2026-08-28', '2026-09-02'));
    }
}
