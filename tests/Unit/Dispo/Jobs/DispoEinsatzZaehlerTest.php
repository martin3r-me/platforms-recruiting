<?php

namespace Platform\Recruiting\Tests\Unit\Dispo\Jobs;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Services\Zas\Dispo\Jobs\DispoEinsatzZaehler;

final class DispoEinsatzZaehlerTest extends TestCase
{
    private static function zeile(int $emp, string $datum, array $extra = []): array
    {
        return $extra + ['employee_id' => $emp, 'datum' => $datum, 'status_id' => 1,
            'missing_since' => null, 'deletion_marked_at' => null, 'declined_at' => null];
    }

    public function testZaehltNurEinsaetzeImMonatUeberDiePersonengruppe(): void
    {
        $rows = [
            self::zeile(1, '2026-10-03'),
            self::zeile(2, '2026-10-10'),            // MA-Datensatz derselben Person
            self::zeile(1, '2026-09-30'),            // Vormonat
            self::zeile(1, '2026-10-11', ['status_id' => 3]),
            self::zeile(1, '2026-10-12', ['missing_since' => '2026-10-01 00:00:00']),
            self::zeile(1, '2026-10-13', ['deletion_marked_at' => '2026-10-01 00:00:00']),
            self::zeile(1, '2026-10-14', ['declined_at' => '2026-10-01 00:00:00']),
            self::zeile(3, '2026-10-05'),
        ];
        $this->assertSame([1 => 2, 3 => 1], DispoEinsatzZaehler::proMonat($rows, [1 => 1, 2 => 1, 3 => 3], '2026-10'));
    }

    public function testZaehlfensterGehtDreissigTageZurueckBisZumLetztenTag(): void
    {
        $this->assertSame(['von' => '2026-09-30', 'bis' => '2026-11-08'], DispoEinsatzZaehler::zaehlfenster('2026-10-30', ['2026-11-02', '2026-11-08 00:00:00', '2026-11-05']));
        // Tage in der Vergangenheit ziehen das Ende nicht unter heute.
        $this->assertSame(['von' => '2026-09-30', 'bis' => '2026-10-30'], DispoEinsatzZaehler::zaehlfenster('2026-10-30 12:00:00', ['2026-10-01']));
        $this->assertSame(['von' => '2026-09-30', 'bis' => '2026-10-30'], DispoEinsatzZaehler::zaehlfenster('2026-10-30', []));
    }

    public function testImZeitraumZaehltEinschliesslichDerGrenzenUeberDiePersonengruppe(): void
    {
        $rows = [
            self::zeile(1, '2026-09-30'),            // Grenze von
            self::zeile(2, '2026-10-10'),            // MA-Datensatz derselben Person
            self::zeile(1, '2026-11-08'),            // Grenze bis
            self::zeile(1, '2026-09-29'),            // davor
            self::zeile(1, '2026-11-09'),            // danach
            self::zeile(1, '2026-10-11', ['status_id' => 3]),
            self::zeile(1, '2026-10-14', ['declined_at' => '2026-10-01 00:00:00']),
            self::zeile(3, '2026-10-05 00:00:00'),
        ];
        $this->assertSame([1 => 3, 3 => 1], DispoEinsatzZaehler::imZeitraum($rows, [1 => 1, 2 => 1, 3 => 3], '2026-09-30', '2026-11-08'));
    }

    public function testBaender(): void
    {
        $this->assertSame('0', DispoEinsatzZaehler::band(0));
        $this->assertSame('1-4', DispoEinsatzZaehler::band(1));
        $this->assertSame('1-4', DispoEinsatzZaehler::band(4));
        $this->assertSame('5-9', DispoEinsatzZaehler::band(5));
        $this->assertSame('5-9', DispoEinsatzZaehler::band(9));
        $this->assertSame('10-19', DispoEinsatzZaehler::band(10));
        $this->assertSame('10-19', DispoEinsatzZaehler::band(19));
        $this->assertSame('20+', DispoEinsatzZaehler::band(20));
        $this->assertSame('20+', DispoEinsatzZaehler::band(57));
    }

    public function testTrifftVereinigungUndAlle(): void
    {
        $this->assertTrue(DispoEinsatzZaehler::trifft(0, ['0', '1-4']));
        $this->assertTrue(DispoEinsatzZaehler::trifft(3, ['0', '1-4']));
        $this->assertFalse(DispoEinsatzZaehler::trifft(5, ['0', '1-4']));
        $this->assertTrue(DispoEinsatzZaehler::trifft(57, ['alle']));
        $this->assertFalse(DispoEinsatzZaehler::trifft(0, []), 'leere Auswahl heisst niemand');
        $this->assertFalse(DispoEinsatzZaehler::trifft(0, ['quatsch']));
    }
}
