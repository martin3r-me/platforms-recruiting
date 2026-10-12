<?php

namespace Platform\Recruiting\Tests\Unit\Dispo\Jobs;

use Carbon\Carbon;
use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Services\Zas\Dispo\Jobs\DispoJobSchichten;

final class DispoJobSchichtenTest extends TestCase
{
    private static function zeile(string $datum, ?string $von, string $taet, array $extra = []): array
    {
        return $extra + ['datum' => $datum, 'von' => $von, 'taetigkeit' => $taet, 'pnr_raw' => 'RG100',
            'missing_since' => null, 'deletion_marked_at' => null];
    }

    public function testGruppiertJeTagNachBeginnUndTaetigkeit(): void
    {
        $rows = [
            self::zeile('2026-10-24', '17:00', 'Zapfer'),
            self::zeile('2026-10-24', '17:00', 'Zapfer'),
            self::zeile('2026-10-24', '12:00', 'Service'),
            self::zeile('2026-10-24', '17:00', 'Service'),
            self::zeile('2026-10-25', '17:00', 'Zapfer'),
        ];
        $out = DispoJobSchichten::optionen($rows, ['2026-10-24', '2026-10-25']);

        $this->assertSame(['2026-10-24', '2026-10-25'], array_keys($out));
        $this->assertSame([
            ['von' => '12:00', 'taetigkeit' => 'Service', 'zeilen' => 1, 'platzhalter' => 0],
            ['von' => '17:00', 'taetigkeit' => 'Service', 'zeilen' => 1, 'platzhalter' => 0],
            ['von' => '17:00', 'taetigkeit' => 'Zapfer', 'zeilen' => 2, 'platzhalter' => 0],
        ], $out['2026-10-24']);
        $this->assertSame([['von' => '17:00', 'taetigkeit' => 'Zapfer', 'zeilen' => 1, 'platzhalter' => 0]], $out['2026-10-25']);
    }

    public function testZaehltPlatzhalterZeilen(): void
    {
        $rows = [
            self::zeile('2026-10-24', '17:00', 'Zapfer', ['pnr_raw' => 'RG0']),
            self::zeile('2026-10-24', '17:00', 'Zapfer', ['pnr_raw' => 'rg14']),
            self::zeile('2026-10-24', '17:00', 'Zapfer', ['pnr_raw' => ' 14 ']),
            self::zeile('2026-10-24', '17:00', 'Zapfer', ['pnr_raw' => 'RG140']),
        ];
        $out = DispoJobSchichten::optionen($rows, ['2026-10-24']);

        $this->assertSame([['von' => '17:00', 'taetigkeit' => 'Zapfer', 'zeilen' => 4, 'platzhalter' => 3]], $out['2026-10-24']);
    }

    public function testVerschwundeneUndGemeldeteZeilenUndFremdeTageFallenRaus(): void
    {
        $rows = [
            self::zeile('2026-10-24', '17:00', 'Zapfer', ['missing_since' => '2026-10-01 00:00:00']),
            self::zeile('2026-10-24', '18:00', 'Zapfer', ['deletion_marked_at' => '2026-10-01 00:00:00']),
            self::zeile('2026-10-26', '17:00', 'Zapfer'),
            self::zeile('2026-10-24', '19:00', 'Zapfer'),
        ];
        $out = DispoJobSchichten::optionen($rows, ['2026-10-24']);

        $this->assertSame(['2026-10-24'], array_keys($out));
        $this->assertSame([['von' => '19:00', 'taetigkeit' => 'Zapfer', 'zeilen' => 1, 'platzhalter' => 0]], $out['2026-10-24']);
    }

    public function testModelleMitCarbonDatumUndLeeremBeginn(): void
    {
        $row = (object) ['datum' => Carbon::parse('2026-10-24'), 'von' => null, 'taetigkeit' => ' Zapfer ', 'pnr_raw' => null,
            'missing_since' => null, 'deletion_marked_at' => null];
        $out = DispoJobSchichten::optionen([$row], ['2026-10-24']);

        $this->assertSame([['von' => '', 'taetigkeit' => 'Zapfer', 'zeilen' => 1, 'platzhalter' => 0]], $out['2026-10-24']);
    }

    public function testSchluesselIstStabilUndUnterscheidetFelder(): void
    {
        $a = DispoJobSchichten::schluessel(5, '2026-10-24', '17:00', 'Zapfer');
        $this->assertSame($a, DispoJobSchichten::schluessel(5, '2026-10-24', '17:00', 'Zapfer'));
        $this->assertNotSame($a, DispoJobSchichten::schluessel(5, '2026-10-24', '17:00', 'Service'));
        $this->assertNotSame($a, DispoJobSchichten::schluessel(6, '2026-10-24', '17:00', 'Zapfer'));
        $this->assertMatchesRegularExpression('/^s[0-9a-f]+$/', $a, 'keine Punkte/Leerzeichen — wire:model-tauglich');
    }
}
