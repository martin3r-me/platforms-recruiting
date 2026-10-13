<?php

namespace Platform\Recruiting\Tests\Unit\Dispo\Jobs;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Services\Zas\Dispo\Jobs\DispoJobFortschritt;

final class DispoJobFortschrittTest extends TestCase
{
    private static function s(string $datum, string $von, string $taet, int $gesucht, int $zugesagt, int $eingetragen = 0, int $angekommen = 0): object
    {
        return (object) (compact('datum', 'von', 'gesucht', 'zugesagt', 'eingetragen', 'angekommen') + ['taetigkeit' => $taet]);
    }

    public function testSummenProzentUndReihenfolgeOffenVorVoll(): void
    {
        $voll1 = self::s('2026-10-20', '10:00', 'Zapfer', 2, 2, 1, 1);
        $offen1 = self::s('2026-10-21', '17:00', 'Service', 5, 3, 2);
        $offen2 = self::s('2026-10-20', '17:00', 'Zapfer', 3, 0);
        $voll2 = self::s('2026-10-19', '09:00', 'Koch', 1, 4);

        $f = DispoJobFortschritt::berechnen([$voll1, $offen1, $offen2, $voll2]);

        // besetzt = 2 + 3 + 0 + 1 (Koch: 4 Zusagen, aber nur 1 Platz)
        $this->assertSame(['gesucht' => 11, 'zugesagt' => 9, 'eingetragen' => 3, 'angekommen' => 1, 'besetzt' => 6, 'prozent' => 54], array_intersect_key($f, array_flip(['gesucht', 'zugesagt', 'eingetragen', 'angekommen', 'besetzt', 'prozent'])));
        $this->assertSame([$offen2, $offen1], $f['offen'], 'offene nach Datum, Beginn, Taetigkeit');
        $this->assertSame([$voll2, $voll1], $f['voll']);
    }

    public function testOhneSchichtenUndMitGesuchtNull(): void
    {
        $f = DispoJobFortschritt::berechnen([]);
        $this->assertSame(0, $f['prozent']);
        $this->assertSame([], $f['offen']);

        $null = self::s('2026-10-20', '10:00', 'Zapfer', 0, 0);
        $f = DispoJobFortschritt::berechnen([$null]);
        $this->assertSame(0, $f['prozent'], 'keine Division durch null');
        $this->assertSame([$null], $f['voll'], 'gesucht 0 gilt als voll');
    }

    public function testProzentIstBeiHundertGedeckelt(): void
    {
        $f = DispoJobFortschritt::berechnen([self::s('2026-10-20', '10:00', 'Zapfer', 2, 5)]);
        $this->assertSame(100, $f['prozent']);
        $this->assertSame(2, $f['besetzt']);
        $this->assertSame(5, $f['zugesagt'], 'zugesagt bleibt die rohe Summe');
    }

    public function testUeberfuellteSchichtDecktKeineLeereSchicht(): void
    {
        // Modus „sammeln“: A gesucht 2 / zugesagt 10, B gesucht 8 / zugesagt 0 — nicht „10 von 10“.
        $f = DispoJobFortschritt::berechnen([
            self::s('2026-10-20', '10:00', 'A', 2, 10),
            self::s('2026-10-20', '12:00', 'B', 8, 0),
        ]);

        $this->assertSame(10, $f['gesucht']);
        $this->assertSame(10, $f['zugesagt']);
        $this->assertSame(2, $f['besetzt']);
        $this->assertSame(20, $f['prozent']);
    }

    public function testGesuchtNullTraegtNichtsZuBesetztBei(): void
    {
        $f = DispoJobFortschritt::berechnen([self::s('2026-10-20', '10:00', 'A', 0, 3), self::s('2026-10-20', '12:00', 'B', 4, 4)]);

        $this->assertSame(4, $f['besetzt']);
        $this->assertSame(100, $f['prozent']);
    }
}
