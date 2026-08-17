<?php

namespace Platform\Recruiting\Tests\Unit\Statistics;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Support\YmdDate;

/**
 * Datumsrechnung fuer pure Klassen — ohne Carbon, weil der Test-Bootstrap
 * kein Laravel laedt.
 */
final class YmdDateTest extends TestCase
{
    public function test_gueltige_daten_werden_erkannt(): void
    {
        $this->assertTrue(YmdDate::isValid('2026-08-17'));
        $this->assertTrue(YmdDate::isValid('2026-02-28'));
    }

    public function test_rollover_daten_gelten_als_ungueltig(): void
    {
        // createFromFormat rollt '2026-02-30' still auf den 2. Maerz — ohne
        // Round-Trip-Pruefung waere das ein plausibel aussehender Fehlwert.
        $this->assertFalse(YmdDate::isValid('2026-02-30'));
        $this->assertFalse(YmdDate::isValid('2026-13-01'));
        $this->assertFalse(YmdDate::isValid('2026-8-4'), 'unpadded ist nicht Y-m-d');
        $this->assertFalse(YmdDate::isValid(''));
        $this->assertFalse(YmdDate::isValid('2026-08-17 10:00:00'));
    }

    public function test_tage_zwischen_zwei_daten(): void
    {
        $this->assertSame(0, YmdDate::daysBetween('2026-08-17', '2026-08-17'));
        $this->assertSame(1, YmdDate::daysBetween('2026-08-17', '2026-08-18'));
        $this->assertSame(31, YmdDate::daysBetween('2026-07-17', '2026-08-17'));
        $this->assertSame(-3, YmdDate::daysBetween('2026-08-20', '2026-08-17'), 'negativ ist erlaubt');
    }

    public function test_sommerzeit_wechsel_zaehlt_ganze_tage(): void
    {
        // 01.03. -> 31.03. ist 30 Tage, auch wenn dazwischen die Uhr springt.
        // Ohne UTC-Fixierung liefert die Differenz 29 oder 30,96 Tage.
        $this->assertSame(30, YmdDate::daysBetween('2026-03-01', '2026-03-31'));
    }

    public function test_unlesbares_datum_liefert_null(): void
    {
        $this->assertNull(YmdDate::daysBetween('2026-02-30', '2026-08-17'));
        $this->assertNull(YmdDate::daysBetween('2026-08-17', 'kaputt'));
    }
}
