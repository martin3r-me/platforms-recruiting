<?php

namespace Platform\Recruiting\Tests\Unit;

use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Zeit\ZeitBezug;
use Platform\Recruiting\Zeit\ZeitRechner;
use Platform\Recruiting\Zeit\ZeitRegeln;

class ZeitRechnerTest extends TestCase
{
    private function regeln(): ZeitRegeln
    {
        return new class implements ZeitRegeln {
            public function fruehestesKommen(ZeitBezug $b): ?CarbonImmutable { return null; }
            public function spaetestesKommen(ZeitBezug $b): ?CarbonImmutable { return null; }
            public function spaetestesStempeln(ZeitBezug $b): ?CarbonImmutable { return null; }
            public function runde(\DateTimeInterface $zeit): CarbonImmutable { return CarbonImmutable::instance($zeit); }
            public function pauseMinuten(int $anwesendMinuten): int
            {
                return $anwesendMinuten > 540 ? 45 : ($anwesendMinuten > 360 ? 30 : 0);
            }
        };
    }

    public function test_pause_an_den_grenzen(): void
    {
        $r = $this->regeln();
        $this->assertSame(0, ZeitRechner::pauseMinuten(null, 360, $r));
        $this->assertSame(30, ZeitRechner::pauseMinuten(null, 361, $r));
        $this->assertSame(30, ZeitRechner::pauseMinuten(null, 540, $r));
        $this->assertSame(45, ZeitRechner::pauseMinuten(null, 541, $r));
    }

    public function test_gesetzte_pause_null_gewinnt_gegen_regel(): void
    {
        $this->assertSame(0, ZeitRechner::pauseMinuten(0, 600, $this->regeln()));
        $this->assertSame(15, ZeitRechner::pauseMinuten(15, 600, $this->regeln()));
    }

    public function test_ohne_anwesenheit_keine_pause(): void
    {
        $this->assertSame(0, ZeitRechner::pauseMinuten(null, null, $this->regeln()));
    }

    public function test_anwesend_minuten(): void
    {
        $k = new \DateTimeImmutable('2026-10-01 14:00');
        $g = new \DateTimeImmutable('2026-10-01 21:45');
        $this->assertSame(465, ZeitRechner::anwesendMinuten($k, $g));
        $this->assertNull(ZeitRechner::anwesendMinuten($k, null));
        $this->assertNull(ZeitRechner::anwesendMinuten(null, $g));
        $this->assertSame(0, ZeitRechner::anwesendMinuten($g, $k));
    }

    public function test_arbeitsminuten_nie_negativ(): void
    {
        $this->assertSame(0, ZeitRechner::arbeitsMinuten(20, 30));
        $this->assertSame(435, ZeitRechner::arbeitsMinuten(465, 30));
        $this->assertNull(ZeitRechner::arbeitsMinuten(null, 30));
    }

    public function test_verspaetung(): void
    {
        $soll = new \DateTimeImmutable('2026-10-01 14:00');
        $this->assertSame(0, ZeitRechner::verspaetungMinuten(new \DateTimeImmutable('2026-10-01 13:52'), $soll));
        $this->assertSame(12, ZeitRechner::verspaetungMinuten(new \DateTimeImmutable('2026-10-01 14:12'), $soll));
        $this->assertSame(0, ZeitRechner::verspaetungMinuten(null, $soll));
        $this->assertSame(0, ZeitRechner::verspaetungMinuten(new \DateTimeImmutable('2026-10-01 14:12'), null));
    }

    public function test_als_stunden(): void
    {
        $this->assertSame('7:45', ZeitRechner::alsStunden(465));
        $this->assertSame('0:05', ZeitRechner::alsStunden(5));
        $this->assertSame('0:00', ZeitRechner::alsStunden(0));
    }
}
