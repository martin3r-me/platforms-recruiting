<?php

namespace Platform\Recruiting\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Support\LohnMa;

final class LohnMaTest extends TestCase
{
    private const EG1 = 15.33;
    private const MINDEST = 13.90;

    public function test_fall_1_angleich_rg_lohn_ueber_eg1(): void
    {
        $l = LohnMa::rechne(self::EG1, self::MINDEST, true, 2.50, LohnMa::QUELLE_BEWERBUNG, '1', '6');

        $this->assertSame(LohnMa::ANGLEICH, $l->fall);
        $this->assertSame(15.33, $l->grundlohn);
        $this->assertSame(1.07, $l->zuschlag);
        $this->assertSame('15,33', $l->grundlohnText());
        $this->assertSame('1,07', $l->zuschlagText());
        $this->assertFalse($l->paarLeer());
        $this->assertNull($l->grundKurz());
        $this->assertStringContainsString('Angleich an RG 16,40 € (13,90 + 2,50) → 1,07 €', $l->begruendung);
    }

    /** Review Focus 1: RG-Lohn unter EG 1 → 0,00, nie negativ. */
    public function test_fall_1_rg_lohn_unter_eg1_ergibt_null_nicht_negativ(): void
    {
        $l = LohnMa::rechne(self::EG1, self::MINDEST, true, 0.60, LohnMa::QUELLE_BEWERBUNG, '3', '6');

        $this->assertSame(0.0, $l->zuschlag);
        $this->assertSame('0,00', $l->zuschlagText());
    }

    /**
     * Markus 22.10.2026, Variante A: MA-Gesamt = RG-Gesamt + 1,00 EUR, der
     * Zuschlag ist die Differenz zu EG 1 (nicht pauschal 1 EUR auf den Tarif).
     */
    public function test_rg_1_und_ma_6_ist_rg_plus_ein_euro(): void
    {
        $mit = LohnMa::rechne(self::EG1, self::MINDEST, true, 0.60, LohnMa::QUELLE_BEWERBUNG, '6', '1');        // RG 14,50 → MA 15,50
        $mitAngleich = LohnMa::rechne(self::EG1, self::MINDEST, true, 2.50, LohnMa::QUELLE_BEWERBUNG, '6', '3'); // RG 16,40 → MA 17,40

        $this->assertSame(0.17, $mit->zuschlag);
        $this->assertNull($mit->pruefen);
        $this->assertSame(2.07, $mitAngleich->zuschlag);
        $this->assertStringContainsString('RG + 1,00 € = 15,50 €', $mit->begruendung);
    }

    /** Markus' Beispiel: RG 20,00 (Klasse 1) → MA 21,00 (Klasse 6). */
    public function test_markus_beispiel_zwanzig_euro(): void
    {
        $l = LohnMa::rechne(self::EG1, self::MINDEST, true, round(20.00 - self::MINDEST, 2), LohnMa::QUELLE_BEWERBUNG, '6', '1');

        $this->assertSame(round(21.00 - self::EG1, 2), $l->zuschlag);
    }

    /** RG + 1 unter EG 1: Tarif ist Pflicht → Zuschlag 0, Akte zur Pruefung. */
    public function test_rg_plus_euro_unter_eg1_zahlt_tarif_und_meldet(): void
    {
        $l = LohnMa::rechne(self::EG1, self::MINDEST, true, 0.0, LohnMa::QUELLE_BEWERBUNG, '6', '1');        // RG 13,90 + 1 = 14,90 < EG 1

        $this->assertSame(0.0, $l->zuschlag);
        $this->assertNotNull($l->grundlohnText(), 'Tarif geht raus, keine leere Zelle');
        $this->assertSame('0,00', $l->zuschlagText());
        $this->assertStringContainsString('unter EG 1', (string) $l->pruefen);
    }

    /** MA 1-5 und RG 6: der Euro gehoert zu RG — Angleich wie bisher, zur Pruefung. */
    public function test_ma_haupt_und_rg_6_gleicht_an_und_meldet(): void
    {
        $l = LohnMa::rechne(self::EG1, self::MINDEST, true, 2.50, LohnMa::QUELLE_BEWERBUNG, '1', '6');

        $this->assertSame(1.07, $l->zuschlag);
        $this->assertStringContainsString('RG 6', (string) $l->pruefen);
    }

    public function test_euro_fuer_jede_rg_klasse_von_1_bis_5(): void
    {
        foreach (['1', '2', '3', '4', '5'] as $rg) {
            $l = LohnMa::rechne(self::EG1, self::MINDEST, true, 0.60, LohnMa::QUELLE_BEWERBUNG, '6', $rg);
            $this->assertSame(0.17, $l->zuschlag, 'RG ' . $rg);
        }
    }

    public function test_rg_6_und_ma_6_kein_euro(): void
    {
        $l = LohnMa::rechne(self::EG1, self::MINDEST, true, 0.60, LohnMa::QUELLE_BEWERBUNG, '6', '6');

        $this->assertSame(LohnMa::ANGLEICH, $l->fall);
        $this->assertSame(0.0, $l->zuschlag);
        $this->assertStringNotContainsString('Steuerklasse 6', $l->begruendung);
    }

    /** 20.10.2026: beide Seiten 1-5 = zwei Hauptarbeitgeber — beide Zellen leer. */
    public function test_beide_seiten_1_bis_5_ist_widerspruechlich(): void
    {
        foreach ([['1', '1'], ['2', '2'], ['1', '3'], ['5', '4']] as [$ma, $rg]) {
            $l = LohnMa::rechne(self::EG1, self::MINDEST, true, 2.50, LohnMa::QUELLE_BEWERBUNG, $ma, $rg);

            $this->assertSame(LohnMa::STEUERKLASSE_FEHLT, $l->fall, $ma . '/' . $rg);
            $this->assertNull($l->zuschlag);
            $this->assertNull($l->zuschlagText());
            $this->assertNull($l->grundlohnText());
            $this->assertTrue($l->paarLeer());
            $this->assertSame('Steuerklasse widersprüchlich (beide Hauptarbeitgeber)', $l->grundKurz());
            $this->assertStringContainsString('Steuerklasse widersprüchlich (beide Hauptarbeitgeber) — Grundlohn und Zuschlag leer — ZAS behält sein bisheriges Paar', $l->begruendung);
        }
    }

    /** 'P' (pauschal), 'S', '/' sind gesetzt, aber weder 1-5 noch 6: berechnet, kein Euro. */
    public function test_steuerklasse_p_ist_bekannt_ohne_euro(): void
    {
        $rgP = LohnMa::rechne(self::EG1, self::MINDEST, true, 2.50, LohnMa::QUELLE_BEWERBUNG, '6', 'P');
        $maP = LohnMa::rechne(self::EG1, self::MINDEST, true, 2.50, LohnMa::QUELLE_BEWERBUNG, 'P', '1');
        $klein = LohnMa::rechne(self::EG1, self::MINDEST, true, 2.50, LohnMa::QUELLE_BEWERBUNG, 'p', '1');

        foreach ([$rgP, $maP, $klein] as $l) {
            $this->assertSame(LohnMa::ANGLEICH, $l->fall);
            $this->assertSame(1.07, $l->zuschlag);
            $this->assertSame('15,33', $l->grundlohnText());
            $this->assertStringNotContainsString('Steuerklasse', $l->begruendung);
        }
        foreach (['S', '/'] as $sonst) {
            $this->assertSame(1.07, LohnMa::rechne(self::EG1, self::MINDEST, true, 2.50, LohnMa::QUELLE_BEWERBUNG, '6', $sonst)->zuschlag, $sonst);
            $this->assertSame(1.07, LohnMa::rechne(self::EG1, self::MINDEST, true, 2.50, LohnMa::QUELLE_BEWERBUNG, $sonst, '1')->zuschlag, $sonst);
        }
    }

    public function test_ma_steuerklasse_leer_ist_steuerklasse_fehlt(): void
    {
        foreach ([null, '', ' '] as $ma) {
            $l = LohnMa::rechne(self::EG1, self::MINDEST, true, 2.50, LohnMa::QUELLE_BEWERBUNG, $ma, '1');

            $this->assertSame(LohnMa::STEUERKLASSE_FEHLT, $l->fall);
            $this->assertNull($l->zuschlag);
            $this->assertNull($l->zuschlagText());
            // Pre-Merge-Review I2: Grundlohn intern EG 1, Exporttext leer.
            $this->assertSame(15.33, $l->grundlohn);
            $this->assertNull($l->grundlohnText());
            $this->assertTrue($l->paarLeer());
            $this->assertStringContainsString('Angleich an RG 16,40 €', $l->begruendung);
            $this->assertStringContainsString('Steuerklasse fehlt (MA) — Grundlohn und Zuschlag leer — ZAS behält sein bisheriges Paar', $l->begruendung);
        }
    }

    public function test_rg_steuerklasse_leer_ist_steuerklasse_fehlt(): void
    {
        $l = LohnMa::rechne(self::EG1, self::MINDEST, true, 2.50, LohnMa::QUELLE_BEWERBUNG, '6', null);

        $this->assertSame(LohnMa::STEUERKLASSE_FEHLT, $l->fall);
        $this->assertNull($l->zuschlag);
        $this->assertNull($l->grundlohnText());
        $this->assertSame('Steuerklasse fehlt (RG)', $l->grundKurz());
        $this->assertStringContainsString('Steuerklasse fehlt (RG)', $l->begruendung);
    }

    public function test_beide_steuerklassen_leer(): void
    {
        $l = LohnMa::rechne(self::EG1, self::MINDEST, true, 2.50, LohnMa::QUELLE_ZAS, null, null);

        $this->assertSame(LohnMa::STEUERKLASSE_FEHLT, $l->fall);
        $this->assertNull($l->zuschlag);
        $this->assertStringContainsString('Steuerklasse fehlt (MA und RG)', $l->begruendung);
    }

    /** Unbekannter RG-Zuschlag hat Vorrang vor der fehlenden Steuerklasse. */
    public function test_unbekannter_rg_zuschlag_schlaegt_leere_steuerklasse(): void
    {
        $l = LohnMa::rechne(self::EG1, self::MINDEST, true, null, null, null, null);

        $this->assertSame(LohnMa::UNBEKANNT, $l->fall);
        $this->assertNull($l->grundlohnText());
        $this->assertStringNotContainsString('Steuerklasse', $l->begruendung);
    }

    public function test_fall_2_unbekannt_liefert_leeren_zuschlag(): void
    {
        $l = LohnMa::rechne(self::EG1, self::MINDEST, true, null, null, '6', '1');

        $this->assertSame(LohnMa::UNBEKANNT, $l->fall);
        $this->assertNull($l->zuschlag);
        $this->assertNull($l->zuschlagText());
        $this->assertSame(15.33, $l->grundlohn, 'intern bleibt EG 1');
        $this->assertNull($l->grundlohnText(), 'Pre-Merge-Review I2: Grundlohn nur zusammen mit dem Zuschlag');
        $this->assertSame('RG-Zuschlag unbekannt', $l->grundKurz());
        $this->assertStringContainsString('RG-Zuschlag unbekannt', $l->begruendung);
        $this->assertStringContainsString('Grundlohn und Zuschlag leer — ZAS behält sein bisheriges Paar', $l->begruendung);
    }

    public function test_fall_1b_zas_rechnet_wie_angleich(): void
    {
        $l = LohnMa::rechne(self::EG1, self::MINDEST, true, 2.50, LohnMa::QUELLE_ZAS, '6', '1');

        $this->assertSame(LohnMa::ZAS, $l->fall);
        $this->assertSame(2.07, $l->zuschlag);
        $this->assertSame('15,33', $l->grundlohnText());
        $this->assertStringContainsString('laut ZAS', $l->begruendung);
    }

    public function test_fall_3_manuell_rechnet_wie_angleich(): void
    {
        $l = LohnMa::rechne(self::EG1, self::MINDEST, true, 2.50, LohnMa::QUELLE_MANUELL, '6', '1');

        $this->assertSame(LohnMa::MANUELL, $l->fall);
        $this->assertSame(2.07, $l->zuschlag);
        $this->assertSame('15,33', $l->grundlohnText());
        $this->assertStringContainsString('von HR eingetragen', $l->begruendung);
    }

    public function test_fall_4_nur_ma_ohne_angleich(): void
    {
        $ohne = LohnMa::rechne(self::EG1, self::MINDEST, false, null, null, '1');

        $this->assertSame(LohnMa::NUR_MA, $ohne->fall);
        $this->assertSame(0.0, $ohne->zuschlag);
        $this->assertSame('0,00', $ohne->zuschlagText(), 'bekannter Wert wird ausdruecklich geschickt');
        $this->assertSame('15,33', $ohne->grundlohnText());
        $this->assertSame('EG 1 15,33 € · nur MA, kein Angleich · Zuschlag 0,00 €', $ohne->begruendung);
    }

    /** "Nur MA immer EG 1 ohne Zuschlag" (Markus 16.10.): nie ein Euro, egal welche Klasse. */
    public function test_nur_ma_klasse_6_gibt_keinen_euro(): void
    {
        foreach (['6', '1', null] as $klasse) {
            $l = LohnMa::rechne(self::EG1, self::MINDEST, false, null, null, $klasse, '1');

            $this->assertSame(LohnMa::NUR_MA, $l->fall);
            $this->assertSame(0.0, $l->zuschlag);
            $this->assertStringNotContainsString('Steuerklasse', $l->begruendung);
        }
    }

    /** Ohne RG zaehlt ein mitgegebener Zuschlag nicht — es gibt nichts anzugleichen. */
    public function test_nur_ma_ignoriert_einen_rg_zuschlag(): void
    {
        $l = LohnMa::rechne(self::EG1, self::MINDEST, false, 2.50, LohnMa::QUELLE_BEWERBUNG, '1');

        $this->assertSame(LohnMa::NUR_MA, $l->fall);
        $this->assertSame(0.0, $l->zuschlag);
    }

    public function test_rundung_auf_zwei_stellen(): void
    {
        $l = LohnMa::rechne(15.33, 13.90, true, 1.436, LohnMa::QUELLE_BEWERBUNG, '1', '6');

        $this->assertSame(0.01, $l->zuschlag);
        $this->assertSame('0,01', $l->zuschlagText());
    }

    /** Ruling R4: eigener Grund, Zuschlag leer, kein Klasse-6-Euro. */
    public function test_unbekannt_weil_traegt_den_grund(): void
    {
        $l = LohnMa::unbekanntWeil(15.33, 'keine Personen-Zuordnung');

        $this->assertSame(LohnMa::UNBEKANNT, $l->fall);
        $this->assertNull($l->zuschlag);
        $this->assertNull($l->zuschlagText());
        $this->assertNull($l->grundlohnText());
        $this->assertSame('keine Personen-Zuordnung', $l->grundKurz());
        $this->assertSame('EG 1 15,33 € · keine Personen-Zuordnung · Grundlohn und Zuschlag leer — ZAS behält sein bisheriges Paar', $l->begruendung);
    }
}
