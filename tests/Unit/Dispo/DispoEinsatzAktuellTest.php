<?php

namespace Platform\Recruiting\Tests\Unit\Dispo;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Services\Zas\Dispo\DispoEinsatzAktuell;

/**
 * Kunde 28.10.: Anhaenge verschwanden um 00:00 Uhr, obwohl die Schicht bis nach
 * Mitternacht lief. Ein Einsatz bleibt „aktuell“, bis Tag UND Schichtende plus
 * Nachlauf vorbei sind.
 */
final class DispoEinsatzAktuellTest extends TestCase
{
    private static function um(string $zeit): \DateTimeImmutable
    {
        return new \DateTimeImmutable($zeit, new \DateTimeZone('Europe/Berlin'));
    }

    public function test_heute_und_zukunft_sind_immer_aktuell(): void
    {
        $now = self::um('2026-10-28 23:59:00');
        $this->assertTrue(DispoEinsatzAktuell::istAktuell('2026-10-28', '08:00', '12:00', $now), 'heute, Schicht lange vorbei: Tag zaehlt');
        $this->assertTrue(DispoEinsatzAktuell::istAktuell('2026-10-29', null, null, $now));
        $this->assertTrue(DispoEinsatzAktuell::istAktuell('2027-01-01', '08:00', '12:00', $now));
    }

    public function test_schicht_ueber_mitternacht_bleibt_nach_null_uhr_aktuell(): void
    {
        // Der Fall vom 27./28.10.: 18:00–02:00, Check-out nach Mitternacht.
        $this->assertTrue(DispoEinsatzAktuell::istAktuell('2026-10-27', '18:00', '02:00', self::um('2026-10-28 00:05:00')));
        $this->assertTrue(DispoEinsatzAktuell::istAktuell('2026-10-27', '18:00', '02:00', self::um('2026-10-28 01:30:00')));
        $this->assertTrue(DispoEinsatzAktuell::istAktuell('2026-10-27', '18:00', '02:00', self::um('2026-10-28 07:59:00')), 'bis Ende + 6 Std Nachlauf');
        $this->assertFalse(DispoEinsatzAktuell::istAktuell('2026-10-27', '18:00', '02:00', self::um('2026-10-28 08:00:00')), 'Ende 02:00 + 6 Std = 08:00 → vorbei');
        $this->assertFalse(DispoEinsatzAktuell::istAktuell('2026-10-27', '18:00', '02:00', self::um('2026-10-28 12:00:00')));
    }

    public function test_endzeit_gleich_beginn_gilt_als_folgetag(): void
    {
        $this->assertTrue(DispoEinsatzAktuell::istAktuell('2026-10-27', '20:00', '20:00', self::um('2026-10-28 19:00:00')));
    }

    public function test_tagschicht_vom_vortag_ist_nach_mitternacht_vergangen(): void
    {
        // 10:00–16:00 + 6 Std = 22:00 am selben Tag → um 00:30 am Folgetag vorbei.
        $this->assertFalse(DispoEinsatzAktuell::istAktuell('2026-10-27', '10:00', '16:00', self::um('2026-10-28 00:30:00')));
    }

    public function test_spaetschicht_mit_ende_vor_mitternacht_bekommt_nachlauf(): void
    {
        // 16:00–23:00 + 6 Std = 05:00 am Folgetag.
        $this->assertTrue(DispoEinsatzAktuell::istAktuell('2026-10-27', '16:00', '23:00', self::um('2026-10-28 04:59:00')));
        $this->assertFalse(DispoEinsatzAktuell::istAktuell('2026-10-27', '16:00', '23:00', self::um('2026-10-28 05:00:00')));
    }

    public function test_ohne_endzeit_zaehlt_nur_der_tag(): void
    {
        $this->assertFalse(DispoEinsatzAktuell::istAktuell('2026-10-27', '18:00', null, self::um('2026-10-28 00:05:00')));
        $this->assertFalse(DispoEinsatzAktuell::istAktuell('2026-10-27', null, null, self::um('2026-10-28 00:05:00')));
    }

    public function test_sekunden_in_der_zeit_stoeren_nicht(): void
    {
        $this->assertTrue(DispoEinsatzAktuell::istAktuell('2026-10-27', '18:00:00', '02:00:00', self::um('2026-10-28 01:00:00')));
    }

    public function test_kaputtes_datum_ist_nie_aktuell(): void
    {
        $this->assertFalse(DispoEinsatzAktuell::istAktuell('', '18:00', '02:00', self::um('2026-10-28 01:00:00')));
        $this->assertFalse(DispoEinsatzAktuell::istAktuell('27.10.2026', '18:00', '02:00', self::um('2026-10-28 01:00:00')));
    }

    public function test_abfrage_holt_ab_dem_vortag(): void
    {
        $this->assertSame('2026-10-27', DispoEinsatzAktuell::abDatum(self::um('2026-10-28 00:05:00')));
        $this->assertSame('2026-10-31', DispoEinsatzAktuell::abDatum(self::um('2026-11-01 12:00:00')));
    }
}
