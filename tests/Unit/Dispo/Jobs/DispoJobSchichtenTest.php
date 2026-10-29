<?php

namespace Platform\Recruiting\Tests\Unit\Dispo\Jobs;

use Carbon\Carbon;
use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Services\Zas\Dispo\Jobs\DispoJobSchichten;

final class DispoJobSchichtenTest extends TestCase
{
    private static function zeile(string $datum, ?string $von, string $taet, array $extra = [], ?string $bis = null): array
    {
        return $extra + ['datum' => $datum, 'von' => $von, 'bis' => $bis, 'taetigkeit' => $taet, 'pnr_raw' => 'RG100',
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
            ['von' => '12:00', 'bis' => '', 'taetigkeit' => 'Service', 'zeilen' => 1, 'platzhalter' => 0, 'offen' => null, 'dispoposten' => null],
            ['von' => '17:00', 'bis' => '', 'taetigkeit' => 'Service', 'zeilen' => 1, 'platzhalter' => 0, 'offen' => null, 'dispoposten' => null],
            ['von' => '17:00', 'bis' => '', 'taetigkeit' => 'Zapfer', 'zeilen' => 2, 'platzhalter' => 0, 'offen' => null, 'dispoposten' => null],
        ], $out['2026-10-24']);
        $this->assertSame([['von' => '17:00', 'bis' => '', 'taetigkeit' => 'Zapfer', 'zeilen' => 1, 'platzhalter' => 0, 'offen' => null, 'dispoposten' => null]], $out['2026-10-25']);
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

        $this->assertSame([['von' => '17:00', 'bis' => '', 'taetigkeit' => 'Zapfer', 'zeilen' => 4, 'platzhalter' => 3, 'offen' => null, 'dispoposten' => null]], $out['2026-10-24']);
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
        $this->assertSame([['von' => '19:00', 'bis' => '', 'taetigkeit' => 'Zapfer', 'zeilen' => 1, 'platzhalter' => 0, 'offen' => null, 'dispoposten' => null]], $out['2026-10-24']);
    }

    public function testModelleMitCarbonDatumUndLeeremBeginn(): void
    {
        $row = (object) ['datum' => Carbon::parse('2026-10-24'), 'von' => null, 'taetigkeit' => ' Zapfer ', 'pnr_raw' => null,
            'missing_since' => null, 'deletion_marked_at' => null];
        $out = DispoJobSchichten::optionen([$row], ['2026-10-24']);

        $this->assertSame([['von' => '', 'bis' => '', 'taetigkeit' => 'Zapfer', 'zeilen' => 1, 'platzhalter' => 0, 'offen' => null, 'dispoposten' => null]], $out['2026-10-24']);
    }

    public function testEndzeitIstDieHaeufigsteBeiGleichstandDieFrueheste(): void
    {
        $rows = [
            self::zeile('2026-10-24', '17:00', 'Zapfer', [], '23:00'),
            self::zeile('2026-10-24', '17:00', 'Zapfer', [], '23:00'),
            self::zeile('2026-10-24', '17:00', 'Zapfer', [], '22:00'),
            self::zeile('2026-10-24', '17:00', 'Zapfer', [], null),
            self::zeile('2026-10-24', '12:00', 'Service', [], '20:00'),
            self::zeile('2026-10-24', '12:00', 'Service', [], '18:00'),
            self::zeile('2026-10-24', '19:00', 'Logistik', [], ''),
        ];
        $out = DispoJobSchichten::optionen($rows, ['2026-10-24']);

        $this->assertSame('18:00', $out['2026-10-24'][0]['bis'], 'Gleichstand: die fruehere');
        $this->assertSame('23:00', $out['2026-10-24'][1]['bis'], 'die haeufigste; leere zaehlen nicht');
        $this->assertSame('', $out['2026-10-24'][2]['bis'], 'nirgends eine Endzeit');
        $this->assertSame(['von', 'bis', 'taetigkeit', 'zeilen', 'platzhalter', 'offen', 'dispoposten'], array_keys($out['2026-10-24'][0]));
    }

    public function testSchluesselIstStabilUndUnterscheidetFelder(): void
    {
        $a = DispoJobSchichten::schluessel(5, '2026-10-24', '17:00', 'Zapfer');
        $this->assertSame($a, DispoJobSchichten::schluessel(5, '2026-10-24', '17:00', 'Zapfer'));
        $this->assertNotSame($a, DispoJobSchichten::schluessel(5, '2026-10-24', '17:00', 'Service'));
        $this->assertNotSame($a, DispoJobSchichten::schluessel(6, '2026-10-24', '17:00', 'Zapfer'));
        $this->assertMatchesRegularExpression('/^s[0-9a-f]+$/', $a, 'keine Punkte/Leerzeichen — wire:model-tauglich');
    }

    // ----- Bedarfsliste (Spec 2026-10-29 §5) -----

    private static function posten(string $nr, string $datum, string $von, string $bis, string $taet, int $anzahl, array $extra = []): array
    {
        return $extra + ['dispoposten_id' => $nr, 'datum' => $datum, 'von' => $von, 'bis' => $bis, 'zeiten' => [['von' => $von, 'bis' => $bis]],
            'taetigkeit' => $taet, 'anzahl' => $anzahl, 'status_id' => 1, 'missing_since' => null];
    }

    private static function zeileAus(array $r, array $o = []): array
    {
        return $o + $r;
    }

    public function testPostenOhnePersonErscheintMitOffen(): void
    {
        $out = DispoJobSchichten::optionen([], ['2026-11-03'], [
            self::posten('126159', '2026-11-03', '14:00', '23:30', 'Servicekräfte', 6),
        ]);

        $this->assertSame([[
            'von' => '14:00', 'bis' => '23:30', 'taetigkeit' => 'Servicekräfte', 'zeilen' => 0, 'platzhalter' => 0, 'offen' => 6, 'dispoposten' => '126159',
        ]], $out['2026-11-03']);
    }

    public function testPersonMitPassendemBeginnZaehltZumPosten(): void
    {
        $out = DispoJobSchichten::optionen([
            self::zeile('2026-11-03', '07:30', 'Servicekräfte', [], '14:00'),
            self::zeile('2026-11-03', '07:30', 'servicekräfte ', [], '14:00'), // Tippfehler-Varianten zaehlen mit
        ], ['2026-11-03'], [
            self::posten('126158', '2026-11-03', '07:30', '14:00', 'Servicekräfte', 6),
        ]);

        $this->assertCount(1, $out['2026-11-03'], 'keine eigene Personenzeile neben dem Posten');
        $this->assertSame(2, $out['2026-11-03'][0]['zeilen']);
        $this->assertSame(4, $out['2026-11-03'][0]['offen']);
        $this->assertSame('126158', $out['2026-11-03'][0]['dispoposten']);
    }

    public function testAbweichenderBeginnZaehltBeiEindeutigemPostenSonstEigeneZeile(): void
    {
        // Ein Posten „Theke“ → Person mit 08:00 gehoert dazu.
        $out = DispoJobSchichten::optionen([
            self::zeile('2026-11-04', '08:00', 'Theke'),
        ], ['2026-11-04'], [
            self::posten('1', '2026-11-04', '07:30', '18:00', 'Theke', 2),
        ]);
        $this->assertCount(1, $out['2026-11-04']);
        $this->assertSame(1, $out['2026-11-04'][0]['offen']);
        $this->assertSame('07:30', $out['2026-11-04'][0]['von'], 'Zeit des Postens, nicht der Person');

        // Zwei Posten „Theke“ → Person mit 08:00 passt zu keinem: eigene Zeile, offen bleibt.
        $out = DispoJobSchichten::optionen([
            self::zeile('2026-11-04', '08:00', 'Theke'),
        ], ['2026-11-04'], [
            self::posten('1', '2026-11-04', '07:30', '18:00', 'Theke', 2),
            self::posten('2', '2026-11-04', '18:00', '00:00', 'Theke', 4),
        ]);
        $this->assertSame(['07:30', '08:00', '18:00'], array_column($out['2026-11-04'], 'von'));
        $this->assertSame([2, null, 4], array_column($out['2026-11-04'], 'offen'));
        $this->assertSame(1, $out['2026-11-04'][1]['zeilen']);
    }

    public function testPlatzhalterZaehlenNichtAlsEchtePerson(): void
    {
        $out = DispoJobSchichten::optionen([
            self::zeile('2026-11-03', '14:00', 'Servicekräfte', ['pnr_raw' => 'RG14']),
            self::zeile('2026-11-03', '14:00', 'Servicekräfte', ['pnr_raw' => 'RG0']),
            self::zeile('2026-11-03', '14:00', 'Servicekräfte', ['pnr_raw' => 'RG17182']),
        ], ['2026-11-03'], [
            self::posten('126159', '2026-11-03', '14:00', '23:30', 'Servicekräfte', 6),
        ]);

        $r = $out['2026-11-03'][0];
        $this->assertSame(3, $r['zeilen']);
        $this->assertSame(2, $r['platzhalter']);
        $this->assertSame(5, $r['offen'], '6 Plaetze, eine echte Person');
    }

    public function testZweiZeitvariantenSindEinePostenzeile(): void
    {
        $posten = self::posten('120988', '2026-09-14', '08:30', '16:45', 'Cateringhilfe', 2,
            ['zeiten' => [['von' => '08:30', 'bis' => '16:45'], ['von' => '08:45', 'bis' => '16:45']]]);
        $out = DispoJobSchichten::optionen([
            self::zeile('2026-09-14', '08:45', 'Cateringhilfe', [], '16:45'),
            self::zeile('2026-09-14', '08:30', 'Cateringhilfe', [], '16:45'),
        ], ['2026-09-14'], [$posten]);

        $this->assertCount(1, $out['2026-09-14']);
        $this->assertSame(2, $out['2026-09-14'][0]['zeilen']);
        $this->assertSame(0, $out['2026-09-14'][0]['offen']);
        $this->assertSame('08:30', $out['2026-09-14'][0]['von']);
    }

    public function testZeitenAlsJsonTextUndModell(): void
    {
        $posten = (object) self::posten('5', '2026-09-14', '08:30', '16:45', 'Cateringhilfe', 2,
            ['zeiten' => json_encode([['von' => '08:30', 'bis' => '16:45'], ['von' => '08:45', 'bis' => '16:45']]), 'datum' => Carbon::parse('2026-09-14')]);
        $out = DispoJobSchichten::optionen([self::zeile('2026-09-14', '08:45', 'Cateringhilfe')], ['2026-09-14'], [$posten]);

        $this->assertCount(1, $out['2026-09-14']);
        $this->assertSame(1, $out['2026-09-14'][0]['offen']);
    }

    public function testOffenFaelltNieUnterNull(): void
    {
        $out = DispoJobSchichten::optionen([
            self::zeile('2026-11-03', '14:00', 'Service'),
            self::zeile('2026-11-03', '14:00', 'Service'),
            self::zeile('2026-11-03', '14:00', 'Service'),
        ], ['2026-11-03'], [self::posten('1', '2026-11-03', '14:00', '23:30', 'Service', 2)]);

        $this->assertSame(0, $out['2026-11-03'][0]['offen']);
    }

    public function testVerschwundeneStornierteUndFremdeTagePostenFallenRaus(): void
    {
        $out = DispoJobSchichten::optionen([], ['2026-11-03'], [
            self::posten('1', '2026-11-03', '14:00', '23:30', 'A', 2, ['missing_since' => '2026-10-01 00:00:00']),
            self::posten('2', '2026-11-03', '14:00', '23:30', 'B', 2, ['status_id' => 3]),
            self::posten('3', '2026-11-05', '14:00', '23:30', 'C', 2),
            self::posten('', '2026-11-03', '14:00', '23:30', 'D', 2),
            self::posten('4', '2026-11-03', '14:00', '23:30', 'E', 2),
        ]);

        $this->assertSame(['2026-11-03'], array_keys($out));
        $this->assertSame(['E'], array_column($out['2026-11-03'], 'taetigkeit'));
    }

    public function testSortierungMischtPostenUndPersonenzeilen(): void
    {
        $out = DispoJobSchichten::optionen([
            self::zeile('2026-11-03', '07:30', 'Logistik'),
        ], ['2026-11-03'], [
            self::posten('2', '2026-11-03', '14:00', '23:30', 'Servicekräfte', 6),
            self::posten('1', '2026-11-03', '07:30', '14:00', 'Servicekräfte', 1),
        ]);

        $this->assertSame([['07:30', 'Logistik'], ['07:30', 'Servicekräfte'], ['14:00', 'Servicekräfte']],
            array_map(fn ($r) => [$r['von'], $r['taetigkeit']], $out['2026-11-03']));
    }

    // ----- Review 29.10. (I-1, M-2) -----

    public function testZweiPostenGleicherBeginnUndTaetigkeitBleibenGetrenntUndPersonenGehenZumPostenMitMehrOffen(): void
    {
        // Posten 1: 14:00–23:30 Anzahl 1, Posten 2: 14:00–18:00 Anzahl 3 — drei Personen um 14:00.
        $out = DispoJobSchichten::optionen([
            self::zeile('2026-11-03', '14:00', 'Service'),
            self::zeile('2026-11-03', '14:00', 'Service'),
            self::zeile('2026-11-03', '14:00', 'Service'),
        ], ['2026-11-03'], [
            self::posten('1', '2026-11-03', '14:00', '23:30', 'Service', 1),
            self::posten('2', '2026-11-03', '14:00', '18:00', 'Service', 3),
        ]);

        $this->assertCount(2, $out['2026-11-03'], 'zwei Posten = zwei Zeilen, kein Zusammenfallen');
        $this->assertSame([['1', 1, 0], ['2', 2, 1]], array_map(fn ($r) => [$r['dispoposten'], $r['zeilen'], $r['offen']], $out['2026-11-03']),
            'erste Person zum Posten mit mehr offen (2), dann 1, dann wieder 2');

        $a = DispoJobSchichten::schluessel(5, '2026-11-03', '14:00', 'Service', '1');
        $b = DispoJobSchichten::schluessel(5, '2026-11-03', '14:00', 'Service', '2');
        $this->assertNotSame($a, $b, 'eigenes Eingabefeld je Posten');
        $this->assertSame(DispoJobSchichten::schluessel(5, '2026-11-03', '14:00', 'Service'), DispoJobSchichten::schluessel(5, '2026-11-03', '14:00', 'Service', null), 'ohne Posten wie bisher');
        $this->assertSame(DispoJobSchichten::schluessel(5, '2026-11-03', '14:00', 'Service'), DispoJobSchichten::schluessel(5, '2026-11-03', '14:00', 'Service', ''), 'leer = kein Posten');
    }

    public function testPersonPasstZumZweitenPosten(): void
    {
        $out = DispoJobSchichten::optionen([
            self::zeile('2026-11-03', '14:00', 'Servicekräfte'),
        ], ['2026-11-03'], [
            self::posten('126158', '2026-11-03', '07:30', '14:00', 'Servicekräfte', 1),
            self::posten('126159', '2026-11-03', '14:00', '23:30', 'Servicekräfte', 6),
            self::posten('126205', '2026-11-03', '18:00', '22:00', 'Servicekräfte', 6),
        ]);

        $this->assertSame([1, 5, 6], array_column($out['2026-11-03'], 'offen'));
        $this->assertSame([0, 1, 0], array_column($out['2026-11-03'], 'zeilen'));
    }

    public function testPersonOhneBeginn(): void
    {
        // Ein Posten der Taetigkeit → Fallback greift.
        $out = DispoJobSchichten::optionen([self::zeile('2026-11-03', null, 'Theke')], ['2026-11-03'],
            [self::posten('1', '2026-11-03', '07:30', '18:00', 'Theke', 2)]);
        $this->assertCount(1, $out['2026-11-03']);
        $this->assertSame(1, $out['2026-11-03'][0]['offen']);

        // Zwei Posten der Taetigkeit → eigene Zeile „–“, Posten bleiben voll.
        $out = DispoJobSchichten::optionen([self::zeile('2026-11-03', null, 'Theke')], ['2026-11-03'], [
            self::posten('1', '2026-11-03', '07:30', '18:00', 'Theke', 2),
            self::posten('2', '2026-11-03', '18:00', '00:00', 'Theke', 4),
        ]);
        $this->assertSame(['', '07:30', '18:00'], array_column($out['2026-11-03'], 'von'));
        $this->assertSame([null, 2, 4], array_column($out['2026-11-03'], 'offen'));

        // Posten ohne Beginn nimmt Personen ohne Beginn ueber den Beginn-Treffer.
        $out = DispoJobSchichten::optionen([self::zeile('2026-11-03', null, 'Theke'), self::zeile('2026-11-03', '09:00', 'Theke')], ['2026-11-03'], [
            self::posten('1', '2026-11-03', '', '', 'Theke', 2, ['zeiten' => []]),
            self::posten('2', '2026-11-03', '18:00', '00:00', 'Theke', 4),
        ]);
        $this->assertSame([['', 1, 1], ['09:00', 1, null], ['18:00', 0, 4]], array_map(fn ($r) => [$r['von'], $r['zeilen'], $r['offen']], $out['2026-11-03']));
    }
}
