<?php

namespace Platform\Recruiting\Tests\Unit;

use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Services\Zeit\ZeitAnzeige;

class ZeitAnzeigeTest extends TestCase
{
    private string $zone;

    protected function setUp(): void
    {
        $this->zone = date_default_timezone_get();
        date_default_timezone_set('UTC');
    }

    protected function tearDown(): void
    {
        date_default_timezone_set($this->zone);
    }

    public function test_sommerzeit_ist_zwei_stunden_vor_utc(): void
    {
        $utc = CarbonImmutable::parse('2026-07-01 12:00:00', 'UTC');
        $this->assertSame('14:00', ZeitAnzeige::uhrzeit($utc));
        $this->assertSame('Europe/Berlin', ZeitAnzeige::ortszeit($utc)->getTimezone()->getName());
    }

    public function test_winterzeit_ist_eine_stunde_vor_utc(): void
    {
        $this->assertSame('14:00', ZeitAnzeige::uhrzeit(CarbonImmutable::parse('2026-12-01 13:00:00', 'UTC')));
    }

    public function test_null_bleibt_leer(): void
    {
        $this->assertNull(ZeitAnzeige::ortszeit(null));
        $this->assertSame('', ZeitAnzeige::uhrzeit(null));
    }

    public function test_aus_eingabe_sommer_und_winter(): void
    {
        $s = ZeitAnzeige::ausEingabe('2026-07-01T14:00');
        $this->assertSame('2026-07-01 12:00', $s->utc()->format('Y-m-d H:i'));
        $w = ZeitAnzeige::ausEingabe('2026-12-01 14:00');
        $this->assertSame('2026-12-01 13:00', $w->utc()->format('Y-m-d H:i'));
    }

    public function test_aus_eingabe_ungueltig_ist_null(): void
    {
        $this->assertNull(ZeitAnzeige::ausEingabe(''));
        $this->assertNull(ZeitAnzeige::ausEingabe('morgen'));
        $this->assertNull(ZeitAnzeige::ausEingabe('2026-13-45T25:99'));
        $this->assertNull(ZeitAnzeige::ausEingabe('2026-07-01'));
    }
}
