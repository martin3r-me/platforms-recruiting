<?php

namespace Platform\Recruiting\Tests\Unit;

use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Services\Zeit\RheingedeckZeitRegeln;
use Platform\Recruiting\Zeit\ZeitBezug;

class RheingedeckZeitRegelnTest extends TestCase
{
    private function bezug(?string $von, ?string $bis): ZeitBezug
    {
        return new ZeitBezug(
            'rec_employee', 1, 'rec_dispo_assignment', 1, '2026-10-01',
            $von === null ? null : CarbonImmutable::parse($von, 'Europe/Berlin'),
            $bis === null ? null : CarbonImmutable::parse($bis, 'Europe/Berlin'),
        );
    }

    public function test_fenster_eine_stunde_vor_und_nach_der_schicht(): void
    {
        $r = new RheingedeckZeitRegeln();
        $b = $this->bezug('2026-10-01 14:00', '2026-10-01 22:00');

        $this->assertTrue($r->fruehestesKommen($b)->equalTo(CarbonImmutable::parse('2026-10-01 13:00', 'Europe/Berlin')));
        $this->assertTrue($r->spaetestesStempeln($b)->equalTo(CarbonImmutable::parse('2026-10-01 23:00', 'Europe/Berlin')));
    }

    public function test_ohne_soll_von_gibt_es_kein_fruehestes_kommen(): void
    {
        $r = new RheingedeckZeitRegeln();
        $this->assertNull($r->fruehestesKommen($this->bezug(null, '2026-10-01 22:00')));
    }

    public function test_ohne_soll_bis_gibt_es_kein_spaetestes_stempeln(): void
    {
        $r = new RheingedeckZeitRegeln();
        $this->assertNull($r->spaetestesStempeln($this->bezug('2026-10-01 14:00', null)));
    }

    public function test_pausenstufen(): void
    {
        $r = new RheingedeckZeitRegeln();
        $this->assertSame(0, $r->pauseMinuten(0));
        $this->assertSame(0, $r->pauseMinuten(360));
        $this->assertSame(30, $r->pauseMinuten(361));
        $this->assertSame(30, $r->pauseMinuten(540));
        $this->assertSame(45, $r->pauseMinuten(541));
    }

    public function test_konstanten(): void
    {
        $this->assertSame(60, RheingedeckZeitRegeln::VOR_MINUTEN);
        $this->assertSame(60, RheingedeckZeitRegeln::NACH_MINUTEN);
    }
}
