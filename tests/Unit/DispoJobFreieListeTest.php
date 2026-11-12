<?php

namespace Platform\Recruiting\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Services\Zas\Dispo\Jobs\DispoJobFreieListe;
use Platform\Recruiting\Services\Zas\Dispo\Jobs\DispoJobRecipientPlanner;

/** „Wer ist an dem Tag frei?“ je Schicht (Markus 12.11.2026). */
class DispoJobFreieListeTest extends TestCase
{
    private const SCHICHT = ['datum' => '2026-12-05', 'taetigkeit' => 'Service'];

    private function k(int $anchor, string $name, array $mehr = []): array
    {
        return $mehr + ['anchor' => $anchor, 'employee_id' => $anchor, 'name' => $name, 'first_name' => $name, 'phone' => '+4917' . $anchor,
            'block' => 1, 'portal_locked' => false, 'taetigkeiten' => ['Service'], 'status' => 'GO'];
    }

    private function daten(array $kandidaten, array $gebucht = [], array $einsaetze = []): array
    {
        return ['kandidaten' => $kandidaten, 'gebucht' => $gebucht, 'einsaetze' => [], 'reihenfolge' => ['einsaetze' => $einsaetze, 'letzte_anfrage' => []]];
    }

    private function namen(array $liste): array
    {
        return array_column($liste['zeilen'], 'name');
    }

    public function test_frei_ist_wer_die_taetigkeit_hat_und_an_dem_tag_nicht_gebucht_ist(): void
    {
        $liste = DispoJobFreieListe::fuerSchicht($this->daten([
            $this->k(1, 'Anna'),
            $this->k(2, 'Bernd'),
            $this->k(3, 'Cem', ['taetigkeiten' => ['Logistik']]),
            $this->k(4, 'Dana'),
        ], [2 => ['2026-12-05' => true], 4 => ['2026-12-06' => true]]), self::SCHICHT, []);

        $this->assertSame(['Anna', 'Dana'], $this->namen($liste), 'Buchung an einem ANDEREN Tag stoert nicht');
        $this->assertSame(1, $liste['ohne']['gebucht']);
        $this->assertSame(1, $liste['ohne']['taetigkeit']);
    }

    public function test_taetigkeit_vergleicht_ohne_gross_klein_und_leerzeichen(): void
    {
        $liste = DispoJobFreieListe::fuerSchicht($this->daten([$this->k(1, 'Anna', ['taetigkeiten' => [' service ']])]),
            ['datum' => '2026-12-05 00:00:00', 'taetigkeit' => 'Service'], []);

        $this->assertSame(['Anna'], $this->namen($liste));
    }

    /** Der Kern des Wunsches: angeschrieben ohne Antwort bleibt drin — die sollen nachgefasst werden. */
    public function test_angeschrieben_ohne_antwort_bleibt_in_der_liste(): void
    {
        $liste = DispoJobFreieListe::fuerSchicht($this->daten([$this->k(1, 'Anna'), $this->k(2, 'Bernd')]), self::SCHICHT,
            [2 => DispoJobFreieListe::STAND_ANGESCHRIEBEN]);

        $this->assertSame(['Anna', 'Bernd'], $this->namen($liste));
        $this->assertFalse($liste['zeilen'][0]['angeschrieben']);
        $this->assertTrue($liste['zeilen'][1]['angeschrieben']);
    }

    public function test_zusage_und_absage_in_dieser_schicht_fallen_heraus(): void
    {
        $liste = DispoJobFreieListe::fuerSchicht($this->daten([$this->k(1, 'Anna'), $this->k(2, 'Bernd'), $this->k(3, 'Cem')]), self::SCHICHT,
            [1 => DispoJobFreieListe::STAND_ZUGESAGT, 2 => DispoJobFreieListe::STAND_ABGESAGT]);

        $this->assertSame(['Cem'], $this->namen($liste));
        $this->assertSame(1, $liste['ohne']['zugesagt']);
        $this->assertSame(1, $liste['ohne']['abgesagt']);
    }

    /** „Einmal pro Tag“: wer am selben Tag eine ANDERE Schicht zugesagt hat, ist nicht frei — am Folgetag schon. */
    public function test_zusage_am_selben_tag_in_anderer_anfrage_zaehlt(): void
    {
        $selberTag = DispoJobRecipientPlanner::schichtSchluessel(77, '2026-12-05', '18:00', '23:00', 'Logistik');
        $andererTag = DispoJobRecipientPlanner::schichtSchluessel(77, '2026-12-06', '18:00', '23:00', 'Service');

        $liste = DispoJobFreieListe::fuerSchicht($this->daten([$this->k(1, 'Anna'), $this->k(2, 'Bernd')]), self::SCHICHT, [],
            [1 => [$selberTag], 2 => [$andererTag]]);

        $this->assertSame(['Bernd'], $this->namen($liste));
        $this->assertSame(1, $liste['ohne']['zugesagt']);
    }

    public function test_gesperrte_stehen_nicht_drin_ohne_nummer_schon(): void
    {
        $liste = DispoJobFreieListe::fuerSchicht($this->daten([
            $this->k(1, 'Anna', ['portal_locked' => true]),
            $this->k(2, 'Bernd', ['phone' => null]),
        ]), self::SCHICHT, []);

        $this->assertSame(['Bernd'], $this->namen($liste));
        $this->assertSame('', $liste['zeilen'][0]['phone']);
        $this->assertSame(1, $liste['ohne']['gesperrt']);
    }

    /** Filialen der Anfrage sind die Grundmenge — wer ausserhalb liegt, wird auch nicht als Ausschluss gezaehlt. */
    public function test_nur_filialen_der_anfrage(): void
    {
        $kandidaten = [$this->k(1, 'Anna', ['block' => 1]), $this->k(2, 'Bernd', ['block' => 2]), $this->k(3, 'Cem', ['block' => null])];

        $this->assertSame(['Anna'], $this->namen(DispoJobFreieListe::fuerSchicht($this->daten($kandidaten), self::SCHICHT, [], [], [1])));
        $this->assertSame(['Anna', 'Cem'], $this->namen(DispoJobFreieListe::fuerSchicht($this->daten($kandidaten), self::SCHICHT, [], [], [1, null])));
        $this->assertSame(['Anna', 'Bernd', 'Cem'], $this->namen(DispoJobFreieListe::fuerSchicht($this->daten($kandidaten), self::SCHICHT, [], [], null)));

        $gebucht = DispoJobFreieListe::fuerSchicht($this->daten($kandidaten, [2 => ['2026-12-05' => true]]), self::SCHICHT, [], [], [1]);
        $this->assertSame(0, $gebucht['ohne']['gebucht']);
    }

    public function test_wenigste_einsaetze_zuerst_dann_name(): void
    {
        $liste = DispoJobFreieListe::fuerSchicht($this->daten([$this->k(1, 'Zoe'), $this->k(2, 'Bernd'), $this->k(3, 'anna')], [], [1 => 0, 2 => 3, 3 => 3]),
            self::SCHICHT, []);

        $this->assertSame(['Zoe', 'anna', 'Bernd'], $this->namen($liste));
        $this->assertSame([0, 3, 3], array_column($liste['zeilen'], 'einsaetze'));
    }
}
