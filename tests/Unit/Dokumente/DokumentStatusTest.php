<?php

namespace Platform\Recruiting\Tests\Unit\Dokumente;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Support\DokumentStatus;

final class DokumentStatusTest extends TestCase
{
    private function z(array $set = []): array
    {
        return array_merge(['withdrawn_at' => null, 'signed_at' => null, 'acknowledged_at' => null, 'first_viewed_at' => null], $set);
    }

    public function test_reihenfolge_zurueckgezogen_schlaegt_alles(): void
    {
        $this->assertSame('zurueckgezogen', DokumentStatus::fuer($this->z(['withdrawn_at' => '2026-10-09 10:00:00', 'signed_at' => '2026-10-08 10:00:00']), 'sign'));
    }

    public function test_sign_stufen(): void
    {
        $this->assertSame('offen', DokumentStatus::fuer($this->z(), 'sign'));
        $this->assertSame('gesehen', DokumentStatus::fuer($this->z(['first_viewed_at' => '2026-10-09 10:00:00']), 'sign'));
        $this->assertSame('bestaetigt', DokumentStatus::fuer($this->z(['first_viewed_at' => '2026-10-09 10:00:00', 'acknowledged_at' => '2026-10-09 10:01:00']), 'sign'));
        $this->assertSame('unterschrieben', DokumentStatus::fuer($this->z(['signed_at' => '2026-10-09 10:02:00']), 'sign'));
    }

    public function test_acknowledge_kennt_keine_unterschrift(): void
    {
        $this->assertSame('bestaetigt', DokumentStatus::fuer($this->z(['acknowledged_at' => '2026-10-09 10:01:00']), 'acknowledge'));
        $this->assertTrue(DokumentStatus::istErledigt($this->z(['acknowledged_at' => '2026-10-09 10:01:00']), 'acknowledge'));
        $this->assertFalse(DokumentStatus::istErledigt($this->z(['acknowledged_at' => '2026-10-09 10:01:00']), 'sign'));
    }

    public function test_none_ist_abgelegt_oder_gesehen(): void
    {
        $this->assertSame('abgelegt', DokumentStatus::fuer($this->z(), 'none'));
        $this->assertSame('gesehen', DokumentStatus::fuer($this->z(['first_viewed_at' => '2026-10-09 10:00:00']), 'none'));
        $this->assertTrue(DokumentStatus::istErledigt($this->z(), 'none'), 'nur ablegen verlangt nichts');
    }

    public function test_labels(): void
    {
        $this->assertSame('Unterschrieben', DokumentStatus::label('unterschrieben'));
        $this->assertSame('Zurückgezogen', DokumentStatus::label('zurueckgezogen'));
        $this->assertSame('unbekannt', DokumentStatus::label('unbekannt'));
    }
}
