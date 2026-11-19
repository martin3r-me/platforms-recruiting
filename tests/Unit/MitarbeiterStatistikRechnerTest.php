<?php

namespace Platform\Recruiting\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Models\RecDispoAssignment;
use Platform\Recruiting\Services\Statistics\Mitarbeiter\GeleisteterEinsatz;
use Platform\Recruiting\Services\Statistics\Mitarbeiter\MitarbeiterStatistikRechner as R;

/** Mitarbeiter-Statistik (Markus 18./19.11.2026) — Regeln ohne DB. */
final class MitarbeiterStatistikRechnerTest extends TestCase
{
    private const HEUTE = '2026-11-19';
    private const VON = '2026-10-23';

    private static function ma(int $id, ?int $person = null, bool $aktiv = true, ?int $ks = 100, string $name = 'Muster, Max', array $phones = []): array
    {
        return ['id' => $id, 'person' => $person, 'aktiv' => $aktiv, 'kostenstelle' => $ks, 'name' => $name, 'pnr' => 'P' . $id, 'phones' => $phones];
    }

    private static function personen(array $ma, array $einsaetze, array $nummern = [], string $von = self::VON, string $bis = self::HEUTE): array
    {
        return R::personen($ma, $einsaetze, $nummern, self::HEUTE, $von, $bis);
    }

    public function test_zaehlende_einbuchung(): void
    {
        $this->assertTrue(GeleisteterEinsatz::zaehlt(RecDispoAssignment::STATUS_AUFTRAG, null, null, null));
        $this->assertTrue(GeleisteterEinsatz::zaehlt(RecDispoAssignment::STATUS_BEENDET, null, null, null));
        $this->assertFalse(GeleisteterEinsatz::zaehlt(RecDispoAssignment::STATUS_ANGEBOT, null, null, null));
        $this->assertFalse(GeleisteterEinsatz::zaehlt(RecDispoAssignment::STATUS_STORNO, null, null, null));
        $this->assertFalse(GeleisteterEinsatz::zaehlt(null, null, null, null));
        $this->assertFalse(GeleisteterEinsatz::zaehlt(RecDispoAssignment::STATUS_AUFTRAG, '2026-11-01', null, null), 'verschwunden');
        $this->assertFalse(GeleisteterEinsatz::zaehlt(RecDispoAssignment::STATUS_AUFTRAG, null, '2026-11-01', null), 'zur Löschung gemeldet');
        $this->assertFalse(GeleisteterEinsatz::zaehlt(RecDispoAssignment::STATUS_BEENDET, null, null, '2026-11-01'), 'abgesagt');
    }

    public function test_person_mit_rg_und_ma_zaehlt_einmal_und_termine_addieren_sich(): void
    {
        $p = self::personen(
            [self::ma(1, 7), self::ma(2, 7)],
            [['employee_id' => 1, 'datum' => '2026-11-01'], ['employee_id' => 2, 'datum' => '2026-11-02']],
        );
        $this->assertCount(1, $p);
        $this->assertSame(2, $p[0]['termine']);
        $this->assertSame('1-5', $p[0]['stufe']);
        $this->assertTrue($p[0]['produktiv']);
        $this->assertSame('P1, P2', $p[0]['pnr']);
    }

    public function test_nur_aktive_personen_inaktive_anstellung_zaehlt_aber_mit(): void
    {
        $p = self::personen(
            [self::ma(1, null, false), self::ma(2, 5, false), self::ma(3, 5, true)],
            [['employee_id' => 2, 'datum' => '2026-11-10']],
        );
        $this->assertCount(1, $p, 'Person 1 ist nur inaktiv');
        $this->assertSame(3, $p[0]['employee_id']);
        $this->assertTrue($p[0]['produktiv'], 'Einsatz über die inaktive Anstellung derselben Person zählt');
    }

    public function test_produktiv_60_tage_einschliesslich_stichtag_und_kuenftige_einsaetze(): void
    {
        $ma = [self::ma(1), self::ma(2), self::ma(3), self::ma(4)];
        $p = self::personen($ma, [
            ['employee_id' => 1, 'datum' => '2026-09-21'], // 59 Tage zurück = erster Tag des Fensters
            ['employee_id' => 2, 'datum' => '2026-09-20'], // 60 Tage zurück = draußen
            ['employee_id' => 3, 'datum' => '2026-12-05'], // disponiert, kommt noch
        ]);
        $byId = array_column($p, null, 'employee_id');
        $this->assertTrue($byId[1]['produktiv']);
        $this->assertFalse($byId[2]['produktiv']);
        $this->assertTrue($byId[3]['produktiv']);
        $this->assertSame(0, $byId[3]['termine'], 'künftige Einsätze sind keine geleisteten Termine');
        $this->assertSame('2026-12-05', $byId[3]['naechster_einsatz']);
        $this->assertFalse($byId[4]['produktiv']);
    }

    public function test_vergangener_stichtag_ignoriert_spaetere_einsaetze(): void
    {
        $p = self::personen([self::ma(1)], [['employee_id' => 1, 'datum' => '2026-11-15']], [], '2026-09-01', '2026-09-30');
        $this->assertFalse($p[0]['produktiv']);
        $this->assertSame(0, $p[0]['termine']);
    }

    public function test_stufen_sind_getrennte_gruppen(): void
    {
        $this->assertSame('0', R::stufe(0));
        $this->assertSame('1-5', R::stufe(5));
        $this->assertSame('6-10', R::stufe(6));
        $this->assertSame('6-10', R::stufe(10));
        $this->assertSame('11-20', R::stufe(11));
        $this->assertSame('11-20', R::stufe(20));
        $this->assertSame('21+', R::stufe(21));
    }

    public function test_termine_nur_im_zeitraum(): void
    {
        $p = self::personen([self::ma(1)], [
            ['employee_id' => 1, 'datum' => '2026-10-22'],
            ['employee_id' => 1, 'datum' => '2026-10-23'],
            ['employee_id' => 1, 'datum' => '2026-11-19'],
            ['employee_id' => 1, 'datum' => '2026-11-20'],
        ]);
        $this->assertSame(2, $p[0]['termine']);
    }

    public function test_rueckmeldung_ueber_normalisierte_nummer(): void
    {
        $p = self::personen(
            [self::ma(1, null, true, 100, 'A', ['0151 1234567']), self::ma(2, null, true, 100, 'B', ['+49 151 999'])],
            [],
            ['491511234567' => true],
        );
        $byId = array_column($p, null, 'employee_id');
        $this->assertTrue($byId[1]['rueckmeldung']);
        $this->assertFalse($byId[2]['rueckmeldung']);
    }

    public function test_kostenstelle_zwei_niederlassungen_zuletzt_gearbeitet_sonst_aeltere(): void
    {
        $zuletzt = self::personen(
            [self::ma(1, 9, true, 200), self::ma(2, 9, true, 100)],
            [['employee_id' => 1, 'datum' => '2026-10-01'], ['employee_id' => 2, 'datum' => '2026-11-01']],
        );
        $this->assertSame(100, $zuletzt[0]['kostenstelle']);

        $ohneEinsatz = self::personen([self::ma(5, 9, true, 400), self::ma(3, 9, true, 200)], []);
        $this->assertSame(200, $ohneEinsatz[0]['kostenstelle'], 'ältere Anstellung (kleinere id)');

        $kuenftigZaehltNicht = self::personen(
            [self::ma(1, 9, true, 200), self::ma(2, 9, true, 100)],
            [['employee_id' => 1, 'datum' => '2026-10-01'], ['employee_id' => 2, 'datum' => '2026-12-01']],
        );
        $this->assertSame(200, $kuenftigZaehltNicht[0]['kostenstelle']);
    }

    public function test_fehlende_kostenstelle_ueber_andere_anstellung_sonst_ohne(): void
    {
        $p = self::personen([self::ma(1, 4, true, null), self::ma(2, 4, false, 400)], []);
        $this->assertSame(400, $p[0]['kostenstelle']);

        $q = self::personen([self::ma(1, null, true, null)], []);
        $this->assertNull($q[0]['kostenstelle']);
    }

    public function test_je_kostenstelle_soll_abweichung_ohne_am_ende_und_gesamt(): void
    {
        $p = self::personen(
            [self::ma(1, null, true, 200), self::ma(2, null, true, 100), self::ma(3, null, true, 100), self::ma(4, null, true, null)],
            [['employee_id' => 2, 'datum' => '2026-11-01']],
            [],
        );
        $zeilen = R::jeKostenstelle($p, [100 => 3, 400 => 5]);
        $this->assertSame([100, 200, 400, null], array_column($zeilen, 'kostenstelle'));
        $this->assertSame([3, null, 5, null], array_column($zeilen, 'soll'));
        $this->assertSame([2, 1, 0, 1], array_column($zeilen, 'aktiv'));
        $this->assertSame([1, 0, 0, 0], array_column($zeilen, 'produktiv'));
        $this->assertSame([-2, null, -5, null], array_column($zeilen, 'abweichung'));
        $this->assertSame(1, $zeilen[0]['stufen']['0']);
        $this->assertSame(1, $zeilen[0]['stufen']['1-5']);
        $this->assertSame(1, $zeilen[0]['rueckmeldung_nein']);

        $g = R::gesamt($zeilen);
        $this->assertSame(4, $g['aktiv']);
        $this->assertSame(1, $g['produktiv']);
        $this->assertSame(8, $g['soll']);
        $this->assertSame(-7, $g['abweichung'], 'nur Kostenstellen mit Soll');
        $this->assertSame(3, $g['stufen']['0']);
    }

    public function test_passt_fuer_drilldown(): void
    {
        $p = ['kostenstelle' => 100, 'produktiv' => false, 'termine' => 0, 'stufe' => '0', 'rueckmeldung' => true];
        $this->assertTrue(R::passt($p, 'alle', 'aktiv'));
        $this->assertTrue(R::passt($p, '100', 'nicht_produktiv'));
        $this->assertFalse(R::passt($p, '200', 'aktiv'));
        $this->assertFalse(R::passt($p, 'ohne', 'aktiv'));
        $this->assertTrue(R::passt($p, 'alle', 'stufe:0'));
        $this->assertTrue(R::passt($p, 'alle', 'rueckmeldung:ja'));
        $this->assertFalse(R::passt($p, 'alle', 'rueckmeldung:nein'));
        $this->assertFalse(R::passt($p, 'alle', 'unbekannt'));
        $this->assertTrue(R::passt(['kostenstelle' => null] + $p, 'ohne', 'aktiv'));
    }

    public function test_drill_oeffnet_nur_bekannte_auswahlen(): void
    {
        $c = new \Platform\Recruiting\Livewire\Statistics\Mitarbeiter();
        foreach ([['alle', 'gibtsnicht'], ['alle', 'stufe:99'], ["1' or 1", 'aktiv'], ['', 'aktiv']] as [$ks, $m]) {
            $c->drill($ks, $m);
            $this->assertFalse($c->showDrill, $ks . ' / ' . $m);
        }
        $c->drill('100', 'stufe:6-10');
        $this->assertTrue($c->showDrill);
        $this->assertSame('100', $c->drillKostenstelle);
        $c->drill('ohne', 'rueckmeldung:nein');
        $this->assertSame('ohne', $c->drillKostenstelle);
    }

    public function test_empfaenger_nur_gueltige_adressen_ohne_doppelte(): void
    {
        $c = new \Platform\Recruiting\Livewire\Statistics\Mitarbeiter();
        $c->neueAdresse = 'kein-at-zeichen';
        $c->fuegeAdresseHinzu();
        $this->assertSame([], $c->empfaengerListe);
        $this->assertStringContainsString('keine gültige', $c->adressMeldung);

        $c->neueAdresse = ' Clara@RheinGedeck.de ';
        $c->fuegeAdresseHinzu();
        $c->neueAdresse = 'clara@rheingedeck.de';
        $c->fuegeAdresseHinzu();
        $this->assertSame(['clara@rheingedeck.de'], $c->empfaengerListe);
        $this->assertSame('', $c->adressMeldung);
        $this->assertSame('', $c->neueAdresse);

        $c->entferneEmpfaenger('clara@rheingedeck.de');
        $this->assertSame([], $c->empfaengerListe);
    }
}
