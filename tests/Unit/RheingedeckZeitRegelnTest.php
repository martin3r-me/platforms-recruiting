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

    public function test_fenster_30_minuten_vor_bis_4_stunden_nach_der_schicht(): void
    {
        $r = new RheingedeckZeitRegeln();
        $b = $this->bezug('2026-10-01 14:00', '2026-10-01 22:00');

        $this->assertTrue($r->fruehestesKommen($b)->equalTo(CarbonImmutable::parse('2026-10-01 13:30', 'Europe/Berlin')));
        $this->assertTrue($r->spaetestesKommen($b)->equalTo(CarbonImmutable::parse('2026-10-01 22:00', 'Europe/Berlin')));
        $this->assertTrue($r->spaetestesStempeln($b)->equalTo(CarbonImmutable::parse('2026-10-02 02:00', 'Europe/Berlin')));
    }

    public function test_rundung_auf_die_naechste_viertelstunde(): void
    {
        $r = new RheingedeckZeitRegeln();
        $faelle = [
            '16:53:00' => '17:00:00',
            '16:52:29' => '16:45:00',
            '16:52:30' => '17:00:00', // die Mitte rundet auf
            '23:08:00' => '23:15:00',
            '23:07:29' => '23:00:00',
            '17:00:00' => '17:00:00',
            '23:55:00' => '00:00:00', // ueber Mitternacht
        ];
        foreach ($faelle as $ein => $aus) {
            $z = $r->runde(CarbonImmutable::parse('2026-10-01 ' . $ein, 'Europe/Berlin'));
            $this->assertSame($aus, $z->format('H:i:s'), $ein);
            $this->assertSame('Europe/Berlin', $z->getTimezone()->getName());
        }
        $this->assertSame('2026-10-02', $r->runde(CarbonImmutable::parse('2026-10-01 23:55:00', 'Europe/Berlin'))->format('Y-m-d'));
        // UTC-Eingang (so speichert die Host-App): dieselbe Viertelstunde.
        $this->assertSame('14:45:00', $r->runde(CarbonImmutable::parse('2026-10-01 14:52:00', 'UTC'))->format('H:i:s'));
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
        $this->assertNull($r->spaetestesKommen($this->bezug('2026-10-01 14:00', null)));
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
        $this->assertSame(30, RheingedeckZeitRegeln::VOR_MINUTEN);
        $this->assertSame(240, RheingedeckZeitRegeln::NACH_MINUTEN);
        $this->assertSame(15, RheingedeckZeitRegeln::RUNDUNG_MINUTEN);
    }
}
