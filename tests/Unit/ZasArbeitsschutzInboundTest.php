<?php

namespace Platform\Recruiting\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Services\Zas\ZasInboundRowMapper;
use Platform\Recruiting\Services\Zas\ZasLookupReverseResolver;

class ZasArbeitsschutzInboundTest extends TestCase
{
    private function map(array $row): array
    {
        return (new ZasInboundRowMapper(new ZasLookupReverseResolver()))->map($row);
    }

    public function test_maps_bools_and_date(): void
    {
        $res = $this->map([
            'Ersthelfer'              => 'Ja',
            'ErsthelferBis'           => '01.03.2027',
            'Sicherheitsbeauftragter' => 'Nein',
        ]);
        $this->assertTrue($res['employee']['is_first_aider']);
        $this->assertSame('2027-03-01', $res['employee']['first_aider_valid_until']);
        $this->assertFalse($res['employee']['is_safety_officer']);
        $this->assertSame([], $res['warnings']);
    }

    public function test_empty_values_stay_unset(): void
    {
        $res = $this->map(['Ersthelfer' => '', 'ErsthelferBis' => '', 'Sicherheitsbeauftragter' => '']);
        $this->assertArrayNotHasKey('is_first_aider', $res['employee']);
        $this->assertArrayNotHasKey('first_aider_valid_until', $res['employee']);
        $this->assertArrayNotHasKey('is_safety_officer', $res['employee']);
        $this->assertSame([], $res['warnings']);
    }

    public function test_warns_when_ersthelfer_ja_without_date(): void
    {
        $res = $this->map(['Ersthelfer' => 'Ja', 'ErsthelferBis' => '']);
        $this->assertTrue($res['employee']['is_first_aider']);
        $this->assertArrayNotHasKey('first_aider_valid_until', $res['employee']);
        $this->assertStringContainsString('Ersthelfer=Ja ohne', implode(' | ', $res['warnings']));
    }

    public function test_warns_when_ersthelfer_ja_with_invalid_date(): void
    {
        $res = $this->map(['Ersthelfer' => 'Ja', 'ErsthelferBis' => '31.02.2027']);
        $this->assertArrayNotHasKey('first_aider_valid_until', $res['employee']);
        $warnings = implode(' | ', $res['warnings']);
        // Bestehende Datums-Warnung UND neue Kopplungs-Warnung
        $this->assertStringContainsString('kein gueltiges Datum', $warnings);
        $this->assertStringContainsString('Ersthelfer=Ja ohne', $warnings);
    }

    public function test_no_warning_when_nein(): void
    {
        $res = $this->map(['Ersthelfer' => 'Nein']);
        $this->assertFalse($res['employee']['is_first_aider']);
        $this->assertSame([], $res['warnings']);
    }
}
