<?php

namespace Platform\Recruiting\Tests\Unit;

use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Container\Container;
use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Support\MetaAbrechnung;

/**
 * Sollwerte = echte Meta-Auswertung Oktober 2026 (pricing_analytics, abgefragt
 * 18.11.2026), Dispo-Summe gegen die Abbuchungen auf 2 Cent bestaetigt.
 */
final class MetaAbrechnungTest extends TestCase
{
    protected function setUp(): void
    {
        // Echte Modul-Konfig (Filialen-Kuerzel + meta_abrechnung), keine Kopie
        // (die Konfig-Datei liest selbst config() — erst leer binden, dann laden)
        $config = new ConfigRepository();
        Container::getInstance()->instance('config', $config);
        $config->set('recruiting', require __DIR__ . '/../../config/recruiting.php');
    }

    protected function tearDown(): void
    {
        Container::getInstance()->forgetInstance('config');
    }

    public function test_konfig_hr_nummer_und_aufschlag(): void
    {
        $this->assertSame(['4921178177722' => 'HR'], config('recruiting.meta_abrechnung.nummern_ohne_filiale'));
        $this->assertSame(['2789746154722145', '2378798469210132'], config('recruiting.meta_abrechnung.waba_ids'));
    }

    private function punkt(string $nr, string $typ, int $volumen, float $kosten): array
    {
        return ['start' => 1790805600, 'end' => 1793487600, 'phone_number' => $nr,
            'pricing_category' => $typ === 'FREE_CUSTOMER_SERVICE' ? 'SERVICE' : 'UTILITY',
            'pricing_type' => $typ, 'volume' => $volumen, 'cost' => $kosten];
    }

    private function oktober(): array
    {
        return [
            // HR, inkl. der 357 berechneten Service-Nachrichten (REGULAR SERVICE)
            ['start' => 1, 'end' => 2, 'phone_number' => '4921178177722', 'pricing_category' => 'SERVICE', 'pricing_type' => 'REGULAR', 'volume' => 357, 'cost' => 16.226],
            $this->punkt('4921178177722', 'FREE_CUSTOMER_SERVICE', 1001, 0),
            $this->punkt('4921178177722', 'REGULAR', 1957, 88.1517),
            $this->punkt('4921178177725', 'FREE_CUSTOMER_SERVICE', 82, 0),
            $this->punkt('4921178177725', 'REGULAR', 1974, 88.8663),
            $this->punkt('4921178177723', 'FREE_CUSTOMER_SERVICE', 166, 0),
            $this->punkt('4921178177723', 'REGULAR', 1824, 82.9968),
            $this->punkt('4921178177729', 'FREE_CUSTOMER_SERVICE', 11, 0),
            $this->punkt('4921178177729', 'REGULAR', 284, 12.9504),
        ];
    }

    private function zuordnung(): array
    {
        return [
            '4921178177725' => ['kostenstelle' => 100, 'bezeichnung' => 'DUS & ES', 'rufnummer' => '+49 211 78177725'],
            '4921178177723' => ['kostenstelle' => 200, 'bezeichnung' => 'MGL', 'rufnummer' => '+49 211 78177723'],
            '4921178177729' => ['kostenstelle' => 400, 'bezeichnung' => 'CGN', 'rufnummer' => '+49 211 78177729'],
            '4921178177722' => ['kostenstelle' => null, 'bezeichnung' => 'HR', 'rufnummer' => '+49 211 78177722'],
        ];
    }

    public function test_oktober_2026_je_rufnummer_mit_30_prozent(): void
    {
        $a = MetaAbrechnung::aufstellen($this->oktober(), $this->zuordnung(), 30.0);

        $kurz = array_map(fn ($z) => [$z['kostenstelle'], $z['bezeichnung'], $z['meta'], $z['aufschlag'], $z['summe']], $a['zeilen']);
        $this->assertSame([
            [100, 'DUS & ES', 88.87, 26.66, 115.53],
            [200, 'MGL', 83.0, 24.9, 107.9],
            [400, 'CGN', 12.95, 3.89, 16.84],
            [null, 'HR', 104.38, 31.31, 135.69],
        ], $kurz);

        $this->assertSame(289.2, $a['gesamt']['meta']); // Summe der gerundeten Zeilen (exakt 289,1912)
        $this->assertSame(86.76, $a['gesamt']['aufschlag']);
        $this->assertSame(375.96, $a['gesamt']['summe']);
        // Ungerundete Meta-Summe fuer den Abgleich mit den Abbuchungen
        $this->assertEqualsWithDelta(289.1912, $a['gesamt']['meta_exakt'], 0.00001);
    }

    public function test_jede_zeile_geht_optisch_auf(): void
    {
        foreach (MetaAbrechnung::aufstellen($this->oktober(), $this->zuordnung(), 30.0)['zeilen'] as $z) {
            $this->assertSame(round($z['meta'] + $z['aufschlag'], 2), $z['summe'], $z['bezeichnung']);
        }
    }

    public function test_nachrichten_berechnet_und_frei_getrennt(): void
    {
        $hr = MetaAbrechnung::aufstellen($this->oktober(), $this->zuordnung(), 30.0)['zeilen'][3];

        $this->assertSame(1957 + 357, $hr['berechnet']);
        $this->assertSame(1001, $hr['frei']);
    }

    public function test_unbekannte_nummer_geht_nicht_verloren(): void
    {
        $punkte = [$this->punkt('4921178177799', 'REGULAR', 10, 0.455)];

        $z = MetaAbrechnung::aufstellen($punkte, $this->zuordnung(), 30.0)['zeilen'];

        $this->assertCount(1, $z);
        $this->assertNull($z[0]['kostenstelle']);
        $this->assertSame('nicht zugeordnet', $z[0]['bezeichnung']);
        $this->assertSame('+4921178177799', $z[0]['rufnummer']);
        $this->assertSame(0.59, $z[0]['summe']);
    }

    public function test_nummer_ohne_verbrauch_erscheint_nicht(): void
    {
        $z = MetaAbrechnung::aufstellen([], $this->zuordnung(), 30.0);

        $this->assertSame([], $z['zeilen']);
        $this->assertSame(0.0, $z['gesamt']['summe']);
    }

    public function test_datenpunkte_aus_antwort_und_fehler(): void
    {
        $json = ['id' => 'x', 'currency' => 'EUR', 'pricing_analytics' => ['data' => [['data_points' => [['phone_number' => '1', 'cost' => 1]]], ['data_points' => [['phone_number' => '2', 'cost' => 2]]]]]];
        $this->assertCount(2, MetaAbrechnung::datenpunkte($json));
        $this->assertSame([], MetaAbrechnung::datenpunkte(['id' => 'x']));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('(#200) Permission denied');
        MetaAbrechnung::datenpunkte(['error' => ['message' => '(#200) Permission denied', 'code' => 200]]);
    }

    /** Prod-Stand 18.11.2026: Dispo-Einstellungen Team 3 + HR aus der Konfig. */
    public function test_zuordnung_wie_prod(): void
    {
        $warnungen = [];
        $z = MetaAbrechnung::zuordnung(
            ['4921178177722' => 'HR'],
            ['+49 211 78177722', '+49 211 78177723'],
            [
                ['filial_nr' => 400, 'rufnummer' => '+49 211 78177729'],
                ['filial_nr' => 100, 'rufnummer' => '+49 211 78177725'],
                ['filial_nr' => 200, 'rufnummer' => '+49 211 78177723'],
            ],
            function (string $t) use (&$warnungen) { $warnungen[] = $t; },
        );

        $this->assertSame(['kostenstelle' => 100, 'bezeichnung' => 'DUS & ES', 'rufnummer' => '+49 211 78177725'], $z['4921178177725']);
        $this->assertSame(['kostenstelle' => 200, 'bezeichnung' => 'MGL', 'rufnummer' => '+49 211 78177723'], $z['4921178177723']);
        $this->assertSame(['kostenstelle' => 400, 'bezeichnung' => 'CGN', 'rufnummer' => '+49 211 78177729'], $z['4921178177729']);
        $this->assertSame(['kostenstelle' => null, 'bezeichnung' => 'HR', 'rufnummer' => '+49 211 78177722'], $z['4921178177722']);
        $this->assertSame([], $warnungen);
    }

    public function test_zuordnung_doppelte_filiale_kleinste_gewinnt_mit_warnung(): void
    {
        $warnungen = [];
        $z = MetaAbrechnung::zuordnung([], [], [
            ['filial_nr' => 300, 'rufnummer' => '+49 211 78177723'],
            ['filial_nr' => 200, 'rufnummer' => '+49 211 78177723'],
        ], function (string $t) use (&$warnungen) { $warnungen[] = $t; });

        $this->assertSame(200, $z['4921178177723']['kostenstelle']);
        $this->assertCount(1, $warnungen);
        $this->assertStringContainsString('(200, 300)', $warnungen[0]);
    }

    public function test_ziffern_vereinheitlicht_schreibweisen(): void
    {
        $this->assertSame('4921178177725', MetaAbrechnung::ziffern('+49 211 78177725'));
        $this->assertSame('4921178177725', MetaAbrechnung::ziffern('4921178177725'));
        $this->assertSame('', MetaAbrechnung::ziffern(null));
    }

    public function test_csv_zeilen_deutsch_formatiert(): void
    {
        $a = MetaAbrechnung::aufstellen($this->oktober(), $this->zuordnung(), 30.0);

        $csv = MetaAbrechnung::csvZeilen($a, '01.10.2026–31.10.2026', 'EUR', 30.0);

        $this->assertSame(['Kostenstelle', 'Filiale/Bezeichnung', 'Rufnummer', 'Zeitraum', 'Nachrichten berechnet', 'Nachrichten frei', 'Meta (EUR)', 'Aufschlag 30 % (EUR)', 'Summe (EUR)'], $csv[0]);
        $this->assertSame(['100', 'DUS & ES', '+49 211 78177725', '01.10.2026–31.10.2026', '1974', '82', '88,87', '26,66', '115,53'], $csv[1]);
        $this->assertSame(['', 'HR', '+49 211 78177722', '01.10.2026–31.10.2026', '2314', '1001', '104,38', '31,31', '135,69'], $csv[4]);
        $this->assertSame(['', 'Gesamt', '', '01.10.2026–31.10.2026', '6396', '1260', '289,20', '86,76', '375,96'], $csv[5]);
    }
}
